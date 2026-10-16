<?php

namespace Tests\Feature;

use App\Libraries\CanonicalJson;
use App\Libraries\Signing\Ed25519;
use Tests\Support\UcTestCase;

/**
 * §18 WorkSuite OS: signed ring manifests in the exact form the OS package service
 * verifies, APT hosting, ISO downloads, and pause / resume / pull / promote / emergency.
 */
final class OsUpdatesTest extends UcTestCase
{
    private array $ci;
    private array $rm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ci = $this->makeClient(['os.publish', 'release.view']);
        $this->rm = $this->makeUser(['release_manager'], 'rm-os@livezen.lk');
    }

    private function asCi(string $method, string $uri, array|string $body = []): array
    {
        $req = $this->withHeaders($this->ci);
        $res = is_string($body) ? $req->withBody($body)->call($method, $uri) : $req->withBodyFormat('json')->call($method, $uri, $body);
        $this->requestBody = '';
        $this->bodyFormat  = '';
        $this->headers     = [];

        return [$res->response()->getStatusCode(), $this->json($res)];
    }

    private function asRm(string $method, string $uri, array $body = []): array
    {
        $res = $this->withHeaders($this->actingAs($this->rm))->withBodyFormat('json')->call($method, $uri, $body);

        return [$res->response()->getStatusCode(), $this->json($res)];
    }

    private function manifest(string $ring = 'development', string $arch = 'amd64'): array
    {
        $res = $this->get("os/manifests/{$ring}/{$arch}.json");
        $res->assertStatus(200);

        return json_decode((string) $res->response()->getBody(), true);
    }

    private function releaseKeys(): array
    {
        return service('signingClient')->releasePublicKeys();
    }

    private function publishBuild(string $version, string $ring = 'development', string $type = 'feature', array $extra = []): void
    {
        $iso = random_bytes(2048);
        [$s, $j] = $this->asCi('PUT', "api/v1/os/artifacts/{$version}/amd64/worksuite-os-{$version}-amd64.iso", $iso);
        $this->assertSame(201, $s, json_encode($j));
        [$s] = $this->asCi('PUT', "api/v1/os/artifacts/{$version}/amd64/worksuite-os-{$version}-amd64.iso.sha256", hash('sha256', $iso) . "  worksuite-os-{$version}-amd64.iso\n");
        $this->assertSame(201, $s);
        [$s] = $this->asCi('PUT', "api/v1/os/artifacts/{$version}/amd64/worksuite-os-{$version}-amd64.sbom.spdx.json", '{"spdxVersion":"SPDX-2.3"}');
        $this->assertSame(201, $s);
        [$s, $j] = $this->asCi('POST', 'api/v1/os/releases', ['version' => $version, 'ring' => $ring, 'arch' => 'amd64', 'release_type' => $type, 'min_version' => '1.0.0'] + $extra);
        $this->assertSame(201, $s, json_encode($j));
        $this->assertSame(hash('sha256', $iso), $j['data'][0]['iso_sha256']);
    }

    public function testSeedAddsWorkSuiteOsProduct(): void
    {
        $app = db_connect()->table('applications')->where('slug', 'os')->get()->getRowArray();
        $this->assertSame('OS', $app['kind']);
        $arches = array_column(db_connect()->table('application_platforms')->where('application_id', $app['id'])->get()->getResultArray(), 'architecture');
        sort($arches);
        $this->assertSame(['amd64', 'arm64'], $arches);
    }

    public function testManifestIsSignedInTheOsFlatScheme(): void
    {
        $this->publishBuild('1.1.0', 'canary', 'security');
        $m = $this->manifest('development');
        $this->assertSame(['arch', 'min_version', 'release_id', 'ring', 'rollout_percent', 'security', 'signature', 'state', 'version'], (fn ($k) => [sort($k), $k][1])(array_keys($m)));
        $this->assertSame('development', $m['ring']);
        $this->assertTrue($m['security']);
        $this->assertArrayNotHasKey('key_id', $m);
        $this->assertMatchesRegularExpression('#^[A-Za-z0-9._-]+:[A-Za-z0-9+/]+=*$#', $m['signature']);
        $this->assertTrue(Ed25519::verifyFlat($m, $this->releaseKeys()));
        // Tampering breaks it.
        $this->assertFalse(Ed25519::verifyFlat(['version' => '9.9.9'] + $m, $this->releaseKeys()));
        // Same document via the WorkSuite server path.
        $res = $this->get('desktop/v1/releases/manifest?platform=os&ring=development&arch=amd64');
        $this->assertSame($m, json_decode((string) $res->response()->getBody(), true));
    }

    /** Verifies with the exact algorithm of Worksuite-OS services/package-service/src/updates.ts (node:crypto). */
    public function testManifestVerifiesWithNodeCryptoLikeTheOs(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node not installed');
        }
        $this->publishBuild('1.2.0', 'canary', 'emergency', ['deadline' => '2026-12-01T00:00:00Z', 'entitlement' => 'business']);
        $m    = $this->manifest('development');
        $keys = json_decode((string) $this->get('api/v1/os/keys')->response()->getBody(), true)['keys'];
        $dir  = sys_get_temp_dir() . '/os-node-' . getmypid();
        @mkdir($dir);
        file_put_contents("$dir/m.json", json_encode($m));
        file_put_contents("$dir/k.json", json_encode($keys));
        file_put_contents("$dir/v.mjs", <<<'JS'
            import { createPublicKey, verify } from 'node:crypto';
            import { readFileSync } from 'node:fs';
            const canonical = (v) => v === null || typeof v !== 'object' ? JSON.stringify(v) : Array.isArray(v) ? `[${v.map(canonical).join(',')}]`
              : `{${Object.keys(v).sort().filter((k) => v[k] !== undefined).map((k) => `${JSON.stringify(k)}:${canonical(v[k])}`).join(',')}}`;
            const m = JSON.parse(readFileSync(process.argv[2], 'utf8'));
            const keys = Object.fromEntries(JSON.parse(readFileSync(process.argv[3], 'utf8')).map((k) => [k.key_id, createPublicKey(k.public_pem)]));
            const [id, value] = m.signature.split(':', 2);
            const { signature, ...rest } = m;
            process.stdout.write(verify(null, Buffer.from(canonical(rest)), keys[id], Buffer.from(value, 'base64')) ? 'OK' : 'BAD');
            JS);
        $out = shell_exec('node ' . escapeshellarg("$dir/v.mjs") . ' ' . escapeshellarg("$dir/m.json") . ' ' . escapeshellarg("$dir/k.json") . ' 2>&1');
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
        $this->assertSame('OK', $out);
        $this->assertTrue($m['critical'] && $m['mandatory'] && $m['security']);
        $this->assertSame('2026-12-01T00:00:00Z', $m['deadline']);
        $this->assertSame(100, $m['rollout_percent']);
    }

    public function testCiCannotPublishStableOrManageReleases(): void
    {
        [$s] = $this->asCi('POST', 'api/v1/os/releases', ['version' => '1.0.1', 'ring' => 'stable']);
        $this->assertSame(403, $s);
        [$s] = $this->asCi('POST', 'api/v1/admin/os/emergency', ['version' => '1.0.1']);
        $this->assertSame(401, $s === 403 ? 401 : $s); // staff-only
        $this->get('api/v1/os/releases')->assertStatus(200);
        $this->withBodyFormat('json')->post('api/v1/os/releases', ['version' => '1.0.1'])->assertStatus(401);
    }

    public function testPausePullPromoteAndRollback(): void
    {
        $this->publishBuild('1.1.0', 'pilot');
        $this->publishBuild('1.2.0', 'pilot');
        $this->assertSame('1.2.0', $this->manifest('pilot')['version']);

        [$s] = $this->asRm('POST', 'api/v1/admin/os/releases/1.2.0/beta/pause', ['reason' => 'crash spike']);
        $this->assertSame(200, $s);
        $m = $this->manifest('pilot');
        $this->assertSame(['1.2.0', 'paused'], [$m['version'], $m['state']]);
        $this->assertTrue(Ed25519::verifyFlat($m, $this->releaseKeys()));

        $this->asRm('POST', 'api/v1/admin/os/releases/1.2.0/pilot/resume');
        $this->assertSame('active', $this->manifest('pilot')['state']);

        [$s] = $this->asRm('POST', 'api/v1/admin/os/releases/1.2.0/pilot/rollout', ['rollout_percent' => 25]);
        $this->assertSame(200, $s);
        $this->assertSame(25, $this->manifest('pilot')['rollout_percent']);

        [$s, $j] = $this->asRm('POST', 'api/v1/admin/os/releases/1.2.0/pilot/promote', ['to' => 'stable']);
        $this->assertSame(200, $s, json_encode($j));
        $st = $this->manifest('stable');
        $this->assertSame(['1.2.0', 'stable', 10], [$st['version'], $st['ring'], $st['rollout_percent']]);

        // Pull: previous release is served again with a signed rollback directive.
        $this->asRm('POST', 'api/v1/admin/os/releases/1.2.0/pilot/pull', ['reason' => 'data loss']);
        $m = $this->manifest('pilot');
        $this->assertSame('1.1.0', $m['version']);
        $this->assertSame('1.2.0', $m['rollback_directive']['from_version']);
        $this->assertTrue(Ed25519::verifyFlat($m, $this->releaseKeys()));
        // The download page no longer lists the pulled build on pilot.
        $pub = json_decode((string) $this->get('api/v1/os/releases')->response()->getBody(), true)['data'];
        $pilot = array_values(array_filter($pub, static fn ($r) => $r['ring'] === 'pilot'))[0];
        $this->assertSame('1.1.0', $pilot['version']);
    }

    public function testEmergencyPublishesEveryRing(): void
    {
        $this->publishBuild('1.3.1', 'development');
        [$s, $j] = $this->asRm('POST', 'api/v1/admin/os/emergency', ['version' => '1.3.1', 'arches' => ['amd64'], 'min_version' => '1.3.1']);
        $this->assertSame(201, $s, json_encode($j));
        foreach (['development', 'pilot', 'stable'] as $ring) {
            $m = $this->manifest($ring);
            $this->assertTrue($m['critical'] && $m['mandatory'] && $m['security']);
        }
    }

    public function testIsoDownloadAndChecksum(): void
    {
        $this->publishBuild('1.1.0');
        $pub = json_decode((string) $this->get('api/v1/os/releases')->response()->getBody(), true)['data'][0];
        $this->assertSame('worksuite-os-1.1.0-amd64.iso', $pub['iso']['file_name']);
        $this->assertSame(64, strlen($pub['iso']['sha256']));
        $this->assertStringEndsWith('.sbom.spdx.json', $pub['sbom']['file_name']);
        $this->assertSame('trixie-dev', $pub['apt']['suite']);
        $res = $this->get('os/download/1.1.0/amd64/worksuite-os-1.1.0-amd64.iso');
        $this->assertSame(302, $res->response()->getStatusCode());
        $this->assertStringContainsString('_dev-cdn/os/releases/1.1.0/amd64/', $res->response()->getHeaderLine('Location'));
        $this->get('os/download/1.1.0/amd64/missing.iso')->assertStatus(404);
    }

    public function testAptImportServesSignedSuiteAndRejectsUnsigned(): void
    {
        $dir = sys_get_temp_dir() . '/os-apt-src-' . getmypid();
        @mkdir("$dir/dists/trixie-stable/main/binary-amd64", 0777, true);
        @mkdir("$dir/pool/main/w/worksuite-shell", 0777, true);
        @mkdir("$dir/lists", 0777, true);
        file_put_contents("$dir/dists/trixie-stable/main/binary-amd64/Packages", "Package: worksuite-shell\n");
        file_put_contents("$dir/pool/main/w/worksuite-shell/worksuite-shell_1.0_amd64.deb", '!<arch>');
        file_put_contents("$dir/lists/trixie-stable", 'private bookkeeping');
        $tar = function () use ($dir) {
            $p = sys_get_temp_dir() . '/os-apt-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.tar';
            $ph = new \PharData($p);
            $ph->buildFromDirectory($dir);
            $ph->compress(\Phar::GZ);
            $gz = (string) file_get_contents($p . '.gz');
            unlink($p);
            unlink($p . '.gz');

            return $gz;
        };
        [$s] = $this->asCi('PUT', 'api/v1/os/apt', $tar());
        $this->assertSame(422, $s === 400 ? 422 : $s, 'unsigned suite must be refused');

        file_put_contents("$dir/dists/trixie-stable/InRelease", "-----BEGIN PGP SIGNED MESSAGE-----\nSuite: trixie-stable\n");
        [$s, $j] = $this->asCi('PUT', 'api/v1/os/apt', $tar());
        $this->assertSame(200, $s, json_encode($j));
        $this->assertSame(['trixie-stable'], $j['suites']);
        $res = $this->get('os/apt/dists/trixie-stable/InRelease');
        $res->assertStatus(200);
        $this->assertStringContainsString('Suite: trixie-stable', (string) $res->response()->getBody());
        $deb = $this->get('os/apt/pool/main/w/worksuite-shell/worksuite-shell_1.0_amd64.deb');
        $this->assertSame(302, $deb->response()->getStatusCode());
        $this->get('os/apt/lists/trixie-stable')->assertStatus(404);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testCanonicalJsonMatchesJsForManifestShapes(): void
    {
        $doc = ['b' => 1, 'a' => ['z' => true, 'y' => null], 'url' => 'https://x/y', 'name' => 'Sri Lankā'];
        $this->assertSame('{"a":{"y":null,"z":true},"b":1,"name":"Sri Lankā","url":"https://x/y"}', CanonicalJson::encode($doc));
    }
}

<?php

namespace Tests\Feature;

use App\Libraries\Signing\Ed25519;
use App\Services\KeyRegistry;
use Tests\Support\Fixtures;
use Tests\Support\UcTestCase;

/**
 * End-to-end: create → upload (tus) → process → changelog → QA → approve →
 * publish (signed) → update check → resolver → pause/withdraw.
 */
final class ReleaseLifecycleTest extends UcTestCase
{
    private array $dev;
    private array $qa;
    private array $rm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dev = $this->makeUser(['developer'], 'dev@livezen.lk');
        $this->qa  = $this->makeUser(['qa'], 'qa@livezen.lk');
        $this->rm  = $this->makeUser(['release_manager'], 'rm@livezen.lk');
    }

    private function as(array $user): self
    {
        $h = $this->actingAs($user);

        return $this->withHeaders($h)->withBodyFormat('json');
    }

    private function api(array $user, string $method, string $uri, array $body = [])
    {
        $res = $this->as($user)->call($method, $uri, $body);

        return [$res->response()->getStatusCode(), $this->json($res), $res];
    }

    /** Register + tus-upload a file; returns the package id. */
    private function upload(array $user, string $releaseId, string $platform, string $arch, string $type, string $name, string $bytes, ?string $reason = null, ?string $edition = null): string
    {
        [$s, $j] = $this->api($user, 'POST', "api/v1/admin/releases/{$releaseId}/packages", array_filter(['platform' => $platform, 'architecture' => $arch, 'package_type' => $type, 'file_name' => $name, 'size' => strlen($bytes), 'reason' => $reason, 'edition' => $edition]));
        $this->assertSame(201, $s, json_encode($j));
        $uploadId = $j['upload']['id'];
        $csrf     = $this->actingAs($user)['X-CSRF-Token'];
        $this->bodyFormat  = '';
        $this->requestBody = '';
        $res = $this->withHeaders(['X-CSRF-Token' => $csrf, 'Tus-Resumable' => '1.0.0', 'Upload-Length' => (string) strlen($bytes), 'Upload-Metadata' => 'upload_id ' . base64_encode($uploadId)])->post('api/v1/uploads/tus');
        $this->assertSame(201, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        // Two chunks to exercise resume offsets.
        $half = intdiv(strlen($bytes), 2);
        foreach ([[0, substr($bytes, 0, $half)], [$half, substr($bytes, $half)]] as [$off, $chunk]) {
            $res = $this->withHeaders(['X-CSRF-Token' => $csrf, 'Tus-Resumable' => '1.0.0', 'Upload-Offset' => (string) $off, 'Content-Type' => 'application/offset+octet-stream'])->withBody($chunk)->patch("api/v1/uploads/tus/{$uploadId}");
            $this->assertSame(204, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        }
        $this->requestBody = '';

        return $j['package']['id'];
    }

    private function createReadyRelease(string $version, int $build, string $type = 'RECOMMENDED'): string
    {
        [$s, $j] = $this->api($this->dev, 'POST', 'api/v1/admin/releases', ['app_id' => 'desktop', 'version' => $version, 'build' => $build, 'channel' => 'STABLE', 'release_type' => $type, 'minimum_supported_version' => '2.5.0']);
        $this->assertSame(201, $s, json_encode($j));
        $id = $j['id'];
        $this->upload($this->dev, $id, 'windows', 'x64', 'exe', "WorkSuite-{$version}-x64.exe", Fixtures::pe('x64'));
        $this->upload($this->dev, $id, 'macos', 'arm64', 'dmg', "WorkSuite-{$version}-arm64.dmg", Fixtures::dmg());
        $this->upload($this->dev, $id, 'macos', 'x64', 'dmg', "WorkSuite-{$version}-x64.dmg", Fixtures::dmg());
        $this->upload($this->dev, $id, 'linux', 'x64', 'appimage', "WorkSuite-{$version}.AppImage", Fixtures::appImage('x64'));
        [$s, $j] = $this->api($this->dev, 'PUT', "api/v1/admin/releases/{$id}/changelog", ['summary' => "WorkSuite Desktop {$version}", 'highlights' => ['Offline accounts'], 'markdown' => "## New Features\n- Desktop offline account support\n\n## Bug Fixes\n- Fixed Meet fullscreen issue"]);
        $this->assertSame(200, $s, json_encode($j));
        $this->assertSame(['Desktop offline account support'], $j['sections']['new_features']);

        return $id;
    }

    private function publish(string $id): array
    {
        [$s, $j] = $this->api($this->dev, 'POST', "api/v1/admin/releases/{$id}/submit-test");
        $this->assertSame(200, $s, json_encode($j));
        $this->assertSame('READY_FOR_TEST', $j['status']);
        [$s, $j] = $this->api($this->qa, 'POST', "api/v1/admin/releases/{$id}/qa", ['decision' => 'PASS', 'comment' => 'All platforms pass']);
        $this->assertSame(200, $s, json_encode($j));
        [$s, $j] = $this->api($this->rm, 'POST', "api/v1/admin/releases/{$id}/approve", ['comment' => 'Ship it']);
        $this->assertSame('APPROVED', $j['status'], json_encode($j));
        [$s, $j] = $this->api($this->rm, 'POST', "api/v1/admin/releases/{$id}/publish", ['reason' => 'Release day']);
        $this->assertSame(200, $s, json_encode($j));
        $this->assertSame('PUBLISHED', $j['status']);

        return $j;
    }

    public function testFullLifecycle(): void
    {
        $id = $this->createReadyRelease('2.7.0', 27001);

        // Packages processed: SHA-256, metadata, pipeline steps recorded.
        [, $rel] = $this->api($this->rm, 'GET', "api/v1/admin/releases/{$id}");
        $this->assertCount(4, $rel['packages']);
        foreach ($rel['packages'] as $p) {
            $this->assertSame('READY', $p['status'], json_encode($p));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $p['active_revision']['sha256']);
            $this->assertSame('done', array_column($p['active_revision']['pipeline'], 'status', 'step')['ready']);
        }
        $this->assertSame('DRAFT', $rel['status']);

        // Publish is refused before approval (checklist + transition).
        [$s, $j] = $this->api($this->rm, 'POST', "api/v1/admin/releases/{$id}/publish");
        $this->assertSame(409, $s);
        $this->assertSame('INVALID_TRANSITION', $j['error']['code']);

        $this->publish($id);

        // publish-summary reports the real step-up requirement (dev user without MFA → none).
        $sid = $this->createReadyRelease('2.7.1', 27101);
        [, $sum] = $this->api($this->rm, 'GET', "api/v1/admin/releases/{$sid}/publish-summary");
        $this->assertFalse($sum['requires_mfa']);
        config('UpdateCenter')->environmentName = 'PRODUCTION';
        $this->assertTrue((new \App\Services\ReleaseService(service('authContext')))->stepUpNeeded() || service('authContext')->hasRecentMfa());
        config('UpdateCenter')->environmentName = 'DEV';

        // Signed manifests exist and verify against the release key.
        [, $man] = $this->api($this->rm, 'GET', "api/v1/admin/releases/{$id}/manifest");
        $this->assertTrue($man['release']['signature_valid']);
        $this->assertTrue(Ed25519::verifyDocument($man['release']['json'], KeyRegistry::releaseKeys()));
        $this->assertSame('2.7.0', $man['channel']['json']['current']['version']);
        $this->assertArrayNotHasKey('download_url', $man['release']['json']['packages'][0]);

        // Public channel manifest (byte-for-byte signed JSON).
        $res = $this->get('api/v1/apps/lk.worksuite.desktop/channels/stable/manifest');
        $res->assertStatus(200);
        $doc = json_decode((string) $res->response()->getBody(), true);
        $this->assertTrue(Ed25519::verifyDocument($doc, KeyRegistry::releaseKeys()));

        // Desktop update check: 2.6.5 → recommended (release type), staged 5 % → device must be in cohort.
        db_connect()->table('release_rollouts')->update(['percentage' => 100, 'status' => 'DONE']);
        $res = $this->get('api/v1/desktop/update?app_id=lk.worksuite.desktop&current_version=2.6.5&build=26505&platform=windows&architecture=x64&install_type=exe&device_id=hmac:abc');
        $up  = $this->json($res);
        $this->assertSame('UPDATE_RECOMMENDED', $up['status'], json_encode($up));
        $this->assertSame('2.7.0', $up['latest_version']);
        $this->assertSame('exe', $up['package']['type']);
        $this->assertSame('absent', $up['tenant_policy']);
        $pub = service('responseKeys')->publicKey(config('UpdateCenter')->responseKeyId);
        $this->assertTrue(Ed25519::verifyDocument($up, [config('UpdateCenter')->responseKeyId => $pub]));

        // Below the release minimum → UPDATE_REQUIRED; current → CURRENT.
        $this->assertSame('UPDATE_REQUIRED', $this->json($this->get('api/v1/desktop/update?app_id=lk.worksuite.desktop&current_version=2.4.0&platform=windows&architecture=x64&device_id=hmac:abc'))['status']);
        $this->assertSame('CURRENT', $this->json($this->get('api/v1/desktop/update?app_id=lk.worksuite.desktop&current_version=2.7.0&platform=macos&architecture=arm64&device_id=hmac:abc'))['status']);

        // Grant redemption gives a fresh CDN URL.
        $g = $this->withBodyFormat('json')->post('api/v1/downloads/grant', ['grant' => $up['package']['download_grant']]);
        $g->assertStatus(200);
        $this->assertStringContainsString('/_dev-cdn/desktop/stable/2.7.0/windows/x64/', $this->json($g)['download_url']);

        // Public resolver → 302 to signed CDN URL; event recorded.
        $r = $this->get('download/desktop/windows/x64');
        $r->assertStatus(302);
        $this->assertStringContainsString('sig=', $r->response()->getHeaderLine('Location'));
        $this->seeInDatabase('download_events', ['platform' => 'windows', 'redirect_ok' => 1]);
        $this->get('download/desktop/2.7.0/linux/x64/appimage')->assertStatus(302);

        // Public API shapes.
        $app = $this->json($this->get('api/v1/apps/desktop'));
        $this->assertSame('2.7.0', $app['latest_stable']['version']);
        $this->assertSame('2.7.0', $app['latest']['version']);
        $this->assertNotEmpty($app['latest']['packages']);
        $this->assertStringContainsString('checksums.txt', $app['latest']['checksums_url']);
        $cs = $this->get('apps/desktop/releases/2.7.0/checksums.txt');
        $this->assertStringContainsString('WorkSuite-2.7.0-x64.exe', (string) $cs->response()->getBody());
        $this->assertStringContainsString('<rss', (string) $this->get('changelog/feed')->response()->getBody());

        // Withdraw: type-to-confirm, then update API reports RELEASE_WITHDRAWN and downloads 410.
        [$s] = $this->api($this->rm, 'POST', "api/v1/admin/releases/{$id}/withdraw", ['reason' => 'Crash on start', 'confirm_version' => '2.7']);
        $this->assertSame(422, $s);
        [$s, $j] = $this->api($this->rm, 'POST', "api/v1/admin/releases/{$id}/withdraw", ['reason' => 'Crash on start', 'confirm_version' => '2.7.0']);
        $this->assertSame('WITHDRAWN', $j['status'], json_encode($j));
        $this->assertSame('RELEASE_WITHDRAWN', $this->json($this->get('api/v1/desktop/update?app_id=lk.worksuite.desktop&current_version=2.7.0&platform=windows&architecture=x64'))['status']);
        $this->assertSame(410, $this->get('download/desktop/2.7.0/windows/x64')->response()->getStatusCode());
        $this->assertSame('WITHDRAWN', $this->json($this->get("api/v1/releases/{$id}/state"))['status']);

        // Audit chain intact and records the lifecycle.
        $this->assertTrue(service('audit')->verifyChain()['ok']);
        $this->seeInDatabase('audit_logs', ['action' => 'release.published']);
        $this->seeInDatabase('audit_logs', ['action' => 'release.withdrawn']);
    }

    public function testSeparationOfDuties(): void
    {
        $id = $this->createReadyRelease('2.7.0', 27001);
        $this->api($this->dev, 'POST', "api/v1/admin/releases/{$id}/submit-test");
        // A super admin who uploaded cannot QA-approve their own packages.
        $sa  = $this->makeUser(['super_admin'], 'sa@livezen.lk');
        $pkg = $this->api($this->rm, 'GET', "api/v1/admin/releases/{$id}")[1]['packages'][0];
        db_connect()->table('release_package_revisions')->where('public_id', $pkg['active_revision']['id'])->update(['uploaded_by' => $sa['id']]);
        [$s, $j] = $this->api($sa, 'POST', "api/v1/admin/releases/{$id}/qa", ['decision' => 'PASS', 'comment' => 'self']);
        $this->assertSame(403, $s);
        $this->assertSame('SOD_VIOLATION', $j['error']['code']);
        // QA cannot also give the Release Manager approval.
        $this->api($this->qa, 'POST', "api/v1/admin/releases/{$id}/qa", ['decision' => 'PASS', 'comment' => 'ok']);
        [$s] = $this->api($this->qa, 'POST', "api/v1/admin/releases/{$id}/approve");
        $this->assertSame(403, $s); // lacks release.approve permission
    }

    public function testArchMismatchIsRejected(): void
    {
        [, $j] = $this->api($this->dev, 'POST', 'api/v1/admin/releases', ['app_id' => 'desktop', 'version' => '2.8.0', 'build' => 28000, 'channel' => 'STABLE']);
        $pkg    = $this->upload($this->dev, $j['id'], 'windows', 'x64', 'exe', 'WorkSuite-arm.exe', Fixtures::pe('arm64'));
        [, $p]  = $this->api($this->dev, 'GET', "api/v1/admin/releases/{$j['id']}/packages/{$pkg}");
        $this->assertSame('REJECTED', $p['revisions'][0]['status']);
        $this->assertSame('METADATA_MISMATCH', $p['revisions'][0]['reject_code']);
    }

    public function testBuildMustIncreaseAndVersionUnique(): void
    {
        $this->api($this->dev, 'POST', 'api/v1/admin/releases', ['app_id' => 'desktop', 'version' => '2.7.0', 'build' => 27001]);
        [$s, $j] = $this->api($this->dev, 'POST', 'api/v1/admin/releases', ['app_id' => 'desktop', 'version' => '2.7.0', 'build' => 27002]);
        $this->assertSame(409, $s);
        $this->assertSame('RELEASE_EXISTS', $j['error']['code']);
        [$s, $j] = $this->api($this->dev, 'POST', 'api/v1/admin/releases', ['app_id' => 'desktop', 'version' => '2.7.1', 'build' => 27000]);
        $this->assertSame(422, $s);
        $this->assertSame('BUILD_NOT_INCREASING', $j['error']['code']);
        [$s, $j] = $this->api($this->dev, 'POST', 'api/v1/admin/releases', ['app_id' => 'desktop', 'version' => 'banana', 'build' => 1]);
        $this->assertSame(422, $s);
    }

    public function testSupersedeAndPauseFallback(): void
    {
        $a = $this->createReadyRelease('2.6.5', 26505);
        $this->publish($a);
        $b = $this->createReadyRelease('2.7.0', 27001);
        $this->publish($b);
        $this->assertSame('SUPERSEDED', $this->api($this->rm, 'GET', "api/v1/admin/releases/{$a}")[1]['status']);

        // Pause the newest → stable link and channel manifest fall back to 2.6.5.
        [$s, $j] = $this->api($this->rm, 'POST', "api/v1/admin/releases/{$b}/pause", ['reason' => 'Investigating']);
        $this->assertSame('PAUSED', $j['status'], json_encode($j));
        $loc = $this->get('download/desktop/windows/x64')->response()->getHeaderLine('Location');
        $this->assertStringContainsString('/2.6.5/', $loc);
        $man = json_decode((string) $this->get('api/v1/apps/desktop/channels/stable/manifest')->response()->getBody(), true);
        $this->assertSame('2.6.5', $man['current']['version']);
        $this->api($this->rm, 'POST', "api/v1/admin/releases/{$b}/resume");
        $this->assertStringContainsString('/2.7.0/', $this->get('download/desktop/windows/x64')->response()->getHeaderLine('Location'));
    }

    public function testStagedRolloutCohorts(): void
    {
        $id = $this->createReadyRelease('2.7.0', 27001);
        $this->publish($id); // stage 1 = 5 %
        $in = $out = 0;
        for ($i = 0; $i < 400; $i++) {
            $st = $this->json($this->get("api/v1/desktop/update?app_id=lk.worksuite.desktop&current_version=2.6.0&platform=windows&architecture=x64&device_id=hmac:d{$i}"))['rollout']['in_cohort'];
            $st ? $in++ : $out++;
        }
        $this->assertGreaterThan(5, $in);
        $this->assertLessThan(60, $in);
        // Deterministic: same device → same answer.
        $a = $this->json($this->get('api/v1/desktop/update?app_id=lk.worksuite.desktop&current_version=2.6.0&platform=windows&architecture=x64&device_id=hmac:stable'))['rollout']['in_cohort'];
        cache()->clean();
        $b = $this->json($this->get('api/v1/desktop/update?app_id=lk.worksuite.desktop&current_version=2.6.0&platform=windows&architecture=x64&device_id=hmac:stable'))['rollout']['in_cohort'];
        $this->assertSame($a, $b);
    }

    public function testRolloutAutoPausesOnFailureRateInPercent(): void
    {
        $id = $this->createReadyRelease('2.7.0', 27001);
        $this->publish($id);
        [, $ro] = $this->api($this->rm, 'GET', "api/v1/admin/releases/{$id}/rollout");
        $this->assertSame(97.0, $ro['thresholds']['min_success_rate']);
        $this->assertSame(5.0, $ro['thresholds']['max_failure_rate']);

        // Percent thresholds round-trip; out-of-range values are rejected.
        [$s] = $this->api($this->rm, 'PUT', "api/v1/admin/releases/{$id}/rollout", ['thresholds' => ['max_failure_rate' => 150]]);
        $this->assertSame(422, $s);
        [$s, $ro] = $this->api($this->rm, 'PUT', "api/v1/admin/releases/{$id}/rollout", ['thresholds' => ['max_failure_rate' => 10]]);
        $this->assertSame(10.0, $ro['thresholds']['max_failure_rate'], json_encode($ro));

        // 30 installs, 6 failed = 20 % > 10 % → auto-pause.
        $rel = \App\Services\Catalog::release($id);
        foreach ([['install_succeeded', 24], ['install_failed', 6]] as [$type, $n]) {
            db_connect()->table('update_stats_hourly')->insert(['hour' => gmdate('Y-m-d H:00:00'), 'release_id' => $rel['id'], 'platform' => 'windows', 'type' => $type, 'error_code' => '', 'count' => $n]);
        }
        [, $dev] = $this->api($this->rm, 'GET', "api/v1/admin/releases/{$id}/devices");
        $this->assertSame(80.0, (float) $dev['update_success_rate']);
        $actions = (new \App\Services\RolloutService())->evaluateAll();
        $this->assertStringContainsString('Install failure rate 20.0% above 10.0%', implode(' ', $actions));
        [, $ro] = $this->api($this->rm, 'GET', "api/v1/admin/releases/{$id}/rollout");
        $this->assertSame('PAUSED', $ro['status']);
    }
}

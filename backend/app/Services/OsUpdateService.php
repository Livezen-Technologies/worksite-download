<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\Signing\SigningRefusedException;
use App\Libraries\Storage\ReleaseStorageProviderInterface;
use App\Libraries\Storage\StorageFactory;
use App\Libraries\Ulid;

/**
 * WorkSuite OS updates (§18). Serves the per-ring, per-arch release manifest the OS
 * package service verifies (services/package-service/src/updates.ts in Worksuite-OS),
 * hosts the signed APT repository built by tools/repo/publish.sh and the ISO/SBOM downloads,
 * and maps pause / resume / pull / promote / emergency onto signed manifest changes.
 *
 * Manifest format (canonical JSON, sorted keys, signed by the release key through the
 * signing service, kind OS_MANIFEST):
 *   { release_id, version, min_version, ring, arch, state, rollout_percent, security?,
 *     critical?, mandatory?, deadline?, entitlement?, rollback_directive?,
 *     signature: "<key_id>:<base64 Ed25519>" }
 */
final class OsUpdateService
{
    public const RINGS = ['development', 'pilot', 'stable'];
    public const ARCHES = ['amd64', 'arm64'];
    public const TYPES = ['SECURITY', 'FEATURE', 'EMERGENCY'];

    /** Update Center channel names accepted as ring aliases. */
    private const ALIASES = ['canary' => 'development', 'dev' => 'development', 'beta' => 'pilot', 'stable' => 'stable', 'development' => 'development', 'pilot' => 'pilot'];

    /** OS ring => Update Center channel code. */
    public const CHANNEL = ['development' => 'DEV', 'pilot' => 'BETA', 'stable' => 'STABLE'];

    /** OS ring => APT suite built by publish.sh. */
    public const SUITE = ['development' => 'trixie-dev', 'pilot' => 'trixie-pilot', 'stable' => 'trixie-stable'];

    private const FILE_RE = '/^[A-Za-z0-9][A-Za-z0-9._+~-]{0,200}$/';

    public static function ring(string $r): string
    {
        return self::ALIASES[strtolower($r)] ?? throw ApiException::validation(['ring' => 'Ring must be stable, beta (pilot) or canary (development)']);
    }

    public static function arch(string $a): string
    {
        $a = strtolower($a) === 'x64' ? 'amd64' : strtolower($a);

        return in_array($a, self::ARCHES, true) ? $a : throw ApiException::validation(['arch' => 'Architecture must be amd64 or arm64']);
    }

    public static function version(string $v): string
    {
        return preg_match('/^[0-9][A-Za-z0-9.+~-]{0,63}$/', $v) ? $v : throw ApiException::validation(['version' => 'Invalid version']);
    }

    // ---------------------------------------------------------------- public reads

    /** The signed manifest JSON (already canonical) for a ring/arch, or null. */
    public function manifest(string $ring, string $arch): ?string
    {
        $row = db_connect()->table('os_releases')->where('ring', self::ring($ring))->where('arch', self::arch($arch))
            ->where('manifest IS NOT NULL')->orderBy('published_at', 'DESC')->orderBy('id', 'DESC')->get()->getRowArray();

        return $row['manifest'] ?? null;
    }

    /** Public list for the download page: newest version per ring/arch with ISO, SHA-256 and SBOM links. */
    public function publicReleases(): array
    {
        $out  = [];
        $base = rtrim(config('UpdateCenter')->publicBaseUrl, '/');
        foreach (db_connect()->table('os_releases')->where('state !=', 'pulled')->orderBy('published_at', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray() as $r) {
            $k = $r['ring'] . '/' . $r['arch'];
            if (isset($out[$k])) {
                continue;
            }
            $iso = $r['iso_key'] ? basename($r['iso_key']) : null;
            $out[$k] = [
                'version' => $r['version'], 'ring' => $r['ring'], 'channel' => self::CHANNEL[$r['ring']], 'arch' => $r['arch'],
                'release_type' => $r['release_type'], 'state' => $r['state'], 'published_at' => $r['published_at'], 'notes' => $r['notes'],
                'iso' => $iso ? ['file_name' => $iso, 'size_bytes' => (int) $r['iso_size'], 'sha256' => $r['iso_sha256'],
                    'url' => "{$base}/os/download/{$r['version']}/{$r['arch']}/{$iso}",
                    'sha256_url' => "{$base}/os/download/{$r['version']}/{$r['arch']}/{$iso}.sha256"] : null,
                'sbom' => $r['sbom_key'] ? ['file_name' => basename($r['sbom_key']), 'url' => "{$base}/os/download/{$r['version']}/{$r['arch']}/" . basename($r['sbom_key'])] : null,
                'apt' => ['url' => "{$base}/os/apt", 'suite' => self::SUITE[$r['ring']], 'keyring_url' => "{$base}/os/apt/worksuite-archive-keyring.gpg"],
            ];
        }

        return ['data' => array_values($out), 'keys_url' => "{$base}/api/v1/os/keys"];
    }

    /** Release public keys as PEM (SPKI) for /usr/share/worksuite/keys/release/<key_id>.pem. */
    public function publicKeys(): array
    {
        $out = [];
        foreach (service('signingClient')->releasePublicKeys() as $kid => $raw) {
            $der   = hex2bin('302a300506032b6570032100') . $raw; // SPKI prefix for Ed25519
            $out[] = ['key_id' => $kid, 'public_pem' => "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n"];
        }

        return ['keys' => $out];
    }

    /** Storage key of a downloadable ISO / .sha256 / SBOM file, or null when unknown. */
    public function artifactKey(string $version, string $arch, string $file): ?string
    {
        if (! preg_match(self::FILE_RE, $file)) {
            return null;
        }
        $key = 'os/releases/' . self::version($version) . '/' . self::arch($arch) . '/' . $file;

        return $this->storage()->head($key) !== null ? $key : null;
    }

    public function aptKey(string $path): ?string
    {
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..') || ! preg_match('#^(dists|pool)/[A-Za-z0-9._+~/-]+$|^worksuite-archive-keyring\.gpg$#', $path)) {
            return null;
        }

        return $this->storage()->head('os/apt/' . $path) !== null ? 'os/apt/' . $path : null;
    }

    public function storage(): ReleaseStorageProviderInterface
    {
        return StorageFactory::fromRow(Catalog::activeStorage());
    }

    // ---------------------------------------------------------------- CI publishing

    /** Store one release artifact (ISO, .sha256, SBOM) from a local temp file. */
    public function putArtifact(string $version, string $arch, string $file, string $localPath): array
    {
        if (! preg_match(self::FILE_RE, $file) || ! preg_match('/\.(iso|sha256|json|spdx|cdx\.json|txt)$/', $file)) {
            throw ApiException::validation(['file' => 'Only .iso, .sha256 and SBOM (.json/.spdx) files are accepted']);
        }
        $key = 'os/releases/' . self::version($version) . '/' . self::arch($arch) . '/' . $file;
        $sha  = hash_file('sha256', $localPath);
        $size = (int) filesize($localPath);
        $this->storage()->putFile($key, $localPath, str_ends_with($file, '.iso') ? 'application/x-iso9660-image' : 'application/octet-stream');
        service('audit')->record('os.artifact.upload', 'os_artifact', $key, null, ['sha256' => $sha, 'size' => $size]);

        return ['key' => $key, 'sha256' => $sha, 'size_bytes' => $size];
    }

    /**
     * Import the static tree tools/repo/publish.sh produced (tar.gz of dists/ + pool/ +
     * optional worksuite-archive-keyring.gpg). dists/<suite> is replaced, pool/ only grows,
     * so older .debs stay available for rollback directives.
     */
    public function importApt(string $tarGzPath): array
    {
        $tmp = sys_get_temp_dir() . '/os-apt-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);

        try {
            $tar = $tarGzPath;
            if (! str_ends_with($tar, '.tar.gz')) {
                copy($tarGzPath, $tar = $tmp . '.tar.gz');
            }
            (new \PharData($tar))->extractTo($tmp, null, true);
            $files  = [];
            $suites = [];
            $it     = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (! $f->isFile() || $f->isLink()) {
                    continue;
                }
                $rel = ltrim(substr($f->getPathname(), strlen($tmp)), '/');
                if (! preg_match('#^(dists|pool)/[A-Za-z0-9._+~/-]+$|^worksuite-archive-keyring\.gpg$#', $rel) || str_contains($rel, '..')) {
                    continue; // lists/ and anything unexpected stay out of the public repo
                }
                if (preg_match('#^dists/([^/]+)/#', $rel, $m)) {
                    if (! in_array($m[1], self::SUITE, true)) {
                        throw ApiException::validation(['suite' => "Unknown suite {$m[1]}"]);
                    }
                    $suites[$m[1]] = true;
                }
                $files[$rel] = $f->getPathname();
            }
            foreach (array_keys($suites) as $s) {
                if (! isset($files["dists/{$s}/InRelease"])) {
                    throw ApiException::validation(['apt' => "dists/{$s}/InRelease missing: the repository must be signed by publish.sh"]);
                }
            }
            if ($files === []) {
                throw ApiException::validation(['apt' => 'Archive contains no dists/ or pool/ files']);
            }
            $st = $this->storage();
            // Packages before indexes before InRelease, so a client never sees an index that names a missing file.
            uksort($files, static fn ($a, $b) => [str_starts_with($a, 'dists/'), str_ends_with($a, 'InRelease'), $a] <=> [str_starts_with($b, 'dists/'), str_ends_with($b, 'InRelease'), $b]);
            foreach ($files as $rel => $local) {
                $st->putFile('os/apt/' . $rel, $local, str_ends_with($rel, '.deb') ? 'application/vnd.debian.binary-package' : 'application/octet-stream');
            }
            service('audit')->record('os.apt.import', 'os_apt', implode(',', array_keys($suites)), null, ['files' => count($files)]);

            return ['files' => count($files), 'suites' => array_keys($suites)];
        } catch (\PharException|\UnexpectedValueException $e) {
            throw ApiException::validation(['apt' => 'Not a readable tar.gz: ' . $e->getMessage()]);
        } finally {
            self::rmTree($tmp);
            @unlink($tmp . '.tar.gz');
        }
    }

    /**
     * Publish a version to a ring for one or both architectures and sign the manifests.
     *
     * @param array<string, mixed> $in version, ring, arch|arches, release_type, min_version, rollout_percent, deadline, entitlement, notes
     */
    public function publish(array $in, string $actor): array
    {
        $version = self::version((string) ($in['version'] ?? ''));
        $ring    = self::ring((string) ($in['ring'] ?? 'development'));
        $type    = strtoupper((string) ($in['release_type'] ?? 'FEATURE'));
        if (! in_array($type, self::TYPES, true)) {
            throw ApiException::validation(['release_type' => 'release_type must be security, feature or emergency']);
        }
        $pct = (int) ($in['rollout_percent'] ?? 100);
        if ($pct < 0 || $pct > 100) {
            throw ApiException::validation(['rollout_percent' => '0–100']);
        }
        $deadline = isset($in['deadline']) && $in['deadline'] !== '' ? (string) $in['deadline'] : null;
        if ($deadline !== null && strtotime($deadline) === false) {
            throw ApiException::validation(['deadline' => 'RFC 3339 timestamp']);
        }
        $arches = array_map([self::class, 'arch'], (array) ($in['arches'] ?? [$in['arch'] ?? 'amd64']));
        $out    = [];
        foreach ($arches as $arch) {
            $iso = $this->findArtifact($version, $arch, '.iso');
            $row = [
                'release_id' => 'wsos-' . $version, 'version' => $version, 'min_version' => self::version((string) ($in['min_version'] ?? $version)),
                'ring' => $ring, 'arch' => $arch, 'release_type' => $type, 'state' => 'active', 'rollout_percent' => $type === 'EMERGENCY' ? 100 : $pct,
                'deadline' => $deadline, 'entitlement' => ($in['entitlement'] ?? null) ?: null, 'notes' => $in['notes'] ?? null,
                'rollback_from' => ($in['rollback_from'] ?? null) ?: null, 'rollback_reason' => $in['rollback_reason'] ?? null,
                'iso_key' => $iso['key'] ?? null, 'iso_sha256' => $iso['sha256'] ?? null, 'iso_size' => $iso['size'] ?? 0,
                'sbom_key' => $this->findArtifact($version, $arch, 'sbom')['key'] ?? null,
                'published_by' => $actor, 'published_at' => gmdate('Y-m-d H:i:s'),
            ];
            $out[] = $this->save($row, 'os.release.publish');
        }

        return ['data' => $out];
    }

    /** pause | resume | pull | rollout (percent) for one ring/arch (both arches when arch is null). */
    public function action(string $version, string $ring, ?string $arch, string $action, array $in, string $actor): array
    {
        $rows = $this->rows($version, self::ring($ring), $arch);
        $out  = [];
        foreach ($rows as $r) {
            $r = match ($action) {
                'pause'  => ['state' => 'paused'] + $r,
                'resume' => ['state' => 'active'] + $r,
                'pull'   => ['state' => 'pulled'] + $r,
                'rollout' => ['rollout_percent' => max(0, min(100, (int) ($in['rollout_percent'] ?? -1)))] + $r,
                default  => throw ApiException::notFound('UNKNOWN_ACTION', "Unknown action {$action}"),
            };
            if ($action === 'rollout' && ! isset($in['rollout_percent'])) {
                throw ApiException::validation(['rollout_percent' => 'required']);
            }
            if ($action === 'pull') {
                $this->restorePrevious($r, $actor);
            }
            $r['published_by'] = $actor;
            $out[]             = $this->save($r, 'os.release.' . $action, $in['reason'] ?? null);
        }

        return ['data' => $out];
    }

    /** Promote a version that is live on `from` to the next ring (development → pilot → stable). */
    public function promote(string $version, string $from, string $to, ?string $arch, string $actor): array
    {
        $from = self::ring($from);
        $to   = self::ring($to);
        if (array_search($to, self::RINGS, true) <= array_search($from, self::RINGS, true)) {
            throw ApiException::validation(['to' => 'Promotion goes development → pilot → stable']);
        }
        $out = [];
        foreach ($this->rows($version, $from, $arch) as $r) {
            if ($r['state'] !== 'active') {
                throw ApiException::conflict('OS_RELEASE_NOT_ACTIVE', "{$version} is {$r['state']} on {$from}");
            }
            unset($r['id'], $r['public_id'], $r['manifest'], $r['created_at'], $r['updated_at']);
            $r = ['ring' => $to, 'rollout_percent' => $to === 'stable' && $r['release_type'] === 'FEATURE' ? 10 : 100, 'published_by' => $actor, 'published_at' => gmdate('Y-m-d H:i:s')] + $r;
            $out[] = $this->save($r, 'os.release.promote');
        }

        return ['data' => $out];
    }

    /** Emergency security release: every ring, both arches, critical + mandatory, 100 %. */
    public function emergency(array $in, string $actor): array
    {
        $out = [];
        foreach (self::RINGS as $ring) {
            $out = array_merge($out, $this->publish(['ring' => $ring, 'release_type' => 'EMERGENCY', 'arches' => $in['arches'] ?? self::ARCHES] + $in, $actor)['data']);
        }

        return ['data' => $out];
    }

    public function list(): array
    {
        return ['data' => array_map(fn ($r) => $this->present($r), db_connect()->table('os_releases')->orderBy('published_at', 'DESC')->orderBy('id', 'DESC')->limit(200)->get()->getResultArray())];
    }

    // ---------------------------------------------------------------- internals

    /** The manifest document exactly as WorkSuite OS's Manifest interface describes it. */
    public static function document(array $r): array
    {
        $type = $r['release_type'];
        $doc  = [
            'release_id' => $r['release_id'], 'version' => $r['version'], 'min_version' => $r['min_version'],
            'ring' => $r['ring'], 'arch' => $r['arch'], 'state' => $r['state'], 'rollout_percent' => (int) $r['rollout_percent'],
        ];
        if ($type === 'SECURITY' || $type === 'EMERGENCY') {
            $doc['security'] = true;
        }
        if ($type === 'EMERGENCY') {
            $doc['critical']  = true;
            $doc['mandatory'] = true;
        }
        if (! empty($r['deadline'])) {
            $doc['deadline'] = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $r['deadline']));
        }
        if (! empty($r['entitlement'])) {
            $doc['entitlement'] = (string) $r['entitlement'];
        }
        if (! empty($r['rollback_from'])) {
            $doc['rollback_directive'] = ['from_version' => (string) $r['rollback_from'], 'reason' => (string) ($r['rollback_reason'] ?? 'rollback')];
        }

        return $doc;
    }

    private function save(array $r, string $auditAction, ?string $reason = null): array
    {
        try {
            $signed = service('signingClient')->sign('OS_MANIFEST', self::document($r), []);
        } catch (SigningRefusedException $e) {
            throw ApiException::conflict('SIGNING_REFUSED', 'Signing service refused the OS manifest', ['reasons' => $e->reasons ?? [$e->getMessage()]]);
        }
        $r['manifest'] = \App\Libraries\CanonicalJson::encode($signed);
        $db            = db_connect();
        $now           = gmdate('Y-m-d H:i:s');
        $existing      = $db->table('os_releases')->where('version', $r['version'])->where('ring', $r['ring'])->where('arch', $r['arch'])->get()->getRowArray();
        unset($r['id'], $r['created_at'], $r['updated_at']);
        if ($existing) {
            unset($r['public_id']);
            $db->table('os_releases')->where('id', $existing['id'])->update($r + ['updated_at' => $now]);
            $id = (int) $existing['id'];
        } else {
            $db->table('os_releases')->insert($r + ['public_id' => Ulid::prefixed('osr'), 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $db->insertID();
        }
        $row = $db->table('os_releases')->where('id', $id)->get()->getRowArray();
        service('audit')->record($auditAction, 'os_release', $row['public_id'], $existing ? $this->present($existing) : null, $this->present($row), $reason);

        return $this->present($row);
    }

    /**
     * Pulling the newest release re-serves the previous active one on that ring, with a signed
     * rollback directive so devices that already took the pulled version may go back.
     */
    private function restorePrevious(array $pulled, string $actor): void
    {
        $prev = db_connect()->table('os_releases')->where('ring', $pulled['ring'])->where('arch', $pulled['arch'])
            ->where('state', 'active')->where('version !=', $pulled['version'])->orderBy('published_at', 'DESC')->orderBy('id', 'DESC')->get()->getRowArray();
        if (! $prev) {
            return;
        }
        $prev['rollback_from']   = $pulled['version'];
        $prev['rollback_reason'] = 'release ' . $pulled['version'] . ' pulled';
        $prev['published_by']    = $actor;
        $prev['published_at']    = gmdate('Y-m-d H:i:s', time() + 1); // newer than the pulled row
        $this->save($prev, 'os.release.rollback');
    }

    private function rows(string $version, string $ring, ?string $arch): array
    {
        $q = db_connect()->table('os_releases')->where('version', self::version($version))->where('ring', $ring);
        if ($arch !== null && $arch !== '') {
            $q->where('arch', self::arch($arch));
        }
        $rows = $q->get()->getResultArray();

        return $rows ?: throw ApiException::notFound('OS_RELEASE_NOT_FOUND', "WorkSuite OS {$version} is not on {$ring}");
    }

    private function findArtifact(string $version, string $arch, string $kind): ?array
    {
        $prefix = "os/releases/{$version}/{$arch}/";
        $names  = $kind === '.iso'
            ? ["worksuite-os-{$version}-{$arch}.iso"]
            : ["worksuite-os-{$version}-{$arch}.sbom.spdx.json", "worksuite-os-{$version}-{$arch}.spdx.json", "worksuite-os-{$version}-{$arch}.cdx.json", "worksuite-os-{$version}-{$arch}.sbom.json"];
        $st = $this->storage();
        foreach ($names as $n) {
            $h = $st->head($prefix . $n);
            if ($h !== null) {
                $sha = null;
                if ($kind === '.iso' && ($s = $st->head($prefix . $n . '.sha256')) !== null) {
                    $sha = $this->readSha($st, $prefix . $n . '.sha256');
                }

                return ['key' => $prefix . $n, 'size' => (int) ($h['size'] ?? 0), 'sha256' => $sha];
            }
        }

        return null;
    }

    private function readSha(ReleaseStorageProviderInterface $st, string $key): ?string
    {
        $h = $st->stream($key);
        $s = is_resource($h) ? (string) stream_get_contents($h, 4096) : (string) $h;
        if (is_resource($h)) {
            fclose($h);
        }

        return preg_match('/\b([a-f0-9]{64})\b/i', $s, $m) ? strtolower($m[1]) : null;
    }

    private function present(array $r): array
    {
        return [
            'id' => $r['public_id'], 'release_id' => $r['release_id'], 'version' => $r['version'], 'min_version' => $r['min_version'],
            'ring' => $r['ring'], 'channel' => self::CHANNEL[$r['ring']] ?? null, 'arch' => $r['arch'], 'release_type' => $r['release_type'],
            'state' => $r['state'], 'rollout_percent' => (int) $r['rollout_percent'], 'deadline' => $r['deadline'], 'entitlement' => $r['entitlement'],
            'iso_sha256' => $r['iso_sha256'], 'published_by' => $r['published_by'], 'published_at' => $r['published_at'],
            'manifest' => $r['manifest'] ? json_decode((string) $r['manifest'], true) : null,
        ];
    }

    private static function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && ! $f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}

<?php

namespace App\Libraries\Scanner;

use App\Libraries\Semver;
use ZipArchive;

/**
 * Validates a WorkSuite Server edition ZIP (§17.5, FR-UC-SRV-04/05, AC-C42/C44).
 * Reads entries only; nothing inside the archive is extracted to an executable
 * location or run (FR-UC-SRV-06).
 */
final class ServerPackageValidator
{
    public const FORBIDDEN = ['.env', 'writable/', 'public/uploads/', '.git/', 'tests/', '.github/', 'node_modules/', 'phpunit.xml', 'phpunit.xml.dist', '.phpunit.cache/'];

    public const POST_UPDATE_ALLOWED = ['cache:clear', 'desktop:catalog-sync', 'optimize', 'routes:cache', 'bugtrace:flush', 'modules:sync', 'permissions:sync'];

    private const EDITION_ORDER = ['FREE' => 1, 'BUSINESS' => 2, 'ENTERPRISE' => 3];

    /**
     * @param array<string, list<string>> $editionModules edition code => module slugs (not cumulative)
     * @param array{version: string, build: int, channel: string, edition: string} $expect
     *
     * @return array{ok: bool, reject_code: ?string, checks: list<array{check: string, status: string, detail: ?string}>, package_json: ?array, files: int}
     */
    public function validate(string $zipPath, array $expect, array $editionModules): array
    {
        $checks = [];
        $add    = static function (string $check, bool $ok, ?string $detail = null) use (&$checks): void {
            $checks[] = ['check' => $check, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail];
        };
        $fail = static fn (string $code) => ['ok' => false, 'reject_code' => $code, 'checks' => $checks, 'package_json' => $pkg ?? null, 'files' => $count ?? 0];

        $zip = new ZipArchive();
        $rc  = $zip->open($zipPath, ZipArchive::CHECKCONS);
        if ($rc !== true) {
            $add('zip_integrity', false, 'Not a valid ZIP archive (code ' . $rc . ')');

            return ['ok' => false, 'reject_code' => 'ZIP_INVALID', 'checks' => $checks, 'package_json' => null, 'files' => 0];
        }

        // 1. Entry safety: zip-slip, absolute paths, symlinks, device files.
        $names  = [];
        $unsafe = [];
        $count  = $zip->numFiles;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) $stat['name'];
            $zip->getExternalAttributesIndex($i, $opsys, $attr);
            $mode = ($attr >> 16) & 0xF000;
            if ($name === '' || str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('#^[A-Za-z]:#', $name) || str_contains($name, "\0")) {
                $unsafe[] = "absolute path: {$name}";
            } elseif (in_array('..', preg_split('#[/\\\\]#', $name), true)) {
                $unsafe[] = "path traversal: {$name}";
            } elseif ($opsys === ZipArchive::OPSYS_UNIX && $mode === 0xA000) {
                $unsafe[] = "symlink: {$name}";
            } elseif ($opsys === ZipArchive::OPSYS_UNIX && in_array($mode, [0x2000, 0x6000, 0x1000, 0xC000], true)) {
                $unsafe[] = "device/special file: {$name}";
            }
            if (! str_ends_with($name, '/')) {
                $names[$name] = $i;
            }
        }
        $add('zip_integrity', $unsafe === [], $unsafe === [] ? "{$count} entries, no unsafe paths" : implode('; ', array_slice($unsafe, 0, 5)));
        if ($unsafe !== []) {
            $zip->close();

            return $fail('ZIP_UNSAFE_ENTRY');
        }

        // 2. Forbidden paths.
        $forbidden = [];
        foreach (array_keys($names) as $n) {
            if (basename($n) === '.env' || str_contains('/' . $n, '/.git/')) {
                $forbidden[] = $n;

                continue;
            }
            foreach (self::FORBIDDEN as $f) {
                if ($n === $f || (str_ends_with($f, '/') && str_starts_with($n, $f))) {
                    $forbidden[] = $n;
                    break;
                }
            }
        }
        $add('forbidden_paths', $forbidden === [], $forbidden === [] ? 'No .env, writable/, uploads, .git, tests' : 'Found: ' . implode(', ', array_slice($forbidden, 0, 5)));
        if ($forbidden !== []) {
            $zip->close();

            return $fail('FORBIDDEN_PATH');
        }

        // 3. Manifest schema.
        $raw = isset($names['worksuite-package.json']) ? $zip->getFromName('worksuite-package.json') : false;
        $pkg = is_string($raw) ? json_decode($raw, true) : null;
        $schemaErrors = is_array($pkg) ? self::schemaErrors($pkg) : ['worksuite-package.json missing or not JSON'];
        $add('manifest', $schemaErrors === [], $schemaErrors === [] ? 'worksuite-package.json schema 1' : implode('; ', $schemaErrors));
        if ($schemaErrors !== []) {
            $zip->close();

            return $fail('MANIFEST_INVALID');
        }

        // 4. Version / build / edition / channel match.
        $mismatch = [];
        if ((string) $pkg['version'] !== $expect['version']) {
            $mismatch[] = "version {$pkg['version']} ≠ {$expect['version']}";
        }
        if ((int) $pkg['build'] !== (int) $expect['build']) {
            $mismatch[] = "build {$pkg['build']} ≠ {$expect['build']}";
        }
        if (strtoupper((string) $pkg['edition']) !== strtoupper($expect['edition'])) {
            $mismatch[] = "edition {$pkg['edition']} ≠ " . strtolower($expect['edition']);
        }
        if (isset($pkg['channel']) && strtoupper((string) $pkg['channel']) !== strtoupper($expect['channel'])) {
            $mismatch[] = "channel {$pkg['channel']} ≠ " . strtolower($expect['channel']);
        }
        $add('version_match', $mismatch === [], $mismatch === [] ? "{$pkg['version']} build {$pkg['build']} · {$pkg['edition']}" : implode('; ', $mismatch));
        if ($mismatch !== []) {
            $zip->close();

            return $fail('METADATA_MISMATCH');
        }

        // 5. files.sha256 — every file listed and matching.
        $list = isset($names['files.sha256']) ? (string) $zip->getFromName('files.sha256') : null;
        if ($list === null) {
            $add('file_hashes', false, 'files.sha256 missing');
            $zip->close();

            return $fail('HASH_LIST_MISSING');
        }
        $expected = [];
        foreach (preg_split('/\R/', trim($list)) as $line) {
            if (preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', trim($line), $m)) {
                $expected[$m[2]] = strtolower($m[1]);
            }
        }
        $problems = [];
        foreach ($expected as $path => $hash) {
            if (! isset($names[$path])) {
                $problems[] = "missing {$path}";

                continue;
            }
            $ctx = hash_init('sha256');
            $fh  = $zip->getStreamIndex($names[$path]);
            while ($fh && ! feof($fh)) {
                hash_update($ctx, (string) fread($fh, 1 << 20));
            }
            if ($fh) {
                fclose($fh);
            }
            if (hash_final($ctx) !== $hash) {
                $problems[] = "hash mismatch {$path}";
            }
            if (count($problems) > 20) {
                break;
            }
        }
        $unlisted = array_diff(array_keys($names), array_keys($expected), ['worksuite-package.json', 'files.sha256']);
        foreach (array_slice($unlisted, 0, 5) as $u) {
            $problems[] = "unlisted {$u}";
        }
        if (isset($pkg['files_digest']) && $pkg['files_digest'] !== 'sha256:' . hash('sha256', $list)) {
            $problems[] = 'files_digest does not match files.sha256';
        }
        $add('file_hashes', $problems === [], $problems === [] ? count($expected) . ' files verified' : implode('; ', array_slice($problems, 0, 5)));
        if ($problems !== []) {
            $zip->close();

            return $fail('FILE_HASH_MISMATCH');
        }

        // 6. Requirements + migrations + post-update allow-list.
        $req = $pkg['requires'];
        $add('requirements', isset($req['php'], $req['db']) && is_array($req['ext'] ?? null), "PHP {$req['php']}, DB {$req['db']}");
        $mig = $pkg['migrations'];
        $add('migrations', isset($mig['count']) && is_array($mig['one_way'] ?? null), ($mig['count'] ?? '?') . ' migrations, ' . count((array) ($mig['one_way'] ?? [])) . ' one-way');
        $badPost = array_diff((array) ($pkg['post_update'] ?? []), self::POST_UPDATE_ALLOWED);
        if (isset($names['update/post-update.json'])) {
            $post    = json_decode((string) $zip->getFromName('update/post-update.json'), true);
            $badPost = array_merge($badPost, array_diff((array) ($post['commands'] ?? $post ?? []), self::POST_UPDATE_ALLOWED));
        }
        $add('post_update', $badPost === [], $badPost === [] ? 'Allow-listed spark commands only' : 'Not allowed: ' . implode(', ', $badPost));
        if ($badPost !== []) {
            $zip->close();

            return $fail('POST_UPDATE_NOT_ALLOWED');
        }

        // 7. Edition content (paid-module code never shipped in lower editions).
        $edition = strtoupper((string) $pkg['edition']);
        $scope   = (string) ($pkg['scope'] ?? 'full');
        $allowed = [];
        if ($edition === 'ALL') {
            $allowed = ['core'];
        } else {
            foreach (self::EDITION_ORDER as $code => $rank) {
                if ($rank <= (self::EDITION_ORDER[$edition] ?? 0)) {
                    $allowed = array_merge($allowed, $editionModules[$code] ?? []);
                }
            }
        }
        $allowed    = array_map('strtolower', $allowed);
        $found      = [];
        foreach (array_keys($names) as $n) {
            if (preg_match('#^app/Modules/([^/]+)/#', $n, $m)) {
                $found[strtolower($m[1])] = true;
            }
        }
        $outside = array_values(array_diff(array_keys($found), $allowed));
        $declaredOutside = array_values(array_diff(array_map('strtolower', (array) $pkg['modules']), $allowed));
        if ($edition === 'ALL' && $scope !== 'core-patch') {
            $outside[] = 'ALL packages must have scope core-patch';
        }
        $okEdition = $outside === [] && $declaredOutside === [];
        $add('edition_content', $okEdition, $okEdition ? count($found) . ' modules, all within ' . strtolower($edition) : 'Modules outside ' . strtolower($edition) . ': ' . implode(', ', array_unique(array_merge($outside, $declaredOutside))));
        $zip->close();
        if (! $okEdition) {
            return $fail('EDITION_CONTENT_VIOLATION');
        }

        return ['ok' => true, 'reject_code' => null, 'checks' => $checks, 'package_json' => $pkg, 'files' => count($expected)];
    }

    /** @return list<string> */
    public static function schemaErrors(array $p): array
    {
        $e = [];
        if (($p['schema'] ?? null) !== 1) {
            $e[] = 'schema must be 1';
        }
        if (($p['product'] ?? null) !== 'worksuite-server') {
            $e[] = 'product must be worksuite-server';
        }
        if (! is_string($p['version'] ?? null) || ! Semver::isValid($p['version'])) {
            $e[] = 'version must be SemVer';
        }
        if (! is_int($p['build'] ?? null) || $p['build'] <= 0) {
            $e[] = 'build must be a positive integer';
        }
        if (! in_array(strtolower((string) ($p['edition'] ?? '')), ['free', 'business', 'enterprise', 'all'], true)) {
            $e[] = 'edition must be free|business|enterprise|all';
        }
        if (! in_array($p['scope'] ?? 'full', ['full', 'core-patch'], true)) {
            $e[] = 'scope must be full|core-patch';
        }
        if (isset($p['min_from_version']) && ! Semver::isValid((string) $p['min_from_version'])) {
            $e[] = 'min_from_version must be SemVer';
        }
        if (! is_array($p['requires'] ?? null)) {
            $e[] = 'requires missing';
        }
        if (! is_array($p['modules'] ?? null)) {
            $e[] = 'modules missing';
        }
        if (! is_array($p['migrations'] ?? null)) {
            $e[] = 'migrations missing';
        }

        return $e;
    }
}

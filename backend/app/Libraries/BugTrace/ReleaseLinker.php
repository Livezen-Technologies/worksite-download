<?php

namespace App\Libraries\BugTrace;

use App\Libraries\Semver;

/**
 * Maps event release strings ("2.7.0+27001", "lk.worksuite.desktop@2.7.0+27001",
 * "3.4.0 (34012)") to Update Center releases of the project's application
 * (FR-BT-ING-07). Unknown versions or build numbers (side-loaded / dev builds)
 * are flagged with known=false.
 */
final class ReleaseLinker
{
    /** @var array<string, array{release_id: ?int, known: bool, version: ?string, build: ?int}> */
    private array $cache = [];

    /** @return array{version: ?string, build: ?int} */
    public static function parse(?string $release): array
    {
        $r = trim((string) $release);
        if ($r === '') {
            return ['version' => null, 'build' => null];
        }
        if (str_contains($r, '@')) {
            $r = substr($r, strrpos($r, '@') + 1);
        }
        if (! preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)(?:\+(?:build\.)?(\d+)[0-9A-Za-z.-]*|\s*\((\d+)\))?/', $r, $m)) {
            if (preg_match('/^v?(\d+\.\d+)(?:\.(\d+))?/', $r, $m2)) {
                return ['version' => Semver::normalize($m2[0]), 'build' => null];
            }

            return ['version' => null, 'build' => null];
        }
        $build = ($m[2] ?? '') !== '' ? (int) $m[2] : (($m[3] ?? '') !== '' ? (int) $m[3] : null);

        return ['version' => $m[1], 'build' => $build];
    }

    /** @return array{release_id: ?int, known: bool, version: ?string, build: ?int} */
    public function link(int $applicationId, ?string $release): array
    {
        $key = $applicationId . '|' . $release;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $p   = self::parse($release);
        $out = ['release_id' => null, 'known' => false, 'version' => $p['version'], 'build' => $p['build']];
        if ($p['version'] !== null) {
            $rows = db_connect()->table('releases')->select('id, build_number, status')
                ->where('application_id', $applicationId)->where('version', $p['version'])->get()->getResultArray();
            usort($rows, static fn ($a, $b) => (($b['status'] === 'PUBLISHED') <=> ($a['status'] === 'PUBLISHED')) ?: ((int) $b['id'] <=> (int) $a['id']));
            foreach ($rows as $row) {
                if ($p['build'] === null || (int) $row['build_number'] === $p['build'] || (int) $row['build_number'] === 0) {
                    $out['release_id'] = (int) $row['id'];
                    $out['known']      = true;
                    break;
                }
            }
        }

        return $this->cache[$key] = $out;
    }

    /** Version compare that tolerates junk (unknown → equal). */
    public static function compare(?string $a, ?string $b): int
    {
        $va = self::parse($a)['version'];
        $vb = self::parse($b)['version'];
        if ($va === null || $vb === null) {
            return 0;
        }

        try {
            return Semver::compare($va, $vb);
        } catch (\Throwable) {
            return 0;
        }
    }
}

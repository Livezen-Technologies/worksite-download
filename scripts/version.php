#!/usr/bin/env php
<?php
/**
 * Version management for the Update Center repository.
 *
 * The single source of truth is /VERSION (SemVer 2.0). This script keeps every
 * package manifest in sync with it and rolls the CHANGELOG "Unreleased" section
 * into a dated release heading.
 *
 *   php scripts/version.php show
 *   php scripts/version.php bump patch|minor|major [--pre=beta.1] [--dry-run]
 *   php scripts/version.php set 1.2.0-rc.1 [--dry-run]
 *   php scripts/version.php sync            # write VERSION into package.json files
 *   php scripts/version.php check           # CI: fail if anything is out of sync
 */

$root      = dirname(__DIR__);
$semver    = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z.-]+))?$/';
$manifests = ['frontend/package.json', 'sdks/bugtrace-web/package.json'];
$changelog = $root . '/CHANGELOG.md';
$repoUrl   = 'https://github.com/Livezen-Technologies/worksite-download';

$args   = array_slice($argv, 1);
$cmd    = $args[0] ?? 'show';
$dry    = in_array('--dry-run', $args, true);
$pre    = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--pre=')) {
        $pre = substr($a, 6);
    }
}

function fail(string $msg): never
{
    fwrite(STDERR, "error: {$msg}\n");
    exit(1);
}

$current = trim((string) @file_get_contents($root . '/VERSION'));
if (! preg_match($semver, $current, $m)) {
    fail("VERSION does not contain a valid SemVer ({$current})");
}

/** SemVer 2.0 precedence (build metadata not used). */
function semverCompare(string $a, string $b): int
{
    [$ca, $pa] = array_pad(explode('-', $a, 2), 2, null);
    [$cb, $pb] = array_pad(explode('-', $b, 2), 2, null);
    $c = version_compare($ca, $cb);
    if ($c !== 0) {
        return $c;
    }
    if ($pa === $pb) {
        return 0;
    }
    if ($pa === null) {
        return 1;
    }
    if ($pb === null) {
        return -1;
    }
    $xa = explode('.', $pa);
    $xb = explode('.', $pb);
    for ($i = 0; $i < max(count($xa), count($xb)); $i++) {
        if (! isset($xa[$i])) {
            return -1;
        }
        if (! isset($xb[$i])) {
            return 1;
        }
        $na = ctype_digit($xa[$i]);
        $nb = ctype_digit($xb[$i]);
        $r  = $na && $nb ? ((int) $xa[$i] <=> (int) $xb[$i]) : ($na ? -1 : ($nb ? 1 : strcmp($xa[$i], $xb[$i]) <=> 0));
        if ($r !== 0) {
            return $r;
        }
    }

    return 0;
}

function syncManifests(string $root, array $manifests, string $version, bool $dry): array
{
    $changed = [];
    foreach ($manifests as $rel) {
        $path = $root . '/' . $rel;
        if (! is_file($path)) {
            continue;
        }
        $json = file_get_contents($path);
        $new  = preg_replace('/("version"\s*:\s*")[^"]*(")/', '${1}' . $version . '${2}', $json, 1, $count);
        if ($count === 0) {
            fail("{$rel} has no \"version\" field");
        }
        if ($new !== $json) {
            $changed[] = $rel;
            if (! $dry) {
                file_put_contents($path, $new);
            }
        }
    }

    return $changed;
}

function rollChangelog(string $file, string $from, string $to, string $repoUrl, bool $dry): void
{
    $md = (string) file_get_contents($file);
    if (! preg_match('/^## \[Unreleased\]\s*$/m', $md)) {
        fail('CHANGELOG.md has no "## [Unreleased]" section');
    }
    if (preg_match('/^## \[' . preg_quote($to, '/') . '\]/m', $md)) {
        fail("CHANGELOG.md already has a section for {$to}");
    }
    // Unreleased must contain at least one entry before a release is cut.
    preg_match('/^## \[Unreleased\]\s*\n(.*?)(?=^## \[)/ms', $md . "\n## [", $body);
    if (! preg_match('/^\s*- /m', $body[1] ?? '')) {
        fail('Nothing under [Unreleased] — add changelog entries before bumping the version');
    }
    $date = gmdate('Y-m-d');
    $md   = preg_replace('/^## \[Unreleased\]\s*$/m', "## [Unreleased]\n\n## [{$to}] - {$date}", $md, 1);
    // Reference links at the bottom.
    $md = preg_replace('/^\[Unreleased\]: .*$/m', "[Unreleased]: {$repoUrl}/compare/v{$to}...HEAD\n[{$to}]: {$repoUrl}/compare/v{$from}...v{$to}", $md, 1);
    if (! $dry) {
        file_put_contents($file, $md);
    }
}

switch ($cmd) {
    case 'show':
        echo $current, "\n";
        exit(0);

    case 'sync':
        $changed = syncManifests($root, $manifests, $current, $dry);
        echo $changed ? 'Updated: ' . implode(', ', $changed) . "\n" : "All manifests already at {$current}\n";
        exit(0);

    case 'check':
        $problems = [];
        foreach ($manifests as $rel) {
            $path = $root . '/' . $rel;
            if (is_file($path)) {
                $v = json_decode((string) file_get_contents($path), true)['version'] ?? null;
                if ($v !== $current) {
                    $problems[] = "{$rel} is {$v}, VERSION is {$current}";
                }
            }
        }
        $md = (string) @file_get_contents($changelog);
        if (! preg_match('/^## \[' . preg_quote($current, '/') . '\] - \d{4}-\d{2}-\d{2}/m', $md)) {
            $problems[] = "CHANGELOG.md has no dated section for {$current}";
        }
        if (! preg_match('/^## \[Unreleased\]/m', $md)) {
            $problems[] = 'CHANGELOG.md has no [Unreleased] section';
        }
        if ($problems) {
            fail(implode("\n       ", $problems));
        }
        echo "Version {$current} is consistent\n";
        exit(0);

    case 'bump':
    case 'set':
        if ($cmd === 'set') {
            $next = $args[1] ?? '';
            if (! preg_match($semver, $next)) {
                fail('usage: version.php set X.Y.Z[-pre]');
            }
        } else {
            [$maj, $min, $pat] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $part = $args[1] ?? '';
            // Bumping from a prerelease of the same core version finalises it (1.2.0-rc.1 → 1.2.0).
            $isPre = isset($m[4]) && $m[4] !== '';
            [$maj, $min, $pat] = match ($part) {
                'major' => $isPre && $min === 0 && $pat === 0 ? [$maj, 0, 0] : [$maj + 1, 0, 0],
                'minor' => $isPre && $pat === 0 ? [$maj, $min, 0] : [$maj, $min + 1, 0],
                'patch' => $isPre ? [$maj, $min, $pat] : [$maj, $min, $pat + 1],
                default => fail('usage: version.php bump patch|minor|major [--pre=beta.1]'),
            };
            $next = "{$maj}.{$min}.{$pat}" . ($pre ? "-{$pre}" : '');
        }
        if (semverCompare($next, $current) <= 0) {
            fail("{$next} is not greater than {$current}");
        }
        rollChangelog($changelog, $current, $next, $repoUrl, $dry);
        if (! $dry) {
            file_put_contents($root . '/VERSION', $next . "\n");
        }
        $changed = syncManifests($root, $manifests, $next, $dry);
        echo ($dry ? '[dry run] ' : '') . "{$current} → {$next}\n";
        echo '  CHANGELOG.md: [Unreleased] → [' . $next . '] - ' . gmdate('Y-m-d') . "\n";
        foreach ($changed as $c) {
            echo "  {$c}\n";
        }
        echo "Next: git commit -am \"Release v{$next}\" && git tag v{$next}\n";
        exit(0);

    default:
        fail("unknown command {$cmd}");
}

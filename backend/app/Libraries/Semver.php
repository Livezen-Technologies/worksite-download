<?php

namespace App\Libraries;

use InvalidArgumentException;

/**
 * SemVer 2.0 parsing, comparison and simple range matching.
 */
final class Semver
{
    private const PATTERN = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    public static function isValid(string $v): bool
    {
        return (bool) preg_match(self::PATTERN, trim($v));
    }

    /**
     * @return array{major:int,minor:int,patch:int,pre:?string,build:?string}
     */
    public static function parse(string $v): array
    {
        if (! preg_match(self::PATTERN, trim($v), $m)) {
            throw new InvalidArgumentException("Invalid semantic version: {$v}");
        }

        return [
            'major' => (int) $m[1],
            'minor' => (int) $m[2],
            'patch' => (int) $m[3],
            'pre'   => isset($m[4]) && $m[4] !== '' ? $m[4] : null,
            'build' => isset($m[5]) && $m[5] !== '' ? $m[5] : null,
        ];
    }

    /**
     * Lenient parse used for client-reported versions (e.g. "2.6" or "2.6.5.1").
     */
    public static function normalize(string $v): string
    {
        $v = trim($v);
        if (self::isValid($v)) {
            return ltrim($v, 'v');
        }
        if (preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $v, $m)) {
            return sprintf('%d.%d.%d', $m[1], $m[2] ?? 0, $m[3] ?? 0);
        }

        throw new InvalidArgumentException("Invalid version: {$v}");
    }

    public static function compare(string $a, string $b): int
    {
        $pa = self::parse(self::normalize($a));
        $pb = self::parse(self::normalize($b));
        foreach (['major', 'minor', 'patch'] as $k) {
            if ($pa[$k] !== $pb[$k]) {
                return $pa[$k] <=> $pb[$k];
            }
        }
        if ($pa['pre'] === $pb['pre']) {
            return 0;
        }
        if ($pa['pre'] === null) {
            return 1;
        }
        if ($pb['pre'] === null) {
            return -1;
        }
        $ia = explode('.', $pa['pre']);
        $ib = explode('.', $pb['pre']);
        $n  = max(count($ia), count($ib));
        for ($i = 0; $i < $n; $i++) {
            if (! isset($ia[$i])) {
                return -1;
            }
            if (! isset($ib[$i])) {
                return 1;
            }
            $na = ctype_digit($ia[$i]);
            $nb = ctype_digit($ib[$i]);
            if ($na && $nb) {
                $c = (int) $ia[$i] <=> (int) $ib[$i];
            } elseif ($na) {
                $c = -1;
            } elseif ($nb) {
                $c = 1;
            } else {
                $c = strcmp($ia[$i], $ib[$i]) <=> 0;
            }
            if ($c !== 0) {
                return $c;
            }
        }

        return 0;
    }

    public static function lt(string $a, string $b): bool
    {
        return self::compare($a, $b) < 0;
    }

    public static function gte(string $a, string $b): bool
    {
        return self::compare($a, $b) >= 0;
    }

    public static function max(?string ...$versions): ?string
    {
        $best = null;
        foreach ($versions as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if ($best === null || self::compare($v, $best) > 0) {
                $best = $v;
            }
        }

        return $best;
    }

    /**
     * Sortable string key: 00002.00007.00000.~ (release sorts after any prerelease).
     */
    public static function sortKey(string $v): string
    {
        $p   = self::parse($v);
        $pre = $p['pre'] === null ? '~' : '-' . preg_replace_callback('/\d+/', static fn ($m) => str_pad($m[0], 6, '0', STR_PAD_LEFT), $p['pre']);

        return sprintf('%05d.%05d.%05d.%s', $p['major'], $p['minor'], $p['patch'], substr($pre, 0, 40));
    }

    /**
     * Range syntax supported: "<2.4.0", ">=2.5.0 <3.0.0", "2.6.0 - 2.8.x", "2.6.x", "*", "=2.6.5", "2.6.5".
     */
    public static function satisfies(string $version, string $range): bool
    {
        $range = trim($range);
        if ($range === '' || $range === '*') {
            return true;
        }
        foreach (preg_split('/\s*\|\|\s*/', $range) as $alt) {
            if (self::satisfiesAll($version, $alt)) {
                return true;
            }
        }

        return false;
    }

    private static function satisfiesAll(string $version, string $range): bool
    {
        if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $range, $m)) {
            return self::satisfiesAll($version, '>=' . self::xLow($m[1]) . ' ' . self::xHighComparator($m[2]));
        }
        foreach (preg_split('/\s+/', $range) as $cmp) {
            if (! preg_match('/^(<=|>=|<|>|=)?(.+)$/', $cmp, $m)) {
                return false;
            }
            $op  = $m[1] === '' ? '=' : $m[1];
            $ver = $m[2];
            if (str_contains($ver, 'x') || str_contains($ver, '*')) {
                if ($op === '=') {
                    if (! self::satisfiesAll($version, '>=' . self::xLow($ver) . ' ' . self::xHighComparator($ver))) {
                        return false;
                    }

                    continue;
                }
                $ver = self::xLow($ver);
            }
            $c  = self::compare($version, $ver);
            $ok = match ($op) {
                '<'     => $c < 0,
                '<='    => $c <= 0,
                '>'     => $c > 0,
                '>='    => $c >= 0,
                default => $c === 0,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private static function xLow(string $v): string
    {
        $parts = explode('.', str_replace('*', 'x', $v));
        $out   = [];
        for ($i = 0; $i < 3; $i++) {
            $out[] = isset($parts[$i]) && $parts[$i] !== 'x' ? $parts[$i] : '0';
        }

        return implode('.', $out);
    }

    private static function xHighComparator(string $v): string
    {
        $parts = explode('.', str_replace('*', 'x', $v));
        if (count($parts) === 3 && ! in_array('x', $parts, true)) {
            return '<=' . $v;
        }
        if (! isset($parts[1]) || $parts[1] === 'x') {
            return '<' . ((int) $parts[0] + 1) . '.0.0-0';
        }

        return '<' . $parts[0] . '.' . ((int) $parts[1] + 1) . '.0-0';
    }
}

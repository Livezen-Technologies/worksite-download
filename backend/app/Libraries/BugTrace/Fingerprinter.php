<?php

namespace App\Libraries\BugTrace;

/**
 * Groups events into issues (§18.6, FR-BT-ING-05):
 *
 *   fingerprint = sha256(exception type + normalized top 5 in-app frames)
 *
 * Frames are normalized to module/function/file without line/column numbers,
 * query strings or content-hash suffixes (assets/mail-8c1.js → assets/mail.js).
 * Minified one/two-letter function names are ignored so two builds of the same
 * bundle group together. Native crashes use signal + top frames (or, while
 * unsymbolicated, signal + crashing module + process type). Events without
 * frames fall back to type + normalized message. Custom rules (bt_fingerprint_rules)
 * override the default: match_type ∈ exception_type | message | file | function | type,
 * match_value is a glob (or /regex/), fingerprint may contain {{ default }}.
 *
 * Frames are ordered outermost → innermost (Sentry convention): the crashing
 * frame is the last one.
 */
final class Fingerprinter
{
    public const TOP_FRAMES = 5;

    /** @param list<array<string, mixed>> $rules */
    public function __construct(private readonly array $rules = [])
    {
    }

    /**
     * @param array<string, mixed>           $event  scrubbed event (exception[] with stacktrace.frames)
     * @param list<array<string, mixed>>|null $frames symbolicated frames of the primary exception (preferred)
     *
     * @return array{fingerprint: string, title: string, culprit: ?string, components: list<string>}
     */
    public function compute(array $event, ?array $frames = null): array
    {
        $ex       = self::primaryException($event);
        $type     = trim((string) ($ex['type'] ?? '')) ?: ucfirst((string) ($event['type'] ?? 'error'));
        $value    = (string) ($ex['value'] ?? ($event['message'] ?? ''));
        $frames ??= $ex['stacktrace']['frames'] ?? [];
        $top      = self::topFrames(is_array($frames) ? $frames : []);

        $components = [$type];
        $native     = in_array($event['type'] ?? '', ['crash', 'hang'], true) && (isset($ex['signal']) || ($ex['mechanism']['type'] ?? '') === 'minidump');
        if ($native && isset($ex['signal'])) {
            $components[] = 'signal:' . $ex['signal'];
        }
        if ($top !== []) {
            foreach ($top as $f) {
                $components[] = self::frameKey($f);
            }
        } elseif ($native) {
            $components[] = 'module:' . self::normalizeFile((string) ($ex['module'] ?? ''));
            $components[] = 'process:' . (string) ($event['tags']['process_type'] ?? $event['contexts']['app']['process_type'] ?? 'main');
        } else {
            $components[] = 'msg:' . self::normalizeMessage($value);
        }
        $default = implode("\n", $components);

        $custom = $this->applyRules($event, $ex, $top, $default);
        $key    = $custom ?? $default;

        $title = $value !== '' ? $type . ': ' . $value : $type;
        if ($title === '' || ($ex === [] && isset($event['message']))) {
            $title = (string) ($event['message'] ?? 'Unknown error');
        }

        return [
            'fingerprint' => hash('sha256', $key),
            'title'       => mb_substr(preg_replace('/\s+/', ' ', $title), 0, 255),
            'culprit'     => self::culprit($top),
            'components'  => $custom !== null ? ['custom:' . $custom] : $components,
        ];
    }

    /** @return array<string, mixed> */
    public static function primaryException(array $event): array
    {
        $list = $event['exception'] ?? [];
        if (! is_array($list) || $list === []) {
            return [];
        }
        // Chained exceptions: Sentry puts the outermost (reported) one last.
        $last = end($list);

        return is_array($last) ? $last : [];
    }

    /**
     * Top N in-app frames nearest the crash (innermost first). Falls back to
     * any frames when none are marked in-app.
     *
     * @param list<array<string, mixed>> $frames
     *
     * @return list<array<string, mixed>>
     */
    public static function topFrames(array $frames, int $n = self::TOP_FRAMES): array
    {
        $rev   = array_reverse(array_values(array_filter($frames, 'is_array')));
        $inApp = array_values(array_filter($rev, static fn ($f) => ($f['in_app'] ?? self::guessInApp($f)) === true));
        $use   = $inApp !== [] ? $inApp : $rev;

        return array_slice($use, 0, $n);
    }

    public static function guessInApp(array $f): bool
    {
        $file = (string) ($f['file'] ?? '');

        return ! preg_match('~node_modules|/vendor/|^native |^\[native|<anonymous>|chrome-extension://|moz-extension://|^webpack/bootstrap|/system/|^internal/~', $file);
    }

    public static function frameKey(array $f): string
    {
        $file = self::normalizeFile((string) ($f['file'] ?? ''));
        $fn   = self::normalizeFunction((string) ($f['function'] ?? ''));
        $mod  = (string) ($f['module'] ?? '');

        return trim(($mod !== '' ? $mod . ':' : '') . $file . ':' . $fn, ':');
    }

    /** Path without scheme/host/query, with content-hash suffixes removed. */
    public static function normalizeFile(string $file): string
    {
        $file = trim($file);
        if ($file === '') {
            return '';
        }
        $file = preg_replace('~^(?:webpack|app|file|capacitor|ionic)://+[^/]*~i', '', $file);
        $file = preg_replace('~^[a-z][a-z0-9+.-]*://[^/]+~i', '', $file);
        $file = substr($file, 0, strcspn($file, '?#'));
        $file = preg_replace('~^(?:\./|/)+~', '', $file);
        $file = preg_replace('~(?:^|/)\.\./~', '', $file);
        $file = str_replace('\\', '/', $file);
        $segs = explode('/', $file);
        foreach ($segs as $i => $seg) {
            if (preg_match('/^v?\d+\.\d+\.\d+(?:[+-][\w.]+)?$/', $seg) || preg_match('/^[0-9a-f]{8,}$/i', $seg)) {
                $segs[$i] = '*'; // versioned / hashed directories
                continue;
            }
            $segs[$i] = self::stripHash($seg);
        }

        return implode('/', array_filter($segs, static fn ($s) => $s !== '*'));
    }

    /** mail-8c1.js → mail.js; main.3f2a1b9c.chunk.js → main.chunk.js; index-BxT3k9aZ.js → index.js */
    public static function stripHash(string $name): string
    {
        return preg_replace_callback('/[-.~_]([A-Za-z0-9_]{3,})(?=(?:\.chunk|\.bundle|\.min)?\.(?:m?js|cjs|jsx|ts|tsx|css|map|bundle|hbc)$)/', static function (array $m) {
            $tok = $m[1];
            if (in_array(strtolower($tok), ['min', 'chunk', 'bundle', 'module', 'esm', 'umd', 'legacy', 'modern', 'vendor', 'runtime', 'polyfills', 'main', 'index', 'app'], true)) {
                return $m[0];
            }
            $hashy = preg_match('/\d/', $tok) || (strlen($tok) >= 8 && preg_match('/[a-z]/', $tok) && preg_match('/[A-Z]/', $tok));

            return $hashy ? '' : $m[0];
        }, $name) ?? $name;
    }

    public static function normalizeFunction(string $fn): string
    {
        $fn = trim($fn);
        $fn = preg_replace('/^(?:async\s+|new\s+|Object\.|Module\.|eval at\s+)/', '', $fn);
        $fn = preg_replace('/\s*\[as [^\]]+\]$/', '', $fn);
        $fn = preg_replace('/\+0x[0-9a-f]+$/i', '', $fn);        // native offsets
        $fn = preg_replace('/\(\d+\)$/', '', $fn);
        $fn = preg_replace('/<anonymous>|\?|<computed>/', '', $fn);
        $fn = preg_replace('/\$\d+$/', '', $fn);                  // closures {closure}$12
        $fn = preg_replace('/lambda\$\d+/', 'lambda', $fn);
        if (strlen($fn) <= 2 && ! preg_match('/^[A-Z]/', $fn)) {
            return ''; // minified name (r, t, e …) — unstable between builds
        }

        return $fn;
    }

    /** Message normalized for grouping: numbers, hex, uuids and quoted values replaced. */
    public static function normalizeMessage(string $msg): string
    {
        $msg = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '<uuid>', $msg);
        $msg = preg_replace('/0x[0-9a-f]+/i', '<hex>', $msg);
        $msg = preg_replace('/\b[0-9a-f]{12,}\b/i', '<hex>', $msg);
        $msg = preg_replace('/"[^"]{0,200}"|\'[^\']{0,200}\'|`[^`]{0,200}`/', '<str>', $msg);
        $msg = preg_replace('/\d+(?:\.\d+)?/', '<n>', $msg);

        return mb_substr(trim(preg_replace('/\s+/', ' ', $msg)), 0, 300);
    }

    /** @param list<array<string, mixed>> $top innermost first */
    public static function culprit(array $top): ?string
    {
        $f = $top[0] ?? null;
        if ($f === null) {
            return null;
        }
        $file = basename(self::normalizeFile((string) ($f['file'] ?? ''))) ?: (string) ($f['module'] ?? '');
        $fn   = (string) ($f['function'] ?? '');

        return mb_substr(trim($file . ($fn !== '' ? ' in ' . $fn : '')), 0, 255) ?: null;
    }

    /** @param list<array<string, mixed>> $top */
    private function applyRules(array $event, array $ex, array $top, string $default): ?string
    {
        foreach ($this->rules as $r) {
            if (isset($r['enabled']) && ! (int) $r['enabled']) {
                continue;
            }
            $val = (string) ($r['match_value'] ?? '');
            $hit = match ((string) ($r['match_type'] ?? '')) {
                'exception_type' => self::matches($val, (string) ($ex['type'] ?? '')),
                'message'        => self::matches($val, (string) ($ex['value'] ?? $event['message'] ?? '')),
                'type'           => self::matches($val, (string) ($event['type'] ?? '')),
                'file'           => array_filter($top, static fn ($f) => self::matches($val, (string) ($f['file'] ?? ''))) !== [],
                'function'       => array_filter($top, static fn ($f) => self::matches($val, (string) ($f['function'] ?? ''))) !== [],
                default          => false,
            };
            if ($hit) {
                return str_replace(['{{ default }}', '{{default}}'], $default, (string) $r['fingerprint']);
            }
        }

        return null;
    }

    private static function matches(string $pattern, string $subject): bool
    {
        if ($pattern === '') {
            return false;
        }
        if (strlen($pattern) > 2 && $pattern[0] === '/' && preg_match('~^/.*/[imsu]*$~s', $pattern)) {
            return (bool) @preg_match($pattern, $subject);
        }

        return fnmatch($pattern, $subject, FNM_CASEFOLD) || fnmatch('*' . $pattern . '*', $subject, FNM_CASEFOLD) && ! str_contains($pattern, '*');
    }
}

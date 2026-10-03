<?php

namespace App\Libraries\BugTrace;

/**
 * Server-side PII / secret scrubbing for Bug Trace events and reports
 * (§18.8, FR-BT-ING-03, NFR-C20). Runs before anything is stored.
 *
 * - Values under sensitive keys (password, token, authorization, cookie,
 *   X-WS-*, card/iban/nic …) → "[Filtered]"; business-content keys (body,
 *   subject, input values …) → "[Filtered]"; IP keys are removed.
 * - String patterns: private keys, JWT, bearer/basic credentials, key=value
 *   secrets, well-known API key formats, e-mail, card numbers (Luhn), IBAN
 *   (mod-97), Sri Lankan NIC (old 9 digits + V/X, new 12 digits), bank account
 *   numbers, phone numbers (+94 / local / international), IPv4/IPv6 and
 *   high-entropy tokens; custom rules from bt_scrub_rules.
 * - URLs lose query strings and fragments; numeric / UUID / hash path
 *   segments are templated (/mail/folders/88 → /mail/folders/:id).
 *
 * Every replacement increments the counter reported as scrub_count.
 */
final class Scrubber
{
    public const FILTERED = '[Filtered]';

    private const MAX_STRING = 8192;
    private const MAX_ITEMS  = 100;
    private const MAX_DEPTH  = 8;

    private const SENSITIVE_KEY = '/^(?:pass(?:word|wd|phrase)?|pwd|secret|[a-z0-9_-]*_secret|client[_-]?secret|token|[a-z0-9_-]*[_-]token|access[_-]?token|refresh[_-]?token|id[_-]?token|auth|authorization|proxy-authorization|www-authenticate|cookie|cookies|set-cookie|session|sessionid|session[_-]?token|sessid|phpsessid|ci_session|uc_session|csrf[a-z0-9_-]*|xsrf[a-z0-9_-]*|x-csrf-token|x-xsrf-token|x-ws-[a-z0-9_-]*|x-mfa-token|x-api-key|x-bt-key|x-worksuite-tenant-policy|api[_-]?key|apikey|private[_-]?key|credit[_-]?card|card[_-]?number|cc[_-]?number|cardnumber|cvv|cvc|iban|account[_-]?number|bank[_-]?account|nic|nic[_-]?number|national[_-]?id|ssn|otp|totp|mfa[_-]?code|recovery[_-]?code)$/i';

    private const CONTENT_KEY = '/^(?:body|request[_-]?body|raw[_-]?body|post[_-]?data|form[_-]?data|formdata|input|input[_-]?value|value[_-]?typed|clipboard|keystrokes?|mail[_-]?body|message[_-]?body|html[_-]?body|text[_-]?body|subject|mail[_-]?subject|content|file[_-]?content|file[_-]?contents|attachment[_-]?name|file[_-]?name|filename|sql|sql[_-]?bindings|bindings|query[_-]?bindings|params|parameters)$/i';

    private const IP_KEY = '/^(?:ip|ip[_-]?address|remote[_-]?addr|client[_-]?ip|x-forwarded-for|x-real-ip|cf-connecting-ip|true-client-ip|forwarded)$/i';

    private const URL_KEY = '/^(?:url|uri|path|to|from|href|location|referrer|referer|endpoint|page|abs_path|current|previous|request_uri)$/i';

    /** Breadcrumb data keys that are allowed through (FR-BT-SDK-06). */
    private const BREADCRUMB_DATA = ['method', 'path', 'url', 'status', 'status_code', 'ms', 'duration', 'from', 'to', 'route', 'label', 'target', 'level', 'reason', 'name', 'action', 'area', 'state', 'code', 'error_code', 'count', 'online', 'visibility', 'kind'];

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * @param list<array{pattern:string, replacement?:string|null}> $customRules regex patterns (with or without delimiters)
     */
    public function __construct(private readonly array $customRules = [])
    {
    }

    public function count(): int
    {
        return array_sum($this->counts);
    }

    /** @return array<string, int> category => replacements */
    public function summary(): array
    {
        ksort($this->counts);

        return $this->counts;
    }

    public function reset(): void
    {
        $this->counts = [];
    }

    private function hit(string $category, int $n = 1): void
    {
        if ($n > 0) {
            $this->counts[$category] = ($this->counts[$category] ?? 0) + $n;
        }
    }

    // ------------------------------------------------------------------ strings

    public function scrubString(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        if (strlen($s) > self::MAX_STRING) {
            $s = mb_strcut($s, 0, self::MAX_STRING) . '…';
        }
        $f = self::FILTERED;

        $s = $this->replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s', $f, $s, 'token');
        $s = $this->replace('/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/', $f, $s, 'token');
        $s = $this->replace('/\b(Bearer|Basic|Token|Digest)\s+[A-Za-z0-9._~+\/=-]{6,}/i', '$1 ' . $f, $s, 'token');
        $s = $this->replace('/\b(pass(?:word|wd|phrase)?|pwd|secret|client[_-]?secret|token|access[_-]?token|refresh[_-]?token|api[_-]?key|apikey|session[_-]?(?:id|token)|otp)((?:["\']?\s*[:=]\s*["\']?)|\s+is\s+)([^\s"\'&,;}\]<>]+)/i', '$1$2' . $f, $s, 'password');
        foreach ([
            '/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{10,}\b/',
            '/\bAKIA[0-9A-Z]{16}\b/',
            '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/',
            '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',
            '/\bAIza[0-9A-Za-z_-]{35}\b/',
            '/\bwsk_[A-Za-z0-9_-]{16,}\b/',
        ] as $re) {
            $s = $this->replace($re, $f, $s, 'token');
        }
        $s = $this->replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', $f, $s, 'email');
        $s = $this->replace('~(https?://[^\s?#"\'<>]+)[?#][^\s"\'<>]*~i', '$1', $s, 'query');

        $s = $this->callback('/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){2,7}(?:[ ]?[A-Z0-9]{1,4})?\b/', $s, 'iban', static fn (string $m) => self::ibanValid($m));
        $s = $this->callback('/(?<![\d.])(?:\d[ -]?){12,18}\d(?![\d.])/', $s, 'card', static function (string $m): bool {
            $d = preg_replace('/\D/', '', $m);

            return strlen($d) >= 13 && strlen($d) <= 19 && in_array($d[0], ['2', '3', '4', '5', '6'], true) && self::luhn($d);
        });
        $s = $this->replace('/(?<![\d.])(?:19|20)\d{10}(?![\d.])/', $f, $s, 'nic');
        $s = $this->replace('/(?<![\w.])\d{9}[VvXx](?!\w)/', $f, $s, 'nic');
        $s = $this->replace('/\b(account|acct|a\/c|acc)(\.?\s*(?:no\.?|number|num|#)?\s*[:#-]?\s*)\d[\d -]{5,20}\d/i', '$1$2' . $f, $s, 'bank');

        $s = $this->callback('/(?<![\w+])\+\d[\d\s().-]{6,18}\d(?![\w])/', $s, 'phone', static function (string $m): bool {
            $n = strlen(preg_replace('/\D/', '', $m));

            return $n >= 8 && $n <= 15;
        });
        $s = $this->replace('/(?<![\w.+-])0(?:7\d|1\d|[2-9]\d)[\s-]?\d{3}[\s-]?\d{4}(?![\w.])/', $f, $s, 'phone');

        $s = $this->callback('/(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])/', $s, 'ip', static function (string $m): bool {
            foreach (explode('.', $m) as $o) {
                if ((int) $o > 255 || (strlen($o) > 1 && $o[0] === '0')) {
                    return false;
                }
            }

            return true;
        });
        $s = $this->callback('/(?<![0-9A-Za-z:.])(?:(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}|(?:[0-9a-f]{1,4}:){1,7}:(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?|::(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6}))(?![0-9A-Za-z:])/i', $s, 'ip', static fn (string $m) => filter_var($m, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false);

        // High-entropy tokens: ≥32 chars mixing upper, lower and digits.
        $s = $this->replace('/\b(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[a-z])(?=[A-Za-z0-9_\-]*[A-Z])[A-Za-z0-9_\-]{32,}\b/', $f, $s, 'token');

        foreach ($this->customRules as $rule) {
            $re = self::delimit((string) $rule['pattern']);
            if ($re !== null) {
                $s = $this->replace($re, (string) ($rule['replacement'] ?? $f) ?: $f, $s, 'custom');
            }
        }

        return $s;
    }

    private function replace(string $re, string $with, string $s, string $category): string
    {
        $out = @preg_replace($re, $with, $s, -1, $n);
        if ($out === null) {
            return $s;
        }
        $this->hit($category, $n);

        return $out;
    }

    /** @param callable(string):bool $accept */
    private function callback(string $re, string $s, string $category, callable $accept): string
    {
        $out = @preg_replace_callback($re, function (array $m) use ($accept, $category) {
            if ($accept($m[0])) {
                $this->hit($category);

                return self::FILTERED;
            }

            return $m[0];
        }, $s);

        return $out ?? $s;
    }

    private static function delimit(string $pattern): ?string
    {
        if ($pattern === '') {
            return null;
        }
        $re = preg_match('/^([\/#~!%@]).*\1[imsxuU]*$/s', $pattern) ? $pattern : '/' . str_replace('/', '\/', $pattern) . '/';

        return @preg_match($re, '') === false ? null : $re;
    }

    // --------------------------------------------------------------- structures

    public function scrubValue(mixed $v, string $key = '', int $depth = 0): mixed
    {
        if ($key !== '' && preg_match(self::SENSITIVE_KEY, $key)) {
            if ($v === null || $v === '' || is_bool($v)) {
                return $v;
            }
            $this->hit(str_contains(strtolower($key), 'cookie') ? 'cookie' : (preg_match('/^(authorization|x-ws-|proxy-auth)/i', $key) ? 'header' : 'password'));

            return self::FILTERED;
        }
        if ($key !== '' && preg_match(self::CONTENT_KEY, $key)) {
            if ($v === null || $v === '' || $v === [] || is_bool($v) || is_int($v)) {
                return $v;
            }
            $this->hit('content');

            return self::FILTERED;
        }
        if (is_string($v)) {
            if ($key !== '' && preg_match(self::URL_KEY, $key)) {
                return $this->scrubUrl($v);
            }

            return $this->scrubString($v);
        }
        if (is_array($v)) {
            if ($depth >= self::MAX_DEPTH) {
                return self::FILTERED;
            }
            $out = [];
            $i   = 0;
            foreach ($v as $k => $item) {
                if (++$i > self::MAX_ITEMS) {
                    break;
                }
                if (is_string($k) && preg_match(self::IP_KEY, $k)) {
                    $this->hit('ip');

                    continue;
                }
                $out[$k] = $this->scrubValue($item, is_string($k) ? $k : $key, $depth + 1);
            }

            return $out;
        }

        return is_float($v) || is_int($v) || is_bool($v) || $v === null ? $v : null;
    }

    /** Strip query/fragment, template numeric/UUID/hash segments, scrub what remains. */
    public function scrubUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        $cut = strcspn($url, '?#');
        if ($cut < strlen($url)) {
            $url = substr($url, 0, $cut);
            $this->hit('query');
        }
        if (preg_match('~^([a-z][a-z0-9+.-]*://[^/]*)(/.*)?$~i', $url, $m)) {
            $host = $m[1];
            if (str_contains($host, '@')) { // credentials in URL
                $host = preg_replace('~//[^@/]*@~', '//', $host);
                $this->hit('password');
            }

            return $host . self::templatePath($m[2] ?? '');
        }

        return $this->scrubString(self::templatePath($url));
    }

    public static function templatePath(string $path): string
    {
        if ($path === '' || ! str_contains($path, '/')) {
            return $path;
        }
        $parts = explode('/', $path);
        foreach ($parts as $i => $seg) {
            if ($seg === '') {
                continue;
            }
            if (preg_match('/^\d+$/', $seg)) {
                $parts[$i] = ':id';
            } elseif (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $seg)) {
                $parts[$i] = ':uuid';
            } elseif (preg_match('/^(?:[a-z]{2,5}_)?[0-9A-HJKMNP-TV-Z]{26}$/', $seg) || preg_match('/^[0-9a-f]{16,}$/i', $seg)) {
                $parts[$i] = ':id';
            } elseif (preg_match('/^[^@\/]+@[^@\/]+\.[a-z]{2,}$/i', $seg)) {
                $parts[$i] = ':email';
            }
        }

        return implode('/', $parts);
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    public function scrubHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $k = (string) $k;
            if (preg_match('/^(authorization|proxy-authorization|cookie|set-cookie|x-ws-.*|x-csrf-token|x-xsrf-token|x-mfa-token|x-api-key|x-bt-key|x-worksuite-tenant-policy)$/i', $k)) {
                $this->hit(stripos($k, 'cookie') !== false ? 'cookie' : 'header');
                $out[$k] = self::FILTERED;

                continue;
            }
            if (preg_match(self::IP_KEY, $k)) {
                $this->hit('ip');

                continue;
            }
            if (! preg_match('/^(user-agent|accept|accept-language|content-type|content-length|referer|origin|x-request-id|sec-ch-ua.*)$/i', $k)) {
                continue; // unknown headers are not kept
            }
            $out[$k] = strtolower($k) === 'referer' || strtolower($k) === 'origin' ? $this->scrubUrl((string) (is_array($v) ? implode(',', $v) : $v)) : $this->scrubString((string) (is_array($v) ? implode(',', $v) : $v));
        }

        return $out;
    }

    /**
     * Scrub one event envelope into the shape Bug Trace stores. Unknown top-level
     * keys are dropped; identity fields (event_id, user_ref, installation_ref …)
     * are pseudonymous by contract and kept as-is (length-limited).
     *
     * @param array<string, mixed> $e
     *
     * @return array<string, mixed>
     */
    public function scrubEvent(array $e): array
    {
        $out = [];
        foreach (['event_id', 'type', 'app', 'platform', 'release', 'environment', 'installation_ref', 'edition', 'user_ref', 'session_id', 'timestamp', 'level', 'sample_rate', 'dist', 'logger'] as $k) {
            if (isset($e[$k]) && is_scalar($e[$k])) {
                $out[$k] = is_string($e[$k]) ? mb_substr($e[$k], 0, 200) : $e[$k];
            }
        }
        if (isset($e['message'])) {
            $msg = is_array($e['message']) ? (string) ($e['message']['formatted'] ?? $e['message']['message'] ?? '') : (string) $e['message'];
            $out['message'] = $this->scrubString(mb_substr($msg, 0, 2000));
        }

        $exceptions = $e['exception'] ?? [];
        if (isset($exceptions['values']) && is_array($exceptions['values'])) {
            $exceptions = $exceptions['values']; // Sentry shape
        }
        if (is_array($exceptions) && isset($exceptions['type'])) {
            $exceptions = [$exceptions];
        }
        $out['exception'] = [];
        foreach (array_slice(is_array($exceptions) ? array_values($exceptions) : [], 0, 10) as $ex) {
            if (! is_array($ex)) {
                continue;
            }
            $item = [
                'type'  => $this->scrubString(mb_substr((string) ($ex['type'] ?? 'Error'), 0, 200)),
                'value' => $this->scrubString(mb_substr((string) ($ex['value'] ?? ''), 0, 2000)),
            ];
            if (isset($ex['module'])) {
                $item['module'] = mb_substr((string) $ex['module'], 0, 200);
            }
            if (isset($ex['mechanism']) && is_array($ex['mechanism'])) {
                $item['mechanism'] = array_intersect_key($ex['mechanism'], array_flip(['type', 'handled', 'synthetic']));
            }
            if (isset($ex['signal'])) {
                $item['signal'] = mb_substr((string) $ex['signal'], 0, 64);
            }
            $frames = $ex['stacktrace']['frames'] ?? [];
            $item['stacktrace'] = ['frames' => $this->scrubFrames(is_array($frames) ? $frames : [])];
            $out['exception'][] = $item;
        }

        $contexts        = is_array($e['contexts'] ?? null) ? $e['contexts'] : [];
        $out['contexts'] = $this->scrubValue($contexts, '', 0);
        if (isset($e['request']) && is_array($e['request'])) {
            $req = $e['request'];
            foreach (['data', 'body', 'cookies', 'query_string', 'env'] as $drop) {
                if (! empty($req[$drop])) {
                    $this->hit($drop === 'cookies' ? 'cookie' : ($drop === 'query_string' ? 'query' : 'content'));
                }
            }
            $out['contexts']['request'] = array_filter([
                'method'  => isset($req['method']) ? strtoupper(substr((string) $req['method'], 0, 10)) : null,
                'url'     => isset($req['url']) ? $this->scrubUrl((string) $req['url']) : null,
                'route'   => isset($req['route']) ? $this->scrubUrl((string) $req['route']) : null,
                'headers' => isset($req['headers']) && is_array($req['headers']) ? $this->scrubHeaders($req['headers']) : null,
            ], static fn ($v) => $v !== null && $v !== []);
        }
        if (isset($e['user']) && is_array($e['user'])) {
            foreach (['email', 'ip_address', 'username', 'name'] as $k) {
                if (! empty($e['user'][$k])) {
                    $this->hit($k === 'ip_address' ? 'ip' : ($k === 'email' ? 'email' : 'content'));
                }
            }
            if (! isset($out['user_ref']) && isset($e['user']['id']) && is_scalar($e['user']['id']) && preg_match('/^u_hmac:|^hmac:/', (string) $e['user']['id'])) {
                $out['user_ref'] = mb_substr((string) $e['user']['id'], 0, 128);
            }
        }

        $crumbs = is_array($e['breadcrumbs'] ?? null) ? ($e['breadcrumbs']['values'] ?? $e['breadcrumbs']) : [];
        $out['breadcrumbs'] = [];
        foreach (array_slice(array_values(is_array($crumbs) ? $crumbs : []), -50) as $b) {
            if (! is_array($b)) {
                continue;
            }
            $crumb = [
                't'        => (string) ($b['t'] ?? $b['timestamp'] ?? ''),
                'category' => mb_substr((string) ($b['category'] ?? 'default'), 0, 64),
            ];
            foreach (['type', 'level'] as $k) {
                if (isset($b[$k]) && is_string($b[$k])) {
                    $crumb[$k] = mb_substr($b[$k], 0, 32);
                }
            }
            if (isset($b['message']) && is_scalar($b['message'])) {
                $crumb['message'] = $this->scrubString(mb_substr((string) $b['message'], 0, 500));
            }
            if (isset($b['data']) && is_array($b['data'])) {
                $data = [];
                foreach ($b['data'] as $k => $v) {
                    if (! in_array($k, self::BREADCRUMB_DATA, true) || is_array($v)) {
                        $this->hit('content');

                        continue;
                    }
                    $data[$k] = $this->scrubValue($v, (string) $k, 1);
                }
                $crumb['data'] = $data;
            }
            $out['breadcrumbs'][] = $crumb;
        }

        $tags = [];
        foreach (array_slice(is_array($e['tags'] ?? null) ? $e['tags'] : [], 0, 50, true) as $k => $v) {
            if (is_scalar($v)) {
                $tags[mb_substr((string) $k, 0, 64)] = $this->scrubValue(mb_substr((string) $v, 0, 200), (string) $k, 1);
            }
        }
        $out['tags'] = $tags;
        if (isset($e['extra']) && is_array($e['extra'])) {
            $out['extra'] = $this->scrubValue($e['extra'], '', 1);
        }
        $out['attachments'] = [];
        foreach (array_slice(is_array($e['attachments'] ?? null) ? $e['attachments'] : [], 0, 10) as $a) {
            if (is_array($a) && isset($a['upload_id'])) {
                $out['attachments'][] = ['kind' => (string) ($a['kind'] ?? 'file'), 'upload_id' => (string) $a['upload_id']];
            }
        }
        if (isset($e['debug_meta']['images']) && is_array($e['debug_meta']['images'])) {
            foreach (array_slice($e['debug_meta']['images'], 0, 200) as $img) {
                if (is_array($img)) {
                    $out['debug_meta']['images'][] = array_filter([
                        'type'      => $img['type'] ?? null,
                        'debug_id'  => $img['debug_id'] ?? null,
                        'code_id'   => $img['code_id'] ?? null,
                        'code_file' => isset($img['code_file']) ? $this->scrubUrl((string) $img['code_file']) : null,
                    ], static fn ($v) => $v !== null);
                }
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $frames
     *
     * @return list<array<string, mixed>>
     */
    public function scrubFrames(array $frames): array
    {
        $out = [];
        // Keep the 100 frames nearest to the crash (frames are ordered outermost → innermost).
        foreach (array_slice(array_values($frames), -100) as $fr) {
            if (! is_array($fr)) {
                continue;
            }
            $f = [];
            foreach (['file', 'filename', 'abs_path'] as $k) {
                if (isset($fr[$k]) && is_string($fr[$k])) {
                    $f['file'] ??= $this->scrubUrl(mb_substr($fr[$k], 0, 500));
                }
            }
            foreach (['function', 'module', 'package', 'instruction_addr', 'module_offset', 'symbol_addr', 'image_addr', 'debug_id', 'platform'] as $k) {
                if (isset($fr[$k]) && is_scalar($fr[$k])) {
                    $f[$k] = mb_substr((string) $fr[$k], 0, 300);
                }
            }
            $line = $fr['line'] ?? $fr['lineno'] ?? null;
            $col  = $fr['col'] ?? $fr['colno'] ?? $fr['column'] ?? null;
            if (is_numeric($line)) {
                $f['line'] = (int) $line;
            }
            if (is_numeric($col)) {
                $f['col'] = (int) $col;
            }
            if (isset($fr['in_app'])) {
                $f['in_app'] = (bool) $fr['in_app'];
            }
            if (! empty($fr['vars']) || ! empty($fr['pre_context']) || ! empty($fr['context_line'])) {
                // Local variables / client-side source lines are never kept.
                if (! empty($fr['vars'])) {
                    $this->hit('content');
                }
            }
            $out[] = $f;
        }

        return $out;
    }

    // ------------------------------------------------------------------ checks

    public static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }

    public static function ibanValid(string $iban): bool
    {
        $iban = strtoupper(str_replace(' ', '', $iban));
        if (strlen($iban) < 15 || strlen($iban) > 34 || ! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban)) {
            return false;
        }
        $moved = substr($iban, 4) . substr($iban, 0, 4);
        $num   = '';
        foreach (str_split($moved) as $ch) {
            $num .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $rem = 0;
        foreach (str_split($num, 7) as $chunk) {
            $rem = (int) ($rem . $chunk) % 97;
        }

        return $rem === 1;
    }
}

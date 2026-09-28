<?php

declare(strict_types=1);

namespace Modules\Core\Libraries;

use Throwable;

/**
 * WorkSuite tenant server → Update Center Bug Trace reporter (SRS §18.7, WF-C34,
 * FR-BT-SDK-04, AC-C63).
 *
 * capture() never blocks a request: it builds a scrubbed event and LPUSHes it
 * onto a Redis list (spool-file fallback). `php spark bugtrace:flush`
 * (cron every minute) sends batches to POST /api/v1/bugtrace/envelope.
 *
 * Never sent: request bodies, query strings, headers, cookies, SQL statements'
 * bound values, function arguments or local variables. Identifiers are HMAC
 * pseudonyms keyed by the tenant secret.
 */
final class BugTraceReporter
{
    public const QUEUE_KEY = 'bugtrace:queue';
    public const MAX_QUEUE = 1000;
    public const BATCH     = 50;
    public const FILTERED  = '[Filtered]';

    /** @var null|callable(): ?string */
    private $tptProvider;

    /** @var null|callable(string, array<string,string>, string): int transport(url, headers, body) → HTTP status */
    private $transport;

    private ?\Redis $redis;

    /**
     * @param array{
     *   endpoint?: string, key: string, release: string, environment?: string, app?: string,
     *   installation_id?: string, tenant_secret: string, edition?: string, root_path?: string,
     *   spool_file?: string, policy?: array<string, mixed>|null
     * } $config
     */
    public function __construct(private array $config, ?\Redis $redis = null, ?callable $tptProvider = null, ?callable $transport = null)
    {
        $this->config += ['endpoint' => 'https://updates.worksuitecloud.com', 'environment' => 'production', 'app' => 'lk.worksuite.server', 'edition' => null, 'root_path' => '', 'spool_file' => sys_get_temp_dir() . '/worksuite-bugtrace.spool', 'policy' => null];
        $this->redis       = $redis;
        $this->tptProvider = $tptProvider;
        $this->transport   = $transport;
    }

    // ---------------------------------------------------------------- policy

    /** Effective policy: explicit config, else the `bugtrace` claim of the current TPT, else CRASHES_ONLY. */
    public function policy(): array
    {
        $p = $this->config['policy'];
        if ($p === null && $this->tptProvider !== null) {
            $p = self::claimFromTpt(($this->tptProvider)());
        }

        return ($p ?? []) + ['policy' => 'CRASHES_ONLY', 'server_reporting' => true];
    }

    public static function claimFromTpt(?string $tpt): ?array
    {
        if ($tpt === null || $tpt === '') {
            return null;
        }
        $json = str_starts_with(ltrim($tpt), '{') ? $tpt : base64_decode(strtr($tpt, '-_', '+/'), false);
        $doc  = json_decode((string) $json, true);

        return is_array($doc['payload']['bugtrace'] ?? null) ? $doc['payload']['bugtrace'] : null;
    }

    private function allowed(string $type): bool
    {
        $p = $this->policy();
        if (empty($p['server_reporting']) || ($p['policy'] ?? 'OFF') === 'OFF') {
            return false;
        }

        return $p['policy'] === 'CRASHES_AND_ERRORS' || in_array($type, ['crash', 'hang'], true);
    }

    // --------------------------------------------------------------- capture

    /**
     * Queue an exception. $context may contain: area (module), route (route
     * pattern, e.g. "mail/folders/(:num)"), method, command (spark command
     * name), user_id (pseudonymised here), fatal (bool), tags.
     *
     * Unhandled exceptions and fatal errors are "crash" events (the request or
     * job failed); handled ones reported explicitly are "error".
     */
    public function capture(Throwable $e, array $context = []): ?string
    {
        try {
            $type = ($context['handled'] ?? false) ? 'error' : 'crash';
            if (! $this->allowed($type)) {
                return null;
            }
            $event = $this->buildEvent($e, $type, $context);
            $this->enqueue($event);

            return $event['event_id'];
        } catch (Throwable) {
            return null; // reporting must never break the tenant server
        }
    }

    /** Register a shutdown function so fatal errors are captured too. */
    public function registerFatalHandler(): void
    {
        register_shutdown_function(function (): void {
            $err = error_get_last();
            if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                $this->capture(new \ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']), ['fatal' => true]);
            }
        });
    }

    /** @return array<string, mixed> */
    public function buildEvent(Throwable $e, string $type, array $context = []): array
    {
        $exceptions = [];
        for ($x = $e, $depth = 0; $x !== null && $depth < 5; $x = $x->getPrevious(), $depth++) {
            array_unshift($exceptions, [
                'type'       => $x::class,
                'value'      => self::scrubMessage($x->getMessage()),
                'mechanism'  => ['type' => ! empty($context['command']) ? 'spark' : 'php', 'handled' => (bool) ($context['handled'] ?? false)],
                'stacktrace' => ['frames' => $this->frames($x)],
            ]);
        }
        $secret = (string) $this->config['tenant_secret'];

        return [
            'event_id'         => bin2hex(random_bytes(16)),
            'type'             => $type,
            'app'              => $this->config['app'],
            'platform'         => 'server',
            'release'          => $this->config['release'],
            'environment'      => $this->config['environment'],
            'installation_ref' => isset($this->config['installation_id']) ? 'inst_hmac:' . substr(hash_hmac('sha256', (string) $this->config['installation_id'], $secret), 0, 24) : null,
            'user_ref'         => isset($context['user_id']) ? 'u_hmac:' . substr(hash_hmac('sha256', 'user:' . $context['user_id'], $secret), 0, 24) : null,
            'edition'          => $this->config['edition'],
            'timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'level'            => ! empty($context['fatal']) ? 'fatal' : 'error',
            'exception'        => $exceptions,
            'contexts'         => [
                'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
                'os'      => ['name' => PHP_OS_FAMILY],
                'app'     => array_filter([
                    'area'    => $context['area'] ?? null,
                    'route'   => isset($context['route']) ? self::stripQuery((string) $context['route']) : null,
                    'method'  => $context['method'] ?? null,
                    'command' => $context['command'] ?? null,
                ]),
            ],
            'tags'             => array_map(static fn ($v) => self::scrubMessage(substr((string) $v, 0, 200)), (array) ($context['tags'] ?? [])),
            'breadcrumbs'      => [],
        ];
    }

    /** @return list<array<string, mixed>> outermost → innermost, without arguments */
    private function frames(Throwable $e): array
    {
        $root   = rtrim((string) $this->config['root_path'], '/') . '/';
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine(), 'function' => null]];
        foreach ($e->getTrace() as $t) { // 'args' are never read
            $frames[] = ['file' => $t['file'] ?? '[internal]', 'line' => $t['line'] ?? null, 'function' => isset($t['class']) ? $t['class'] . ($t['type'] ?? '::') . $t['function'] : ($t['function'] ?? null)];
        }
        // getTrace(): [0] = innermost caller. Shift function names so each frame names its own function.
        $out = [];
        foreach ($frames as $i => $f) {
            $fn   = $frames[$i + 1]['function'] ?? '{main}';
            $file = $root !== '/' && str_starts_with((string) $f['file'], $root) ? substr((string) $f['file'], strlen($root)) : basename((string) $f['file']);
            $out[] = ['file' => $file, 'line' => $f['line'], 'function' => $fn, 'in_app' => ! str_contains($file, 'vendor/') && ! str_starts_with($file, 'system/')];
        }

        return array_reverse(array_slice($out, 0, 60));
    }

    /**
     * Removes SQL literals (bound values), query strings and personal data
     * from exception messages. "Duplicate entry 'a@b.lk' for key…" → "Duplicate entry '?' for key…".
     */
    public static function scrubMessage(string $m): string
    {
        $m = substr($m, 0, 2000);
        if (preg_match('/\b(select|insert|update|delete|replace|values|where|duplicate entry|constraint|sqlstate)\b/i', $m)) {
            $m = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "'?'", $m);
            $m = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '"?"', $m);
            $m = preg_replace('/(?<![\w.])-?\d+(?:\.\d+)?(?![\w.])/', '?', $m);
        }
        $m = preg_replace('~(https?://[^\s?#"\']+)[?#][^\s"\']*~i', '$1', $m);
        $m = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', self::FILTERED, $m);
        $m = preg_replace('/\beyJ[\w-]{5,}\.[\w-]{5,}\.[\w-]*/', self::FILTERED, $m);
        $m = preg_replace('/\b(Bearer|Basic)\s+[\w.~+\/=-]{6,}/i', '$1 ' . self::FILTERED, $m);
        $m = preg_replace('/\b(pass(?:word|wd)?|pwd|secret|token|api[_-]?key)(["\']?\s*[:=]\s*["\']?)[^\s"\'&,;]+/i', '$1$2' . self::FILTERED, $m);
        $m = preg_replace('/(?<![\w.])(?:\d{9}[VvXx]|(?:19|20)\d{10})(?!\w)/', self::FILTERED, $m);
        $m = preg_replace('/(?<![\w+])\+\d[\d\s-]{7,16}\d\b|(?<![\w.])0\d{2}[\s-]?\d{3}[\s-]?\d{4}\b/', self::FILTERED, $m);

        return (string) $m;
    }

    private static function stripQuery(string $s): string
    {
        return substr($s, 0, strcspn($s, '?#'));
    }

    // ------------------------------------------------------------------ queue

    /** @param array<string, mixed> $event */
    private function enqueue(array $event): void
    {
        $json = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($this->redis !== null) {
            try {
                $this->redis->lPush(self::QUEUE_KEY, $json);
                $this->redis->lTrim(self::QUEUE_KEY, 0, self::MAX_QUEUE - 1);

                return;
            } catch (Throwable) {
                // fall back to the spool file
            }
        }
        $f = (string) $this->config['spool_file'];
        if (! is_file($f) || filesize($f) < 5 * 1024 * 1024) {
            file_put_contents($f, $json . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /** @return list<string> */
    private function take(int $n): array
    {
        $out = [];
        if ($this->redis !== null) {
            try {
                for ($i = 0; $i < $n; $i++) {
                    $v = $this->redis->rPop(self::QUEUE_KEY);
                    if (! is_string($v)) {
                        break;
                    }
                    $out[] = $v;
                }
            } catch (Throwable) {
            }
        }
        $f = (string) $this->config['spool_file'];
        if (count($out) < $n && is_file($f) && ($h = fopen($f, 'c+'))) {
            flock($h, LOCK_EX);
            $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($h))));
            $need  = $n - count($out);
            $out   = array_merge($out, array_slice($lines, 0, $need));
            $rest  = array_slice($lines, $need);
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, $rest === [] ? '' : implode("\n", $rest) . "\n");
            flock($h, LOCK_UN);
            fclose($h);
        }

        return $out;
    }

    public function queued(): int
    {
        $n = 0;
        if ($this->redis !== null) {
            try {
                $n += (int) $this->redis->lLen(self::QUEUE_KEY);
            } catch (Throwable) {
            }
        }
        $f = (string) $this->config['spool_file'];

        return $n + (is_file($f) ? count(array_filter(explode("\n", (string) file_get_contents($f)))) : 0);
    }

    // ------------------------------------------------------------------ flush

    /**
     * Send queued events in batches (spark bugtrace:flush). Returns counts.
     *
     * @return array{sent: int, dropped: int, requeued: int}
     */
    public function flush(int $maxBatches = 20): array
    {
        $res = ['sent' => 0, 'dropped' => 0, 'requeued' => 0];
        for ($b = 0; $b < $maxBatches; $b++) {
            $items = $this->take(self::BATCH);
            if ($items === []) {
                break;
            }
            $events = [];
            foreach ($items as $raw) {
                $e = json_decode($raw, true);
                if (! is_array($e) || ! $this->allowed((string) ($e['type'] ?? 'error'))) {
                    $res['dropped']++; // policy changed to OFF: queued events are discarded (WF-C38)
                    continue;
                }
                $events[] = $e;
            }
            if ($events === []) {
                continue;
            }
            $status = $this->send(['events' => $events]);
            if ($status >= 200 && $status < 300) {
                $res['sent'] += count($events);
            } elseif ($status === 0 || $status === 429 || $status >= 500) {
                foreach ($events as $e) {
                    $e['_attempts'] = ($e['_attempts'] ?? 0) + 1;
                    if ($e['_attempts'] <= 5) {
                        $this->enqueue($e);
                        $res['requeued']++;
                    } else {
                        $res['dropped']++;
                    }
                }
                break; // Update Center unavailable — try again next minute
            } else {
                $res['dropped'] += count($events); // permanent rejection (401/413/…)
            }
        }

        return $res;
    }

    /** @param array<string, mixed> $envelope */
    private function send(array $envelope): int
    {
        foreach ($envelope['events'] as &$e) {
            unset($e['_attempts']);
        }
        unset($e);
        $body    = (string) gzencode((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 6);
        $url     = rtrim((string) $this->config['endpoint'], '/') . '/api/v1/bugtrace/envelope';
        $headers = ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip', 'X-BT-Key' => (string) $this->config['key'], 'User-Agent' => 'WorkSuite-BugTrace-PHP/1.0'];
        $tpt     = $this->tptProvider !== null ? ($this->tptProvider)() : null;
        if ($tpt) {
            $headers['X-WorkSuite-Tenant-Policy'] = $tpt;
        }
        if ($this->transport !== null) {
            return (int) ($this->transport)($url, $headers, $body);
        }
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $lines, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return $code;
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $lines), 'content' => $body, 'timeout' => 10, 'ignore_errors' => true]]);
        $r   = @file_get_contents($url, false, $ctx);
        if ($r === false || ! isset($http_response_header[0])) {
            return 0;
        }

        return preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
    }
}

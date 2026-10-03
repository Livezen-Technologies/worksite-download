<?php

namespace App\Libraries;

/**
 * Request metrics for the Prometheus exporter (§13.4, FR-UC-OPS-05).
 *
 * Cumulative counters and a latency histogram per route group, kept in one
 * Redis hash so every PHP-FPM worker and container contributes to the same
 * series. Without Redis (single-node dev, tests) they fall back to the cache.
 * Recording never throws: metrics must not break a request.
 */
class Metrics
{
    /** Histogram buckets in seconds (p95 alert threshold is 0.4 s). */
    public const BUCKETS = [0.05, 0.1, 0.2, 0.4, 0.8, 1.6, 5.0];

    private const KEY = 'uc:metrics';

    private static ?\Redis $redis = null;
    private static bool $redisTried = false;

    /** Route group for a request path; keeps label cardinality bounded. */
    public static function group(string $path): string
    {
        $p = '/' . ltrim($path, '/');

        return match (true) {
            str_starts_with($p, '/api/v1/admin')                                                   => 'admin',
            $p === '/api/v1/desktop/update'                                                        => 'update_check',
            $p === '/api/v1/server/update'                                                         => 'server_update_check',
            str_contains($p, '/manifest')                                                          => 'manifest',
            str_starts_with($p, '/download/')                                                      => 'resolver',
            str_ends_with($p, '/update-events'), str_starts_with($p, '/api/v1/device-versions')    => 'telemetry',
            str_starts_with($p, '/api/v1/bugtrace')                                                => 'bugtrace_ingest',
            str_starts_with($p, '/api/v1/auth')                                                    => 'auth',
            str_starts_with($p, '/api/v1/server')                                                  => 'server',
            str_starts_with($p, '/api/v1')                                                         => 'public_api',
            $p === '/metrics'                                                                      => 'metrics',
            default                                                                                => 'web',
        };
    }

    public static function record(string $group, int $status, float $seconds): void
    {
        try {
            $class  = intdiv(max(100, min(599, $status)), 100) . 'xx';
            $fields = ["req|{$group}|{$class}" => 1, "sum|{$group}" => (int) round($seconds * 1_000_000)];
            foreach (self::BUCKETS as $b) {
                if ($seconds <= $b) {
                    $fields["bkt|{$group}|{$b}"] = 1;
                }
            }
            self::incr($fields);
        } catch (\Throwable) {
            // never break the request
        }
    }

    /** Domain counters (e.g. signature verification failures). */
    public static function count(string $name, int $by = 1): void
    {
        try {
            self::incr(['cnt|' . $name => $by]);
        } catch (\Throwable) {
        }
    }

    /** @return array<string,int> raw fields */
    public static function snapshot(): array
    {
        $r = self::redis();
        if ($r !== null) {
            return array_map('intval', $r->hGetAll(self::KEY) ?: []);
        }

        return (array) (cache()->get('uc_metrics') ?? []);
    }

    public static function reset(): void
    {
        $r = self::redis();
        $r !== null ? $r->del(self::KEY) : cache()->delete('uc_metrics');
    }

    /** @param array<string,int> $fields */
    private static function incr(array $fields): void
    {
        $r = self::redis();
        if ($r !== null) {
            $tx = $r->multi(\Redis::PIPELINE);
            foreach ($fields as $f => $by) {
                $tx->hIncrBy(self::KEY, $f, $by);
            }
            $tx->exec();

            return;
        }
        $all = (array) (cache()->get('uc_metrics') ?? []);
        foreach ($fields as $f => $by) {
            $all[$f] = ($all[$f] ?? 0) + $by;
        }
        cache()->save('uc_metrics', $all, 0);
    }

    private static function redis(): ?\Redis
    {
        if (! self::$redisTried) {
            self::$redisTried = true;
            $host = env('redis.host');
            if ($host && class_exists(\Redis::class)) {
                try {
                    $r = new \Redis();
                    $r->connect($host, (int) env('redis.port', 6379), 1.0);
                    if ($pw = SecretStore::get(env('redis.passwordRef', ''))) {
                        $r->auth($pw);
                    }
                    self::$redis = $r;
                } catch (\Throwable) {
                    self::$redis = null;
                }
            }
        }

        return self::$redis;
    }
}

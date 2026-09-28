<?php

namespace App\Libraries;

use App\Exceptions\ApiException;

/**
 * Sliding-window rate limiter (§10.7). Uses Redis sorted sets when available,
 * otherwise the rate_limit_hits table (single-node dev).
 */
class RateLimiter
{
    private ?\Redis $redis = null;

    public function __construct()
    {
        $host = env('redis.host');
        if ($host && class_exists(\Redis::class)) {
            try {
                $r = new \Redis();
                $r->connect($host, (int) env('redis.port', 6379), 1.0);
                if ($pw = SecretStore::get(env('redis.passwordRef', ''))) {
                    $r->auth($pw);
                }
                $this->redis = $r;
            } catch (\Throwable) {
                $this->redis = null;
            }
        }
    }

    /** Returns remaining hits, or throws RATE_LIMITED with Retry-After. */
    public function hit(string $bucket, int $limit, int $windowSeconds, int $cost = 1): int
    {
        if (! config('UpdateCenter')->rateLimitEnabled) {
            return $limit;
        }
        $now   = (int) (microtime(true) * 1000);
        $start = $now - $windowSeconds * 1000;
        $key   = 'uc:rl:' . $bucket;

        if ($this->redis !== null) {
            $this->redis->zRemRangeByScore($key, '0', (string) $start);
            $count = (int) $this->redis->zCard($key);
            if ($count + $cost > $limit) {
                $oldest = $this->redis->zRange($key, 0, 0, true);
                $first  = $oldest ? (int) reset($oldest) : $now;
                $this->throw($first, $windowSeconds, $now);
            }
            for ($i = 0; $i < $cost; $i++) {
                $this->redis->zAdd($key, $now, $now . ':' . bin2hex(random_bytes(4)));
            }
            $this->redis->expire($key, $windowSeconds + 1);

            return $limit - $count - $cost;
        }

        $db = db_connect();
        if (random_int(1, 50) === 1) {
            $db->table('rate_limit_hits')->where('at <', $now - 86400 * 1000)->delete();
        }
        $count = $db->table('rate_limit_hits')->where('bucket', $key)->where('at >', $start)->countAllResults();
        if ($count + $cost > $limit) {
            $first = $db->table('rate_limit_hits')->selectMin('at')->where('bucket', $key)->where('at >', $start)->get()->getRow('at');
            $this->throw((int) ($first ?? $now), $windowSeconds, $now);
        }
        $rows = [];
        for ($i = 0; $i < $cost; $i++) {
            $rows[] = ['bucket' => $key, 'at' => $now];
        }
        $db->table('rate_limit_hits')->insertBatch($rows);

        return $limit - $count - $cost;
    }

    private function throw(int $firstMs, int $window, int $now): never
    {
        $retry = max(1, (int) ceil(($firstMs + $window * 1000 - $now) / 1000));

        throw new ApiException('RATE_LIMITED', 'Too many requests', 429, ['retry_after' => $retry], ['Retry-After' => (string) $retry]);
    }

    public function redis(): ?\Redis
    {
        return $this->redis;
    }
}

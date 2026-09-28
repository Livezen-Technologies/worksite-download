<?php

namespace App\Services;

use App\Libraries\Queue;
use App\Libraries\Storage\StorageFactory;

/**
 * System › Health (FR-UC-OPS-01/02): API, database, Redis, storage, CDN,
 * signing service and queue. Results are persisted in health_checks.
 */
class HealthService
{
    /** @return list<array{component:string,status:string,latency_ms:?int,detail:string,checked_at:string}> */
    public function run(bool $persist = true): array
    {
        $out   = [];
        $out[] = $this->timed('api', static fn () => [true, 'PHP ' . PHP_VERSION . ' · CodeIgniter ' . \CodeIgniter\CodeIgniter::CI_VERSION]);
        $out[] = $this->timed('database', static function () {
            $db = db_connect();
            $db->query('SELECT 1');

            return [true, $db->getPlatform() . ' ' . $db->getVersion()];
        });
        $out[] = $this->timed('redis', static function () {
            $r = service('rateLimiter')->redis();
            if ($r === null) {
                return [null, env('redis.host') ? 'Redis unreachable — using database fallback' : 'Not configured (database fallback)'];
            }

            return [(bool) $r->ping(), 'Redis ' . ($r->info('server')['redis_version'] ?? '')];
        });
        $storageOk = null;
        $out[]     = $this->timed('storage', static function () use (&$storageOk) {
            $row = Catalog::activeStorage();
            $res = StorageFactory::fromRow($row)->testConnection();
            db_connect()->table('storage_providers')->where('id', $row['id'])->update(['status' => $res['ok'] ? 'OK' : 'DOWN', 'status_detail' => $res['detail'], 'last_check_at' => Catalog::now()]);
            $storageOk = $res['ok'];

            return [$res['ok'], $row['kind'] . ' · ' . $row['bucket'] . ' — ' . $res['detail']];
        });
        $out[] = $this->timed('cdn', static function () use ($storageOk) {
            $degraded = db_connect()->table('release_packages p')->join('releases r', 'r.id = p.release_id')->where('p.health', 'DEGRADED')->whereIn('r.status', ['PUBLISHED', 'PAUSED', 'SUPERSEDED'])->countAllResults();
            if ($degraded > 0) {
                return [false, "{$degraded} published package(s) DEGRADED"];
            }

            return [$storageOk !== false, 'Signed URLs · ' . config('UpdateCenter')->downloadUrlTtl . ' s lifetime · ' . config('UpdateCenter')->cdnSigning];
        });
        $out[] = $this->timed('signing', static function () {
            $h = service('signingClient')->health();

            return [$h['ok'], $h['detail']];
        });
        $out[] = $this->timed('queue', static function () {
            $s = Queue::stats();
            if ($s['backlog'] > 500 || $s['oldest_age_s'] > 600) {
                return [null, "Backlog {$s['backlog']}, oldest {$s['oldest_age_s']} s"];
            }

            return [true, "Backlog {$s['backlog']} · failed 24 h: {$s['failed_24h']}"];
        });

        if ($persist) {
            $db = db_connect();
            foreach ($out as $c) {
                $row = ['status' => $c['status'], 'latency_ms' => $c['latency_ms'], 'detail' => $c['detail'], 'checked_at' => Catalog::now()];
                if ($db->table('health_checks')->where('component', $c['component'])->countAllResults() > 0) {
                    $db->table('health_checks')->where('component', $c['component'])->update($row);
                } else {
                    $db->table('health_checks')->insert($row + ['component' => $c['component']]);
                }
            }
        }

        return $out;
    }

    private function timed(string $component, callable $fn): array
    {
        $t = microtime(true);
        try {
            [$ok, $detail] = $fn();
            $status        = $ok === true ? 'OK' : ($ok === null ? 'DEGRADED' : 'DOWN');
        } catch (\Throwable $e) {
            $status = 'DOWN';
            $detail = $e->getMessage();
        }

        return ['component' => $component, 'status' => $status, 'latency_ms' => (int) round((microtime(true) - $t) * 1000), 'detail' => (string) $detail, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z')];
    }

    /** Publish gate: storage and signing must be healthy (FR-UC-OPS-02, WF-C20). */
    public function publishBlockers(): array
    {
        $blockers = [];
        foreach ($this->run() as $c) {
            if (in_array($c['component'], ['storage', 'signing', 'database'], true) && $c['status'] !== 'OK') {
                $blockers[] = $c['component'] . ': ' . $c['detail'];
            }
            if ($c['component'] === 'cdn' && $c['status'] === 'DOWN') {
                $blockers[] = 'distribution: ' . $c['detail'];
            }
        }

        return $blockers;
    }
}

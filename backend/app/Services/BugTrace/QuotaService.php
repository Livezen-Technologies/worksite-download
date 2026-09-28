<?php

namespace App\Services\BugTrace;

use App\Libraries\BugTrace\Bt;

/**
 * Spike protection and quotas (FR-BT-ING-08): a daily quota per project and a
 * per-minute rate per installation (bt_projects.quota_daily / rate_limit).
 * Excess events are counted (bt_quota_counters.dropped) but not stored.
 */
class QuotaService
{
    /**
     * Admit up to $n events; returns how many may be stored.
     */
    public function admit(array $project, string $installationKey, int $n, ?int $now = null): int
    {
        if ($n <= 0) {
            return 0;
        }
        $now      = $now ?? time();
        $admitted = $this->take('p:' . $project['id'], 'd:' . gmdate('Y-m-d', $now), max(1, (int) $project['quota_daily']), $n);
        if ($admitted > 0) {
            $inst = $this->take('i:' . $project['id'] . ':' . substr(hash('sha256', $installationKey), 0, 32), 'm:' . gmdate('YmdHi', $now), max(1, (int) $project['rate_limit']), $admitted);
            if ($inst < $admitted) {
                // Give back the project quota we could not use.
                Bt::db()->table('bt_quota_counters')->where('bucket', 'p:' . $project['id'])->where('window', 'd:' . gmdate('Y-m-d', $now))
                    ->set('count', 'count - ' . ($admitted - $inst), false)->set('dropped', 'dropped + ' . ($admitted - $inst), false)->update();
            }
            $admitted = $inst;
        }

        return $admitted;
    }

    private function take(string $bucket, string $window, int $limit, int $n): int
    {
        $db  = Bt::db();
        $row = $db->table('bt_quota_counters')->where('bucket', $bucket)->where('window', $window)->get()->getRowArray();
        $cur = $row ? (int) $row['count'] : 0;
        $ok  = max(0, min($n, $limit - $cur));
        Bt::increment('bt_quota_counters', ['bucket' => $bucket, 'window' => $window], ['count' => $ok, 'dropped' => $n - $ok]);

        return $ok;
    }

    /** Count events rejected for other reasons (policy, sampling) for visibility. */
    public function countDropped(array $project, string $reason, int $n): void
    {
        if ($n > 0) {
            Bt::increment('bt_quota_counters', ['bucket' => 'drop:' . $reason . ':' . $project['id'], 'window' => 'd:' . gmdate('Y-m-d')], ['dropped' => $n]);
        }
    }

    /** @return array{accepted: int, dropped: int} */
    public function today(int $projectId): array
    {
        $row = Bt::db()->table('bt_quota_counters')->where('bucket', 'p:' . $projectId)->where('window', 'd:' . gmdate('Y-m-d'))->get()->getRowArray();

        return ['accepted' => (int) ($row['count'] ?? 0), 'dropped' => (int) ($row['dropped'] ?? 0)];
    }
}

<?php

namespace App\Commands;

use App\Services\HealthService;
use App\Services\ReleaseService;
use App\Services\RolloutService;
use App\Services\ServerUpdateService;
use App\Services\StorageAdminService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * update-scheduler container (§3.5): scheduled publishes, rollout evaluation,
 * campaign reminders, fleet auto-pause, server ring gate, health & storage probes, analytics
 * roll-ups, retention and cleanup. Run every minute (loop or cron).
 */
class Scheduler extends BaseCommand
{
    protected $group       = 'Update Center';
    protected $name        = 'uc:scheduler';
    protected $description = 'Run periodic tasks (use --loop for the scheduler container).';
    protected $usage       = 'uc:scheduler [--loop] [--task=name]';

    /** task => every N minutes */
    private const TASKS = [
        'scheduled_publish' => 1,
        'rollouts'          => 60,
        'server_reminders'  => 60,
        'fleet'             => 5,
        'server_rings'      => 5,
        'health'            => 5,
        'storage_probe'     => 60,
        'analytics'         => 60,
        'cleanup'           => 60,
        'bugtrace_alerts'   => 5,
        'bugtrace_rollup'   => 60,
        'bugtrace_daily'    => 1440,
        'export_daily'      => 1440,
    ];

    public function run(array $params)
    {
        $only = CLI::getOption('task');
        do {
            $minute = (int) floor(time() / 60);
            foreach (self::TASKS as $task => $every) {
                if ($only !== null && $only !== $task) {
                    continue;
                }
                if ($only === null && $minute % $every !== 0 && ! $this->due($task, $every)) {
                    continue;
                }
                try {
                    $out = $this->{'task' . str_replace('_', '', ucwords($task, '_'))}();
                    cache()->save('uc_sched_' . $task, time(), 86400);
                    foreach ((array) $out as $line) {
                        CLI::write("[{$task}] {$line}");
                    }
                } catch (\Throwable $e) {
                    CLI::error("[{$task}] " . $e->getMessage());
                    log_message('error', "Scheduler task {$task} failed: " . $e->getMessage());
                }
            }
            if (CLI::getOption('loop') !== null) {
                sleep(60 - time() % 60);
                db_connect()->reconnect();
            }
        } while (CLI::getOption('loop') !== null);
    }

    private function due(string $task, int $every): bool
    {
        $last = cache()->get('uc_sched_' . $task);

        return ! is_int($last) || $last <= time() - $every * 60;
    }

    private function taskScheduledPublish(): array
    {
        return (new ReleaseService())->runScheduled();
    }

    private function taskRollouts(): array
    {
        return (new RolloutService())->evaluateAll();
    }

    private function taskServerReminders(): array
    {
        return (new ServerUpdateService())->runReminders();
    }

    private function taskFleet(): array
    {
        return (new ServerUpdateService())->evaluateFleet();
    }

    /** Server ring rollouts: health gate auto-pause and ring opening (FR-DL-RING-01/02). */
    private function taskServerRings(): array
    {
        return (new \App\Services\ServerRingService())->evaluateAll();
    }

    private function taskHealth(): array
    {
        $down = array_filter((new HealthService())->run(), static fn ($c) => $c['status'] !== 'OK');
        foreach ($down as $c) {
            if (in_array($c['component'], ['storage', 'signing', 'database'], true) && cache()->get('uc_health_alert_' . $c['component']) === null) {
                cache()->save('uc_health_alert_' . $c['component'], 1, 3600);
                service('notifier')->notify('storage.problem', ['title' => ucfirst($c['component']) . ' ' . $c['status'], 'body' => $c['detail'], 'link' => '/admin/health']);
            }
        }

        return array_map(static fn ($c) => "{$c['component']} {$c['status']}: {$c['detail']}", $down);
    }

    private function taskStorageProbe(): array
    {
        $r = (new StorageAdminService())->probe();

        return ["checked {$r['checked']}, degraded {$r['degraded']}"];
    }

    /** Daily download aggregates (kept indefinitely) — FR-UC-ANL-04. */
    private function taskAnalytics(): array
    {
        $db   = db_connect();
        $day  = gmdate('Y-m-d', time() - 86400);
        foreach ([$day, gmdate('Y-m-d')] as $d) {
            $db->table('download_daily_stats')->where('day', $d)->delete();
            $rows = $db->table('download_events')->select("application_id, release_id, COALESCE(platform,'') AS platform, COALESCE(architecture,'') AS architecture, COALESCE(package_type,'') AS package_type, COALESCE(country_code,'') AS country_code, SUM(CASE WHEN redirect_ok = 1 AND is_bot = 0 THEN 1 ELSE 0 END) AS downloads, SUM(CASE WHEN redirect_ok = 0 THEN 1 ELSE 0 END) AS failed, SUM(is_bot) AS bots", false)
                ->where('created_at >=', $d . ' 00:00:00')->where('created_at <=', $d . ' 23:59:59')
                ->groupBy(['application_id', 'release_id', 'platform', 'architecture', 'package_type', 'country_code'])->get()->getResultArray();
            foreach ($rows as $r) {
                $db->table('download_daily_stats')->insert($r + ['day' => $d]);
            }
        }

        return [];
    }

    /** Retention & cleanup: raw download events 13 months, expired uploads/sessions, rate-limit rows, done jobs. */
    private function taskCleanup(): array
    {
        $db  = db_connect();
        $now = gmdate('Y-m-d H:i:s');
        $db->table('download_events')->where('created_at <', gmdate('Y-m-d H:i:s', strtotime('-13 months')))->delete();
        $db->table('update_events')->where('received_at <', gmdate('Y-m-d H:i:s', strtotime('-13 months')))->delete();
        $db->table('user_sessions')->groupStart()->where('expires_at <', $now)->orWhere('revoked_at <', gmdate('Y-m-d H:i:s', time() - 86400))->groupEnd()->delete();
        $db->table('rate_limit_hits')->where('at <', (time() - 86400) * 1000)->delete();
        $db->table('jobs')->where('done_at <', gmdate('Y-m-d H:i:s', time() - 7 * 86400))->delete();
        $expired = $db->table('uploads')->where('status', 'OPEN')->where('expires_at <', $now)->get()->getResultArray();
        foreach ($expired as $u) {
            @unlink(rtrim(config('UpdateCenter')->uploadTempDir, '/') . '/' . $u['public_id'] . '.part');
        }
        $db->table('uploads')->where('status', 'OPEN')->where('expires_at <', $now)->update(['status' => 'EXPIRED']);

        return $expired ? [count($expired) . ' uploads expired'] : [];
    }

    /** Bug Trace (§18): alerts every 5 min, roll-ups hourly, digest + retention daily. */
    private function taskBugtraceAlerts(): array
    {
        return $this->spark('bugtrace:alerts');
    }

    private function taskBugtraceRollup(): array
    {
        return $this->spark('bugtrace:rollup');
    }

    private function taskBugtraceDaily(): array
    {
        return array_merge($this->spark('bugtrace:alerts', ['digest' => true]), $this->spark('bugtrace:retention'));
    }

    /** §13.3: configuration, changelogs, manifests and audit logs to write-once storage. */
    private function taskExportDaily(): array
    {
        $r = (new \App\Services\ExportService())->run();

        return array_map(static fn ($o) => "{$o['status']} {$o['key']} ({$o['rows']} rows)", $r['objects']);
    }

    /** @param array<string, mixed> $options */
    private function spark(string $cmd, array $options = []): array
    {
        if (! array_key_exists($cmd, service('commands')->getCommands())) {
            return [];
        }
        $line = $cmd;
        foreach ($options as $k => $v) {
            $line .= ' --' . $k . ($v === true ? '' : ' ' . escapeshellarg((string) $v));
        }
        ob_start();
        command($line);
        $out = trim((string) ob_get_clean());

        return $out === '' ? [] : [$out];
    }
}

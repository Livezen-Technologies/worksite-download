<?php

namespace App\Services;

use App\Libraries\AppVersion;
use App\Libraries\Metrics;

/**
 * Prometheus text exposition for the §13.4 signals (FR-UC-OPS-05). Request
 * counters come from the Metrics recorder; everything else is read from the
 * database at scrape time. Alert rules: docker/monitoring/alerts.yml.
 */
class MetricsService
{
    private array $lines = [];

    public function render(): string
    {
        $this->lines = [];
        $this->requests();
        $this->counters();
        $this->database();
        $this->gauge('uc_build_info', 'Running Update Center version', 1, ['version' => AppVersion::current()]);

        return implode("\n", $this->lines) . "\n";
    }

    private function requests(): void
    {
        $raw     = Metrics::snapshot();
        $req     = [];
        $sum     = [];
        $buckets = [];
        foreach ($raw as $field => $v) {
            $p = explode('|', (string) $field);
            match ($p[0]) {
                'req'   => $req[$p[1]][$p[2]] = (int) $v,
                'sum'   => $sum[$p[1]] = (int) $v,
                'bkt'   => $buckets[$p[1]][$p[2]] = (int) $v,
                default => null,
            };
        }
        ksort($req);
        $this->help('uc_http_requests_total', 'HTTP requests by route group and status class', 'counter');
        foreach ($req as $g => $classes) {
            ksort($classes);
            foreach ($classes as $c => $n) {
                $this->sample('uc_http_requests_total', $n, ['group' => $g, 'code' => $c]);
            }
        }
        $this->help('uc_http_request_duration_seconds', 'HTTP request latency by route group', 'histogram');
        foreach ($req as $g => $classes) {
            $count = array_sum($classes);
            foreach (Metrics::BUCKETS as $b) {
                $this->sample('uc_http_request_duration_seconds_bucket', $buckets[$g][(string) $b] ?? 0, ['group' => $g, 'le' => (string) $b]);
            }
            $this->sample('uc_http_request_duration_seconds_bucket', $count, ['group' => $g, 'le' => '+Inf']);
            $this->sample('uc_http_request_duration_seconds_sum', round(($sum[$g] ?? 0) / 1_000_000, 6), ['group' => $g]);
            $this->sample('uc_http_request_duration_seconds_count', $count, ['group' => $g]);
        }
    }

    private function counters(): void
    {
        $raw = Metrics::snapshot();
        $this->help('uc_events_total', 'Domain events (signing refusals, signature verification failures, …)', 'counter');
        foreach ($raw as $field => $v) {
            if (str_starts_with((string) $field, 'cnt|')) {
                $this->sample('uc_events_total', (int) $v, ['event' => substr((string) $field, 4)]);
            }
        }
    }

    private function database(): void
    {
        $db  = db_connect();
        $now = time();
        $ago = static fn (int $s) => gmdate('Y-m-d H:i:s', $now - $s);

        // Queue: backlog > 500 or age > 10 min.
        $pending = $db->table('jobs')->where('done_at', null)->where('failed_at', null);
        $this->gauge('uc_queue_backlog', 'Jobs waiting or running', (clone $pending)->countAllResults());
        $oldest = (clone $pending)->selectMin('available_at')->get()->getRow('available_at');
        $this->gauge('uc_queue_oldest_age_seconds', 'Age of the oldest pending job', $oldest ? max(0, $now - strtotime($oldest . ' UTC')) : 0);
        $this->gauge('uc_queue_failed_jobs', 'Jobs that exhausted their attempts', $db->table('jobs')->where('failed_at IS NOT NULL', null, false)->countAllResults());

        // Uploads: processing failure rate > 10 % or job stuck > 15 min.
        $since = $ago(3600);
        $total = $db->table('release_package_revisions')->where('uploaded_at >=', $since)->whereIn('status', ['READY', 'REJECTED', 'REPLACED', 'SUPERSEDED'])->countAllResults();
        $rej   = $db->table('release_package_revisions')->where('uploaded_at >=', $since)->where('status', 'REJECTED')->countAllResults();
        $this->gauge('uc_upload_processed_1h', 'Package revisions finished processing in the last hour', $total);
        $this->gauge('uc_upload_rejected_1h', 'Package revisions rejected in the last hour', $rej);
        $this->gauge('uc_upload_stuck', 'Package revisions processing for more than 15 minutes', $db->table('release_package_revisions')->where('status', 'PROCESSING')->where('uploaded_at <', $ago(900))->countAllResults());

        // Resolver: 503 > 0.5 %.
        $dl = $db->table('download_events')->select('COUNT(*) AS n, SUM(CASE WHEN redirect_ok = 0 THEN 1 ELSE 0 END) AS failed')->where('created_at >=', $ago(300))->where('is_bot', 0)->get()->getRowArray();
        $this->gauge('uc_resolver_requests_5m', 'Download resolver requests in the last 5 minutes', (int) ($dl['n'] ?? 0));
        $this->gauge('uc_resolver_failures_5m', 'Download resolver requests that could not redirect (503/410) in the last 5 minutes', (int) ($dl['failed'] ?? 0));

        // Release health from telemetry: install failure > 5 % or rollback spike.
        $this->help('uc_update_events_1h', 'Desktop update events in the last hour by type', 'gauge');
        foreach ($db->table('update_events')->select('type, COUNT(*) AS c')->where('received_at >=', $since)->groupBy('type')->get()->getResultArray() as $r) {
            $this->sample('uc_update_events_1h', (int) $r['c'], ['type' => (string) $r['type']]);
        }

        // Tenant catalog: webhook delivery failing > 1 h.
        $oldestFailing = $db->table('webhook_deliveries')->selectMin('created_at')->where('status', 'PENDING')->where('attempts >', 0)->get()->getRow('created_at');
        $this->gauge('uc_webhook_oldest_failing_seconds', 'Age of the oldest webhook delivery still failing', $oldestFailing ? max(0, $now - strtotime($oldestFailing . ' UTC')) : 0);

        // Component health (storage HEAD probe, signing, DB, Redis, CDN, queue).
        $checks = $db->table('health_checks')->get()->getResultArray();
        $this->help('uc_component_up', 'Last health probe per component (1 up, 0.5 degraded, 0 down)', 'gauge');
        foreach ($checks as $h) {
            $v = match (strtoupper((string) $h['status'])) { 'UP', 'OK' => 1, 'DEGRADED' => 0.5, default => 0 };
            $this->sample('uc_component_up', $v, ['component' => (string) $h['component']]);
        }
        $this->help('uc_component_latency_ms', 'Latency of the last health probe per component', 'gauge');
        foreach ($checks as $h) {
            $this->sample('uc_component_latency_ms', (int) ($h['latency_ms'] ?? 0), ['component' => (string) $h['component']]);
        }
    }

    private function gauge(string $name, string $help, int|float $v, array $labels = []): void
    {
        $this->help($name, $help, 'gauge');
        $this->sample($name, $v, $labels);
    }

    private function help(string $name, string $help, string $type): void
    {
        $this->lines[] = "# HELP {$name} {$help}";
        $this->lines[] = "# TYPE {$name} {$type}";
    }

    private function sample(string $name, int|float $v, array $labels = []): void
    {
        $l = $labels ? '{' . implode(',', array_map(static fn ($k, $x) => $k . '="' . addcslashes((string) $x, "\\\"\n") . '"', array_keys($labels), $labels)) . '}' : '';
        $this->lines[] = $name . $l . ' ' . $v;
    }
}

<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\Semver;

/**
 * Pseudonymous update telemetry and device heartbeats (§2.7, §10.5,
 * FR-UC-TEL-01..04, FR-UC-DEV-01..03). No user identity, IP or business data.
 */
class TelemetryService
{
    public const TYPES = ['update_checked', 'download_started', 'download_completed', 'install_started', 'install_succeeded', 'install_failed', 'rolled_back'];

    /**
     * @param list<array<string,mixed>> $events
     *
     * @return array{accepted: int, duplicates: int, rejected: list<array{index:int, reason:string}>}
     */
    public function ingest(array $events, ?array $tpt, string $country = ''): array
    {
        if (count($events) > 100) {
            throw ApiException::validation(['events' => 'Batch limit is 100 events']);
        }
        $db       = db_connect();
        $accepted = $dup = 0;
        $rejected = [];
        $apps     = [];
        foreach ($events as $i => $e) {
            $id   = (string) ($e['event_id'] ?? '');
            $type = (string) ($e['type'] ?? '');
            $dev  = (string) ($e['device_ref'] ?? '');
            if ($id === '' || strlen($id) > 64 || ! in_array($type, self::TYPES, true) || $dev === '' || strlen($dev) > 128) {
                $rejected[] = ['index' => $i, 'reason' => 'event_id, type and device_ref are required'];

                continue;
            }
            $appIdent = (string) ($e['app_id'] ?? '');
            $apps[$appIdent] ??= db_connect()->table('applications')->select('id')->where('app_identifier', $appIdent)->get()->getRow('id');
            if (! $apps[$appIdent]) {
                $rejected[] = ['index' => $i, 'reason' => 'Unknown app_id'];

                continue;
            }
            service('rateLimiter')->hit('events:dev:' . hash('sha256', $dev), 120, 3600);
            if ($db->table('update_events')->where('event_id', $id)->countAllResults() > 0) {
                $dup++;

                continue;
            }
            $to        = isset($e['to']) ? (string) $e['to'] : null;
            $releaseId = null;
            if ($to !== null) {
                $releaseId = $db->table('releases')->select('id')->where('application_id', $apps[$appIdent])->where('version', $to)->whereIn('status', ['PUBLISHED', 'PAUSED', 'SUPERSEDED', 'WITHDRAWN', 'TESTING', 'APPROVED'])->orderBy('id', 'DESC')->limit(1)->get()->getRow('id');
            }
            $at = isset($e['at']) && strtotime((string) $e['at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $e['at'])) : Catalog::now();
            $db->table('update_events')->insert([
                'event_id' => $id, 'device_ref' => $dev, 'tenant_id' => $tpt['tenant_id'] ?? (isset($e['tenant']) ? substr((string) $e['tenant'], 0, 64) : null),
                'application_id' => $apps[$appIdent], 'release_id' => $releaseId, 'type' => $type,
                'from_version' => isset($e['from']) ? substr((string) $e['from'], 0, 64) : null, 'to_version' => $to !== null ? substr($to, 0, 64) : null,
                'platform' => isset($e['platform']) ? substr((string) $e['platform'], 0, 16) : null, 'architecture' => isset($e['arch']) ? substr((string) $e['arch'], 0, 16) : null,
                'channel' => isset($e['channel']) ? strtoupper(substr((string) $e['channel'], 0, 32)) : null,
                'error_code' => isset($e['error_code']) ? substr(preg_replace('/[^A-Z0-9_]/', '', strtoupper((string) $e['error_code'])), 0, 64) : null,
                'occurred_at' => $at, 'received_at' => Catalog::now(),
            ]);
            if ($releaseId && in_array($type, ['install_succeeded', 'install_failed', 'rolled_back', 'download_completed'], true)) {
                $hour = substr($at, 0, 13) . ':00:00';
                $key  = ['hour' => $hour, 'release_id' => $releaseId, 'platform' => (string) ($e['platform'] ?? ''), 'type' => $type, 'error_code' => (string) ($e['error_code'] ?? '')];
                if ($db->table('update_stats_hourly')->where($key)->countAllResults() > 0) {
                    $db->table('update_stats_hourly')->where($key)->set('count', 'count + 1', false)->update();
                } else {
                    $db->table('update_stats_hourly')->insert($key + ['count' => 1]);
                }
            }
            if ($type === 'install_succeeded' && $to !== null) {
                $this->heartbeat(['device_ref' => $dev, 'app_id' => $appIdent, 'version' => $to, 'platform' => $e['platform'] ?? 'unknown', 'arch' => $e['arch'] ?? 'unknown', 'channel' => $e['channel'] ?? null, 'build' => 0], $tpt, $country);
            }
            $accepted++;
        }

        return ['accepted' => $accepted, 'duplicates' => $dup, 'rejected' => $rejected];
    }

    public function heartbeat(array $h, ?array $tpt, string $country = ''): void
    {
        $dev = (string) ($h['device_ref'] ?? '');
        $ver = (string) ($h['version'] ?? '');
        if ($dev === '' || $ver === '' || ! Semver::isValid(Semver::normalize($ver))) {
            throw ApiException::validation(['device_ref' => 'device_ref and a valid version are required']);
        }
        $app = Catalog::app((string) ($h['app_id'] ?? ''));
        $db  = db_connect();
        $now = Catalog::now();
        $row = [
            'tenant_id' => $tpt['tenant_id'] ?? null, 'version' => Semver::normalize($ver), 'build' => (int) ($h['build'] ?? 0),
            'platform' => substr((string) ($h['platform'] ?? 'unknown'), 0, 16), 'architecture' => substr((string) ($h['arch'] ?? $h['architecture'] ?? 'unknown'), 0, 16),
            'channel' => strtoupper(substr((string) ($h['channel'] ?? 'STABLE'), 0, 32)), 'country_code' => $country !== '' ? substr($country, 0, 2) : null, 'last_seen' => $now,
        ];
        $exists = $db->table('device_versions')->where('device_ref', $dev)->where('application_id', $app['id'])->countAllResults() > 0;
        if ($exists) {
            $db->table('device_versions')->where('device_ref', $dev)->where('application_id', $app['id'])->update($row);
        } else {
            $db->table('device_versions')->insert($row + ['device_ref' => $dev, 'application_id' => $app['id'], 'first_seen' => $now]);
        }
    }

    /** Per-release success/failure counts (FR-UC-TEL-04). */
    public function releaseStats(int $releaseId): array
    {
        $db   = db_connect();
        $rows = $db->table('update_stats_hourly')->select('type, SUM(count) AS c')->where('release_id', $releaseId)->groupBy('type')->get()->getResultArray();
        $by   = array_column($rows, 'c', 'type');
        $ok   = (int) ($by['install_succeeded'] ?? 0);
        $fail = (int) ($by['install_failed'] ?? 0);
        $rel  = Catalog::releaseById($releaseId);
        $eligible = $db->table('device_versions')->where('application_id', $rel['application_id'])->where('last_seen >=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))->where('channel', Catalog::channelById((int) $rel['channel_id'])['code'])->countAllResults();

        return [
            'installed'        => $ok,
            'failed'           => $fail,
            'rolled_back'      => (int) ($by['rolled_back'] ?? 0),
            'downloaded'       => (int) ($by['download_completed'] ?? 0),
            'success_rate'     => $ok + $fail > 0 ? round($ok / ($ok + $fail), 4) : null,
            'eligible_devices' => $eligible,
        ];
    }

    /** Devices › Versions (FR-UC-DEV-01/02). */
    public function distribution(int $appId, ?string $channel = null): array
    {
        $db = db_connect();
        $q  = $db->table('device_versions')->select('version, COUNT(*) AS c')->where('application_id', $appId)->where('last_seen >=', gmdate('Y-m-d H:i:s', time() - 7 * 86400));
        if ($channel) {
            $q->where('channel', strtoupper($channel));
        }
        $rows  = $q->groupBy('version')->get()->getResultArray();
        $total = array_sum(array_map('intval', array_column($rows, 'c')));
        $stable = Catalog::channel($channel ?: 'STABLE', $appId);
        $current = (new ManifestService())->currentRelease($appId, (int) $stable['id']);
        $states  = array_column($db->table('releases')->select('version, support_state')->where('application_id', $appId)->get()->getResultArray(), 'support_state', 'version');
        $min     = (new MinimumVersionService())->channelMinimum($appId, (int) $stable['id'], null)['minimum_version'] ?? null;
        $items   = [];
        $sum     = ['CURRENT' => 0, 'UPDATE_AVAILABLE' => 0, 'UNSUPPORTED' => 0];
        foreach ($rows as $r) {
            $c     = (int) $r['c'];
            $state = 'UPDATE_AVAILABLE';
            try {
                if (in_array($states[$r['version']] ?? '', ['UNSUPPORTED', 'BLOCKED'], true) || ($min && Semver::lt($r['version'], $min))) {
                    $state = 'UNSUPPORTED';
                } elseif ($current === null || Semver::gte($r['version'], $current['version'])) {
                    $state = 'CURRENT';
                }
            } catch (\Throwable) {
            }
            $sum[$state] += $c;
            $items[] = ['version' => $r['version'], 'count' => $c, 'pct' => $total ? round($c * 100 / $total, 1) : 0, 'state' => $state];
        }
        usort($items, static function ($a, $b) {
            try {
                return Semver::compare($b['version'], $a['version']);
            } catch (\Throwable) {
                return 0;
            }
        });
        $pct = static fn ($n) => $total ? round($n * 100 / $total, 1) : 0;
        $byPlatform = array_map(static fn ($r) => ['platform' => $r['platform'], 'count' => (int) $r['c']], $db->table('device_versions')->select('platform, COUNT(*) AS c')->where('application_id', $appId)->where('last_seen >=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))->groupBy('platform')->get()->getResultArray());
        $byChannel  = array_map(static fn ($r) => ['channel' => $r['channel'], 'count' => (int) $r['c']], $db->table('device_versions')->select('channel, COUNT(*) AS c')->where('application_id', $appId)->where('last_seen >=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))->groupBy('channel')->get()->getResultArray());

        return ['active_devices' => $total, 'by_version' => $items, 'by_platform' => $byPlatform, 'by_channel' => $byChannel, 'current_pct' => $pct($sum['CURRENT']), 'update_available_pct' => $pct($sum['UPDATE_AVAILABLE']), 'unsupported_pct' => $pct($sum['UNSUPPORTED'])];
    }

    /** Update Status (FR-UC-DEV-03). */
    public function updateStatus(int $appId, ?int $releaseId = null): array
    {
        $db = db_connect();
        $q  = $db->table('releases')->where('application_id', $appId)->whereIn('status', ['PUBLISHED', 'PAUSED', 'SUPERSEDED', 'WITHDRAWN'])->orderBy('version_sort', 'DESC')->limit(10);
        if ($releaseId) {
            $q->where('id', $releaseId);
        }
        $out = [];
        foreach ($q->get()->getResultArray() as $r) {
            $s = $this->releaseStats((int) $r['id']);
            $checked = $db->table('update_events')->where('release_id', $r['id'])->where('type', 'update_checked')->countAllResults();
            $out[]   = ['release_id' => $r['public_id'], 'version' => $r['version'], 'checked' => $checked, 'downloaded' => $s['downloaded'], 'installed' => $s['installed'], 'failed' => $s['failed'], 'rolled_back' => $s['rolled_back'], 'success_rate' => $s['success_rate']];
        }
        $fq = $db->table('update_events')->select('error_code, COUNT(*) AS c')->where('application_id', $appId)->where('type', 'install_failed')->where('error_code IS NOT NULL');
        if ($releaseId) {
            $fq->where('release_id', $releaseId);
        }
        $fails = array_map(static fn ($r) => ['error_code' => $r['error_code'], 'count' => (int) $r['c']], $fq->groupBy('error_code')->orderBy('c', 'DESC')->limit(20)->get()->getResultArray());

        return ['releases' => $out, 'failures_by_code' => $fails];
    }
}

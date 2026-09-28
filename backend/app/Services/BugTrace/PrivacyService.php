<?php

namespace App\Services\BugTrace;

use App\Exceptions\ApiException;
use App\Libraries\BugTrace\Bt;
use App\Libraries\BugTrace\Vault;

/**
 * Data-subject requests (FR-BT-PRV-06, WF-C39, AC-C74) and retention
 * (FR-BT-PRV-04).
 *
 * Retention defaults (settings key bugtrace.retention, per-edition overrides
 * under "editions"): raw events 90 days; issues and aggregates 13 months
 * (395 days) after last activity; user reports and their attachments
 * 12 months after close; unlinked uploads 24 h.
 */
class PrivacyService
{
    public const SETTINGS_KEY = 'bugtrace.retention';

    public const DEFAULT_RETENTION = [
        'events_days'     => 90,
        'issues_days'     => 395,
        'aggregates_days' => 395,
        'reports_days'    => 365,
        'editions'        => [], // e.g. {"ENTERPRISE": {"events_days": 180}}
    ];

    /**
     * Delete everything stored for a pseudonymous user ref or installation ref.
     *
     * @return array{events: int, reports: int, attachments: int, sessions: int, issues_touched: int}
     */
    public function delete(?string $userRef, ?string $installationRef): array
    {
        $userRef         = $userRef !== null ? trim($userRef) : null;
        $installationRef = $installationRef !== null ? trim($installationRef) : null;
        if (($userRef === null || $userRef === '') && ($installationRef === null || $installationRef === '')) {
            throw ApiException::validation(['user_ref' => 'user_ref or installation_ref is required']);
        }
        $db   = Bt::db();
        $cols = array_filter(['user_ref' => $userRef ?: null, 'installation_ref' => $installationRef ?: null]);

        $scope = static function ($b) use ($cols) {
            $b->groupStart();
            foreach ($cols as $c => $v) {
                $b->orWhere($c, $v);
            }

            return $b->groupEnd();
        };

        $events   = $scope($db->table('bt_events')->select('id, event_id, issue_id'))->get()->getResultArray();
        $issueIds = array_values(array_unique(array_map('intval', array_filter(array_column($events, 'issue_id')))));
        $reports  = $scope($db->table('bt_reports')->select('id, reference'))->get()->getResultArray();

        $attachments = 0;
        $owners      = [['event', array_column($events, 'event_id')], ['report', array_column($reports, 'reference')]];
        foreach ($owners as [$type, $ids]) {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach ($db->table('bt_attachments')->where('owner_type', $type)->whereIn('owner_id', $chunk)->get()->getResultArray() as $a) {
                    Vault::delete((string) $a['storage_key']);
                    $db->table('bt_attachments')->where('id', $a['id'])->delete();
                    $attachments++;
                }
            }
        }
        foreach (array_chunk(array_column($events, 'id'), 500) as $chunk) {
            $db->table('bt_events')->whereIn('id', $chunk)->delete();
        }
        foreach (array_chunk(array_map('intval', array_column($reports, 'id')), 500) as $chunk) {
            $db->table('bt_report_updates')->whereIn('report_id', $chunk)->delete();
            $db->table('bt_comments')->where('target_type', 'report')->whereIn('target_id', $chunk)->delete();
            $db->table('bt_reports')->whereIn('id', $chunk)->delete();
        }
        $refs = array_values($cols);
        $db->table('bt_issue_users')->whereIn('ref', $refs)->delete();
        $sessions = 0;
        if ($userRef) {
            $sessions = $db->table('bt_session_users')->where('user_ref', $userRef)->countAllResults();
            $db->table('bt_session_users')->where('user_ref', $userRef)->delete();
        }
        $svc = new IssueService();
        foreach ($issueIds as $iid) {
            $svc->recountUsers($iid);
        }

        return ['events' => count($events), 'reports' => count($reports), 'attachments' => $attachments, 'sessions' => $sessions, 'issues_touched' => count($issueIds)];
    }

    /** Export everything stored for a ref (FR-BT-PRV-06 "export on request"). */
    public function export(?string $userRef, ?string $installationRef): array
    {
        $cols = array_filter(['user_ref' => $userRef ? trim($userRef) : null, 'installation_ref' => $installationRef ? trim($installationRef) : null]);
        if ($cols === []) {
            throw ApiException::validation(['user_ref' => 'user_ref or installation_ref is required']);
        }
        $db    = Bt::db();
        $scope = static function ($b) use ($cols) {
            $b->groupStart();
            foreach ($cols as $c => $v) {
                $b->orWhere($c, $v);
            }

            return $b->groupEnd();
        };
        $events  = $scope($db->table('bt_events'))->orderBy('timestamp')->limit(10000)->get()->getResultArray();
        $reports = $scope($db->table('bt_reports'))->orderBy('created_at')->get()->getResultArray();

        return [
            'subject' => $cols,
            'events'  => array_map(static fn ($e) => [
                'event_id' => $e['event_id'], 'type' => $e['type'], 'timestamp' => Bt::iso($e['timestamp']), 'release' => $e['release'],
                'platform' => $e['platform'], 'os' => $e['os'], 'browser' => $e['browser'], 'exception' => Bt::decode($e['exception'], null),
                'contexts' => Bt::decode($e['contexts'], null), 'breadcrumbs' => Bt::decode($e['breadcrumbs'], []), 'tags' => Bt::decode($e['tags'], []),
            ], $events),
            'reports' => array_map(static fn ($r) => [
                'reference' => $r['reference'], 'title' => $r['title'], 'description' => $r['description'], 'steps' => Bt::decode($r['steps'], []),
                'expected' => $r['expected'], 'severity' => $r['severity'], 'category' => $r['category'], 'status' => $r['status'],
                'created_at' => Bt::iso($r['created_at']), 'contact_consent' => (bool) $r['contact_consent'],
            ], $reports),
        ];
    }

    /** @return array<string, int> rows removed per kind */
    public function applyRetention(?int $now = null): array
    {
        $now  = $now ?? time();
        $db   = Bt::db();
        $cfg  = Bt::setting(self::SETTINGS_KEY, self::DEFAULT_RETENTION);
        $at   = static fn (int $days) => gmdate('Y-m-d H:i:s', $now - $days * 86400);
        $out  = ['events' => 0, 'attachments' => 0, 'issues' => 0, 'reports' => 0, 'aggregates' => 0];

        // Raw events (per-edition overrides first).
        $editions = is_array($cfg['editions'] ?? null) ? $cfg['editions'] : [];
        foreach ($editions as $edition => $o) {
            if (isset($o['events_days'])) {
                $out['events'] += $this->purgeEvents($db->table('bt_events')->where('edition', strtoupper((string) $edition))->where('timestamp <', $at((int) $o['events_days'])));
            }
        }
        $b = $db->table('bt_events')->where('timestamp <', $at((int) $cfg['events_days']));
        if ($editions !== []) {
            $b->groupStart()->where('edition', null)->orWhereNotIn('edition', array_map('strtoupper', array_keys($editions)))->groupEnd();
        }
        $out['events'] += $this->purgeEvents($b);

        // Closed reports (+ attachments, updates, comments).
        $reports = $db->table('bt_reports')->select('id, reference')->where('status', 'CLOSED')->where('closed_at <', $at((int) $cfg['reports_days']))->get()->getResultArray();
        foreach ($reports as $r) {
            foreach ($db->table('bt_attachments')->where('owner_type', 'report')->where('owner_id', $r['reference'])->get()->getResultArray() as $a) {
                Vault::delete((string) $a['storage_key']);
                $db->table('bt_attachments')->where('id', $a['id'])->delete();
                $out['attachments']++;
            }
            $db->table('bt_report_updates')->where('report_id', $r['id'])->delete();
            $db->table('bt_comments')->where('target_type', 'report')->where('target_id', $r['id'])->delete();
            $db->table('bt_reports')->where('id', $r['id'])->delete();
            $out['reports']++;
        }

        // Unlinked or expired uploads.
        foreach ($db->table('bt_attachments')->where('expires_at IS NOT NULL', null, false)->where('expires_at <', gmdate('Y-m-d H:i:s', $now))->get()->getResultArray() as $a) {
            Vault::delete((string) $a['storage_key']);
            $db->table('bt_attachments')->where('id', $a['id'])->delete();
            $out['attachments']++;
        }

        // Issues without activity for 13 months (and their aggregates).
        $old = array_map('intval', array_column($db->table('bt_issues')->select('id')->where('last_seen <', $at((int) $cfg['issues_days']))->get()->getResultArray(), 'id'));
        foreach (array_chunk($old, 500) as $chunk) {
            if ($db->table('bt_events')->whereIn('issue_id', $chunk)->countAllResults() > 0) {
                $out['events'] += $this->purgeEvents($db->table('bt_events')->whereIn('issue_id', $chunk));
            }
            foreach (['bt_issue_users', 'bt_issue_releases', 'bt_issue_stats_hourly', 'bt_issue_links'] as $t) {
                $db->table($t)->whereIn('issue_id', $chunk)->delete();
            }
            $db->table('bt_comments')->where('target_type', 'issue')->whereIn('target_id', $chunk)->delete();
            $db->table('bt_reports')->whereIn('issue_id', $chunk)->update(['issue_id' => null]);
            $db->table('bt_reports')->whereIn('suggested_issue_id', $chunk)->update(['suggested_issue_id' => null]);
            $db->table('bt_issues')->whereIn('id', $chunk)->delete();
            $out['issues'] += count($chunk);
        }

        // Aggregates.
        $cut = $at((int) $cfg['aggregates_days']);
        $db->table('bt_issue_stats_hourly')->where('hour <', $cut)->delete();
        $out['aggregates'] += $db->affectedRows();
        $db->table('bt_sessions_daily')->where('date <', substr($cut, 0, 10))->delete();
        $out['aggregates'] += $db->affectedRows();
        $db->table('bt_session_users')->where('date <', gmdate('Y-m-d', $now - 90 * 86400))->delete();
        $out['aggregates'] += $db->affectedRows();
        $db->table('bt_alert_events')->where('created_at <', $cut)->delete();
        $db->table('bt_quota_counters')->groupStart()->like('window', 'm:', 'after')->where('window <', 'm:' . gmdate('YmdHi', $now - 7200))->groupEnd()->delete();
        $db->table('bt_quota_counters')->groupStart()->like('window', 'd:', 'after')->where('window <', 'd:' . gmdate('Y-m-d', $now - 30 * 86400))->groupEnd()->delete();

        return $out;
    }

    private function purgeEvents(\CodeIgniter\Database\BaseBuilder $b): int
    {
        $db   = Bt::db();
        $rows = $b->select('id, event_id')->limit(50000)->get()->getResultArray();
        foreach (array_chunk($rows, 500) as $chunk) {
            foreach ($db->table('bt_attachments')->where('owner_type', 'event')->whereIn('owner_id', array_column($chunk, 'event_id'))->get()->getResultArray() as $a) {
                Vault::delete((string) $a['storage_key']);
                $db->table('bt_attachments')->where('id', $a['id'])->delete();
            }
            $db->table('bt_events')->whereIn('id', array_column($chunk, 'id'))->delete();
        }

        return count($rows);
    }
}

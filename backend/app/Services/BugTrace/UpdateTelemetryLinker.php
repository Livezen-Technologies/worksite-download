<?php

namespace App\Services\BugTrace;

use App\Libraries\BugTrace\Bt;

/**
 * FR-BT-ING-09: update telemetry (§10.5) install failures and rollbacks
 * become Bug Trace issues automatically — one issue per application,
 * platform and error code ("Update install failed: UPDATE_DISK_FULL (windows)"),
 * counted per release and device. Runs incrementally from `bugtrace:rollup`
 * using a watermark on update_events.id.
 */
class UpdateTelemetryLinker
{
    public const WATERMARK = 'bugtrace.update_events_watermark';

    /** @return int events linked */
    public function run(int $limit = 20000): int
    {
        $db    = Bt::db();
        $mark  = (int) (Bt::setting(self::WATERMARK, ['id' => 0])['id'] ?? 0);
        $rows  = $db->table('update_events')->where('id >', $mark)->whereIn('type', ['install_failed', 'rolled_back'])->orderBy('id')->limit($limit)->get()->getResultArray();
        $maxId = $mark;
        $n     = 0;
        foreach ($rows as $e) {
            $maxId   = max($maxId, (int) $e['id']);
            $project = $this->project((int) $e['application_id']);
            if ($project === null) {
                continue;
            }
            $code    = (string) ($e['error_code'] ?: ($e['type'] === 'rolled_back' ? 'ROLLED_BACK' : 'UNKNOWN'));
            $plat    = (string) ($e['platform'] ?: 'any');
            $release = (string) ($e['to_version'] ?? '');
            $ts      = $e['occurred_at'] ?: Bt::now();
            $fp      = hash('sha256', 'update-failure|' . $e['type'] . '|' . $code . '|' . $plat);
            $issue   = $db->table('bt_issues')->where('project_id', $project['id'])->where('fingerprint', $fp)->get()->getRowArray();
            if (! $issue) {
                $db->table('bt_issues')->insert([
                    'public_id'        => $project['issue_prefix'] . '-' . Bt::base36(Bt::nextCounter('issue:' . $project['id'], 1296)),
                    'project_id'       => $project['id'],
                    'fingerprint'      => $fp,
                    'type'             => 'error',
                    'title'            => ($e['type'] === 'rolled_back' ? 'Update rolled back: ' : 'Update install failed: ') . $code . ' (' . $plat . ')',
                    'culprit'          => 'updater',
                    'status'           => 'NEW',
                    'priority'         => 'P3',
                    'first_seen'       => $ts,
                    'last_seen'        => $ts,
                    'first_release_id' => $e['release_id'],
                    'first_release'    => $release ?: null,
                    'last_release'     => $release ?: null,
                    'labels'           => Bt::json(['update-telemetry']),
                    'created_at'       => Bt::now(),
                    'updated_at'       => Bt::now(),
                ]);
                $issue = $db->table('bt_issues')->where('id', $db->insertID())->get()->getRowArray();
            }
            $db->table('bt_issue_users')->ignore(true)->insert(['issue_id' => $issue['id'], 'kind' => 'inst', 'ref' => mb_substr((string) $e['device_ref'], 0, 128)]);
            $newInst = $db->affectedRows() > 0 ? 1 : 0;
            if ($e['tenant_id']) {
                $db->table('bt_issue_users')->ignore(true)->insert(['issue_id' => $issue['id'], 'kind' => 'tnt', 'ref' => (string) $e['tenant_id']]);
            }
            $b = $db->table('bt_issues')->where('id', $issue['id'])->set('events_count', 'events_count + 1', false)->set('installations_count', 'installations_count + ' . $newInst, false);
            if ($ts > $issue['last_seen']) {
                $b->set('last_seen', $ts);
            }
            $b->update();
            if ($release !== '') {
                Bt::increment('bt_issue_releases', ['issue_id' => $issue['id'], 'release' => $release], ['events' => 1], [], ['first_seen' => $ts, 'last_seen' => $ts]);
            }
            Bt::increment('bt_issue_stats_hourly', ['issue_id' => $issue['id'], 'hour' => substr($ts, 0, 13) . ':00:00'], ['events' => 1, 'installations' => $newInst]);
            $n++;
        }
        if ($maxId > $mark) {
            Bt::saveSetting(self::WATERMARK, ['id' => $maxId]);
        }

        return $n;
    }

    private function project(int $appId): ?array
    {
        $db = Bt::db();
        foreach (['desktop', 'server', 'mobile', 'web'] as $platform) {
            $p = $db->table('bt_projects')->where('application_id', $appId)->where('platform', $platform)->get()->getRowArray();
            if ($p) {
                return $p;
            }
        }

        return null;
    }
}

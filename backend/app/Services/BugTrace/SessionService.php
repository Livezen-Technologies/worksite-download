<?php

namespace App\Services\BugTrace;

use App\Libraries\BugTrace\Bt;
use App\Libraries\BugTrace\ReleaseLinker;

/**
 * Session aggregation for crash-free rates (FR-BT-SDK-09, FR-BT-HLT-01).
 *
 * SDK contract: send `{sid, init:true, status:'started'|'ok', release, user_ref}`
 * when a session starts and `{sid, status:'exited'|'crashed'|'abnormal'}` when it
 * ends. A session is counted once (init), crashed/abnormal once (end state).
 * Distinct users per day live in bt_session_users (crashed flag per user).
 */
class SessionService
{
    /** @param list<array<string, mixed>> $sessions */
    public function record(int $projectId, array $sessions, int $receivedAt): void
    {
        $project = Bt::db()->table('bt_projects')->where('id', $projectId)->get()->getRowArray();
        if (! $project) {
            return;
        }
        $linker = new ReleaseLinker();
        foreach ($sessions as $s) {
            $release = (string) ($s['release'] ?? '');
            if ($release === '') {
                continue;
            }
            $link  = $linker->link((int) $project['application_id'], $release);
            $date  = substr(Bt::clientTime($s['started'] ?? null, $receivedAt), 0, 10);
            $user  = (string) ($s['user_ref'] ?? '') ?: ('anon:' . ($s['sid'] ?? bin2hex(random_bytes(6))));
            $where = ['project_id' => $projectId, 'release' => $release, 'date' => $date];
            $incr  = ['sessions' => 0, 'crashed' => 0, 'abnormal' => 0, 'users' => 0, 'crashed_users' => 0];

            $db = Bt::db();
            if (! empty($s['init'])) {
                $incr['sessions'] = 1;
            }
            // Distinct users per day.
            $db->table('bt_session_users')->ignore(true)->insert($where + ['user_ref' => $user, 'crashed' => 0, 'release_id' => $link['release_id']]);
            if ($db->affectedRows() > 0) {
                $incr['users'] = 1;
            }
            if ($s['status'] === 'crashed') {
                $incr['crashed'] = 1;
                $db->table('bt_session_users')->where($where)->where('user_ref', $user)->where('crashed', 0)->update(['crashed' => 1]);
                if ($db->affectedRows() > 0) {
                    $incr['crashed_users'] = 1;
                }
            } elseif ($s['status'] === 'abnormal') {
                $incr['abnormal'] = 1;
            }
            if (array_sum($incr) === 0) {
                continue;
            }
            Bt::increment('bt_sessions_daily', $where, $incr, ['release_id' => $link['release_id']]);
        }
    }
}

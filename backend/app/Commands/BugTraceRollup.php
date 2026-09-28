<?php

namespace App\Commands;

use App\Libraries\BugTrace\Bt;
use App\Services\BugTrace\IssueService;
use App\Services\BugTrace\UpdateTelemetryLinker;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Hourly Bug Trace housekeeping: un-ignores issues whose "ignore until" date
 * passed, reconciles user/installation counters of recently active issues and
 * fills suggested issues for reports whose related event arrived late, and
 * links update install failures / rollbacks to issues (FR-BT-ING-09).
 * Schedule: every hour (cron `5 * * * *  php spark bugtrace:rollup`).
 */
class BugTraceRollup extends BaseCommand
{
    protected $group       = 'BugTrace';
    protected $name        = 'bugtrace:rollup';
    protected $description = 'Hourly Bug Trace rollups: ignore-until dates, counter reconciliation, report suggestions.';

    public function run(array $params)
    {
        $db  = Bt::db();
        $svc = new IssueService();
        $n   = 0;
        foreach ($db->table('bt_issues')->where('status', 'IGNORED')->where('ignore_until IS NOT NULL', null, false)->get()->getResultArray() as $i) {
            $until = Bt::decode($i['ignore_until'], [])['until'] ?? null;
            if ($until !== null && strtotime((string) $until) !== false && strtotime((string) $until) <= time()) {
                $db->table('bt_issues')->where('id', $i['id'])->update(['status' => 'NEW', 'ignore_until' => null, 'updated_at' => Bt::now()]);
                IssueService::activity((int) $i['id'], null, 'Un-ignored: ignore period ended');
                $n++;
            }
        }
        $recent = $db->table('bt_issues')->select('id')->where('last_seen >=', gmdate('Y-m-d H:i:s', time() - 7200))->get()->getResultArray();
        foreach ($recent as $r) {
            $svc->recountUsers((int) $r['id']);
        }
        $filled = 0;
        foreach ($db->table('bt_reports r')->select('r.id, e.issue_id')->join('bt_events e', 'e.event_id = r.related_event_id')->where('r.suggested_issue_id', null)->where('r.related_event_id IS NOT NULL', null, false)->get()->getResultArray() as $r) {
            $db->table('bt_reports')->where('id', $r['id'])->update(['suggested_issue_id' => $r['issue_id']]);
            $filled++;
        }
        $linked = (new UpdateTelemetryLinker())->run();
        CLI::write(sprintf('bugtrace:rollup — un-ignored %d, reconciled %d issues, %d report suggestions, %d update failures linked', $n, count($recent), $filled, $linked));
    }
}

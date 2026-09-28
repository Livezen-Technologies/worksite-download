<?php

namespace Tests\Feature\BugTrace;

require_once __DIR__ . '/BugTraceTestCase.php';

use App\Services\BugTrace\AlertService;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * FR-BT-ISS-01/04 (filters, bulk, comments, links, permissions, tenant
 * attribution, CSV export), FR-BT-HLT-03 (alert rules, spike, quiet hours,
 * digest), minidump ingest (WF-C31, symbolication pending) and projects.
 */
final class TriageAndAlertsTest extends BugTraceTestCase
{
    use StreamFilterTrait;

    public function testTriageApiAndPermissions(): void
    {
        $tpt = $this->tpt(['policy' => 'CRASHES_AND_ERRORS'], 'tnt_ACME');
        $a   = $this->webError();
        $b   = $this->webError(['type' => 'error', 'sample_rate' => 1]);
        $b['exception'][0]['type'] = 'SyntaxError';
        $this->envelope(['events' => [$a, $b]], self::WEB_KEY, $tpt)->assertStatus(202);

        $viewer = $this->staff(['viewer']);
        $this->assertSame(2, $this->json($this->h($viewer)->get('api/v1/admin/bugtrace/issues'))['total']);
        $this->h($viewer, 'json')->patch('api/v1/admin/bugtrace/issues/WS-WEB-100', ['priority' => 'P0'])->assertStatus(403);
        $this->h($viewer)->get('api/v1/admin/bugtrace/issues?format=csv')->assertStatus(403);
        $detail = $this->json($this->h($viewer)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'));
        $this->assertArrayNotHasKey('tenant_ref', $detail['latest_event']);

        $support = $this->staff(['support']); // has bugtrace.tenant.view
        $detail  = $this->json($this->h($support)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'));
        $this->assertSame(1, $detail['tenant_count']);
        $this->assertSame('tnt_ACME', $detail['tenants'][0]['tenant_ref']);
        $this->assertSame('tnt_ACME', $detail['latest_event']['tenant_ref']);
        $this->assertSame(1, $this->json($this->h($support)->get('api/v1/admin/bugtrace/issues?tenant=tnt_ACME&type=error'))['total']);

        $mentioned = $this->makeUser(['developer'], 'kasun.s@livezen.lk');
        $rm        = $this->staff(['release_manager']);
        $c = $this->h($rm, 'json')->post('api/v1/admin/bugtrace/issues/WS-WEB-100/comments', ['body' => 'Looks like the folder cache @kasun.s@livezen.lk']);
        $this->assertSame(201, $c->response()->getStatusCode(), (string) $c->response()->getBody());
        $this->assertSame(1, $this->json($c)['mentions']);
        $this->assertSame([(int) $mentioned['id']], end($this->notifier->notifications)['users']);
        $this->h($rm, 'json')->post('api/v1/admin/bugtrace/issues/WS-WEB-100/links', ['kind' => 'github', 'ref' => 'Livezen-Technologies/worksuite#4312', 'url' => 'https://github.com/Livezen-Technologies/worksuite/issues/4312'])->assertStatus(201);
        $this->h($rm, 'json')->post('api/v1/admin/bugtrace/issues/WS-WEB-100/links', ['kind' => 'jira', 'ref' => 'x'])->assertStatus(422);

        $bulk = $this->json($this->h($rm, 'json')->post('api/v1/admin/bugtrace/issues/bulk', ['ids' => ['WS-WEB-100', 'WS-WEB-101', 'WS-WEB-ZZZ'], 'patch' => ['priority' => 'P1', 'labels' => ['mail', 'finance']]]));
        $this->assertSame(2, $bulk['updated']);
        $this->assertArrayHasKey('WS-WEB-ZZZ', $bulk['errors']);
        $detail = $this->json($this->h($rm)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'));
        $this->assertSame(['mail', 'finance'], $detail['labels']);
        $this->assertSame('github', $detail['links'][0]['kind']);
        $this->assertContains('Priority P2 → P1', array_column($detail['comments'], 'body'));
        $this->assertSame(2, $this->json($this->h($rm)->get('api/v1/admin/bugtrace/issues?priority=P1&label=mail'))['total']);

        $csv = $this->h($rm)->get('api/v1/admin/bugtrace/issues?format=csv');
        $csv->assertStatus(200);
        $this->assertStringContainsString('WS-WEB-100', (string) $csv->response()->getBody());
        $this->seeInDatabase('audit_logs', ['action' => 'bugtrace.export']);

        $events = $this->json($this->h($rm)->get('api/v1/admin/bugtrace/issues/WS-WEB-100/events'));
        $this->assertSame(1, $events['total']);
        $projects = $this->json($this->h($rm)->get('api/v1/admin/bugtrace/projects'));
        $this->assertSame(['btp_web', 'btp_dsk', 'btp_mob', 'btp_srv'], array_column($projects['data'], 'id'));
        $p = $this->json($this->h($rm, 'json')->patch('api/v1/admin/bugtrace/projects/btp_web', ['sample_rates' => ['error' => 0.5]]));
        $this->assertSame(0.5, $p['sample_rates']['error']);
    }

    public function testAlertRulesSpikeQuietHoursAndDigest(): void
    {
        $rm  = $this->staff(['release_manager']);
        $res = $this->h($rm, 'json')->post('api/v1/admin/bugtrace/alerts', ['name' => 'Chat on spikes', 'condition' => 'spike', 'threshold' => ['multiplier' => 3, 'min_events' => 5], 'channels' => ['in_app'], 'project' => 'btp_web']);
        $this->assertSame(201, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $rule = $this->json($res);
        $this->assertStringStartsWith('alr_', $rule['id']);
        $this->assertSame('btp_web', $rule['project']['id']);
        $this->h($rm, 'json')->post('api/v1/admin/bugtrace/alerts', ['name' => 'x', 'condition' => 'nope'])->assertStatus(422);
        $list = $this->json($this->h($rm)->get('api/v1/admin/bugtrace/alerts'));
        $this->assertCount(4, $list['data'], '3 default rules + 1');

        // 8 events in the current hour against no baseline → spike.
        $events = [];
        for ($i = 0; $i < 8; $i++) {
            $events[] = $this->webError(['user_ref' => "u_hmac:s{$i}"]);
        }
        $this->envelope(['events' => $events])->assertStatus(202);
        $this->notifier->notifications = [];
        $fired = (new AlertService())->evaluateIssueRules();
        $this->assertGreaterThanOrEqual(1, $fired);
        $this->assertStringStartsWith('Spike in WS-WEB-100', $this->notifier->notifications[0]['payload']['title']);
        $this->assertSame(0, (new AlertService())->evaluateIssueRules(), 'deduplicated');

        // Quiet hours all day → deferred, then delivered by deliverPending once quiet hours are removed.
        $this->h($rm, 'json')->patch('api/v1/admin/bugtrace/alerts/alr_default_regression', ['quiet_hours' => '00:00-23:59'])->assertStatus(200);
        $this->h($rm, 'json')->patch('api/v1/admin/bugtrace/alerts/alr_default_new_crash', ['digest' => true])->assertStatus(200);
        $this->notifier->notifications = [];
        $this->envelope(['events' => [$this->webError(['exception' => [['type' => 'OtherError']]])]])->assertStatus(202);
        $this->assertSame([], $this->notifier->notifications, 'digest rule holds the new-issue alert');
        $this->assertSame(1, (new AlertService())->sendDigest());
        $this->assertCount(1, $this->notifier->notifications);

        $this->assertTrue(AlertService::inQuietHours('22:00-07:00', strtotime('2026-09-27T17:00:00Z'))); // 22:30 Colombo
        $this->assertFalse(AlertService::inQuietHours('22:00-07:00', strtotime('2026-09-27T06:00:00Z'))); // 11:30 Colombo
        $this->h($rm)->call('DELETE', 'api/v1/admin/bugtrace/alerts/' . $rule['id'])->assertStatus(200);
        $this->seeInDatabase('audit_logs', ['action' => 'bugtrace.alert.deleted']);
    }

    public function testMinidumpIsStoredAndPendingSymbolication(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dmp');
        file_put_contents($tmp, "MDMP\x93\xa7" . random_bytes(2048));
        service('superglobals')->setFilesArray(['upload_file_minidump' => ['name' => 'crash.dmp', 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp), 'full_path' => 'crash.dmp']]);
        $res = $this->h(['X-BT-Key' => self::DESKTOP_KEY, 'Content-Type' => 'multipart/form-data; boundary=x'])->post('api/v1/bugtrace/minidump', ['release' => '2.7.0+27001', 'platform' => 'windows', 'process_type' => 'renderer', 'signal' => 'EXCEPTION_ACCESS_VIOLATION', 'installation_ref' => 'inst_hmac:9']);
        service('superglobals')->setFilesArray([]);
        $this->assertSame(202, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $j = $this->json($res);
        $this->assertSame(1, $j['accepted']);
        $this->assertSame('pending', $j['symbolication']);
        $ev = db_connect()->table('bt_events')->get()->getRowArray();
        $this->assertSame('crash', $ev['type']);
        $this->assertSame('pending', $ev['symbolication']);
        $att = db_connect()->table('bt_attachments')->where('kind', 'minidump')->get()->getRowArray();
        $this->assertSame('event', $att['owner_type']);
        $this->assertSame($ev['event_id'], $att['owner_id']);
        $this->assertStringStartsWith('bugtrace/minidumps/', $att['storage_key']);
        $issue = db_connect()->table('bt_issues')->get()->getRowArray();
        $this->assertSame('WS-DSK-100', $issue['public_id']);
        $this->assertSame('EXCEPTION_ACCESS_VIOLATION: Native crash in renderer process', $issue['title']);
        @unlink($tmp);
    }

    public function testSparkCommandsRun(): void
    {
        $this->envelope(['events' => [$this->webError()]])->assertStatus(202);
        foreach (['bugtrace:rollup', 'bugtrace:alerts', 'bugtrace:alerts --digest', 'bugtrace:retention', 'bugtrace:symbolicate'] as $cmd) {
            $this->resetStreamFilterBuffer();
            command($cmd);
            $this->assertStringContainsString(explode(' ', $cmd)[0] . ' —', $this->getStreamFilterBuffer());
        }
        $this->assertSame(1, db_connect()->table('bt_events')->countAllResults());
    }
}

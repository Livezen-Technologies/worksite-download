<?php

namespace Tests\Feature\BugTrace;

require_once __DIR__ . '/BugTraceTestCase.php';

use App\Libraries\BugTrace\Vault;

/**
 * AC-C65 (offline reports delivered later with a reference, idempotent),
 * FR-BT-REP-03/05/06, FR-BT-ISS-05 (inbox, suggestion, link, reply),
 * FR-BT-PRV-05/07 (reporter e-mail and attachments access).
 */
final class ReportsTest extends BugTraceTestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    private function sdk(array $extra = []): array
    {
        return ['X-BT-Key' => self::WEB_KEY] + $extra;
    }

    private function report(array $o = []): array
    {
        return array_replace([
            'client_report_id' => '01J8ZCLIENTREPORT000000001',
            'app'              => 'lk.worksuite.web',
            'release'          => '3.4.0+34012',
            'title'            => 'Mail page crashed when opening Finance folder',
            'description'      => 'I clicked the shared Finance folder and the whole Mail area turned into an error box.',
            'steps'            => ['Mail › Shared › Finance'],
            'expected'         => 'Folder messages list',
            'severity'         => 'BLOCKS_WORK',
            'category'         => 'BUG',
            'user_ref'         => 'u_hmac:3a9f01',
            'context'          => ['browser' => ['name' => 'Chrome', 'version' => '129'], 'app' => ['route' => '/app/mail/:folder']],
            'contact'          => ['consent' => true, 'email' => 'Reporter@Example.lk'],
        ], $o);
    }

    public function testReportReferenceStatusAndIdempotentRetry(): void
    {
        // The crash the user saw, sent before the report.
        $ev = $this->webError();
        $this->envelope(['events' => [$ev]])->assertStatus(202);

        $res = $this->h($this->sdk(), 'json')->post('api/v1/bugtrace/reports', $this->report(['related_event_id' => $ev['event_id']]));
        $this->assertSame(201, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $r = $this->json($res);
        $this->assertSame('BR-20001', $r['reference']);
        $this->assertSame('/api/v1/bugtrace/reports/BR-20001/status', $r['status_url']);
        $this->assertNotEmpty($r['status_token']);

        // Offline queue re-sends the same report: same reference, no duplicate (AC-C65).
        $again = $this->h($this->sdk(), 'json')->post('api/v1/bugtrace/reports', $this->report());
        $again->assertStatus(200);
        $this->assertSame($r, $this->json($again));
        $this->assertSame(1, db_connect()->table('bt_reports')->countAllResults());

        $row = db_connect()->table('bt_reports')->get()->getRowArray();
        $this->assertStringNotContainsString('reporter@example.lk', strtolower(json_encode($row)), 'reporter e-mail is encrypted at rest');
        $this->assertSame('We received your bug report BR-20001', $this->notifier->emails[0]['subject']);
        $this->assertSame('reporter@example.lk', $this->notifier->emails[0]['to']);

        $status = $this->json($this->h(['X-BT-Status-Token' => $r['status_token']])->get($r['status_url']));
        $this->assertSame('received', $status['status']);
        $this->assertSame('BR-20001', $status['reference']);
        $this->h()->get($r['status_url'] . '?token=wrong')->assertStatus(404);

        // Inbox with suggested issue by fingerprint of the related event.
        $qa    = $this->staff(['qa']);
        $inbox = $this->json($this->h($qa)->get('api/v1/admin/bugtrace/reports?status=UNTRIAGED'));
        $this->assertSame(1, $inbox['total']);
        $this->assertSame(1, $inbox['counts']['UNTRIAGED']);
        $this->assertSame('WS-WEB-100', $inbox['data'][0]['suggested_issue']['id']);
        $this->assertSame('BLOCKS_WORK', $inbox['data'][0]['severity']);

        // QA (no bugtrace.reporter.contact) does not see the e-mail.
        $detail = $this->json($this->h($qa)->get('api/v1/admin/bugtrace/reports/BR-20001'));
        $this->assertNull($detail['contact']);
        $this->assertTrue($detail['contact_consent']);
        $this->assertSame(['Mail › Shared › Finance'], $detail['steps']);

        // Link to the suggested issue → reporter notified, status "linked".
        $res = $this->h($qa, 'json')->patch('api/v1/admin/bugtrace/reports/BR-20001', ['issue_id' => 'WS-WEB-100']);
        $this->assertSame(200, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $this->assertSame('LINKED', $this->json($res)['status']);
        $this->assertSame('linked', $this->json($this->h()->get($r['status_url'] . '?token=' . $r['status_token']))['status']);
        $this->h($qa, 'json')->post('api/v1/admin/bugtrace/reports/BR-20001/reply', ['message' => 'Thanks, we are on it.', 'public' => true])->assertStatus(200);
        $this->assertSame('Reply to your bug report BR-20001', end($this->notifier->emails)['subject']);
        $issue = $this->json($this->h($qa)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'));
        $this->assertSame('BR-20001', $issue['reports'][0]['reference']);

        // Support has bugtrace.reporter.contact.
        $sup = $this->staff(['support']);
        $this->assertSame('reporter@example.lk', $this->json($this->h($sup)->get('api/v1/admin/bugtrace/reports/BR-20001'))['contact']['email']);
        $this->seeInDatabase('audit_logs', ['action' => 'bugtrace.reporter.contact_viewed']);
    }

    public function testScreenshotAttachmentEncryptedAndServedOnlyViaSignedUrl(): void
    {
        $up = $this->h($this->sdk(['Content-Type' => 'image/png']), '', self::PNG)->post('api/v1/bugtrace/attachments?kind=screenshot');
        $this->assertSame(201, $up->response()->getStatusCode(), (string) $up->response()->getBody());
        $att = $this->json($up);
        $this->assertStringStartsWith('att_', $att['id']);

        $row = db_connect()->table('bt_attachments')->where('public_id', $att['id'])->get()->getRowArray();
        $this->assertStringStartsWith('bugtrace/', $row['storage_key']);
        $this->assertStringNotContainsString('IHDR', Vault::rawBytes($row['storage_key']), 'encrypted at rest');
        $this->assertSame(self::PNG, Vault::getBytes($row['storage_key']));

        $this->h($this->sdk(), 'json')->post('api/v1/bugtrace/reports', $this->report(['attachments' => [['kind' => 'screenshot', 'upload_id' => $att['id'], 'annotated' => true, 'redacted_regions' => 1]]]))->assertStatus(201);

        $dev    = $this->staff(['developer']);
        $detail = $this->json($this->h($dev)->get('api/v1/admin/bugtrace/reports/BR-20001'));
        $this->assertTrue($detail['has_screenshot']);
        $a = $detail['attachments'][0];
        $this->assertSame(['annotated' => true, 'redacted_regions' => 1], $a['meta']);
        $dl = $this->h($dev)->get(ltrim($a['url'], '/'));
        $this->assertSame(200, $dl->response()->getStatusCode(), (string) $dl->response()->getBody());
        $this->assertSame(self::PNG, (string) $dl->response()->getBody());
        $this->assertSame('image/png', $dl->response()->getHeaderLine('Content-Type') === '' ? '' : explode(';', $dl->response()->getHeaderLine('Content-Type'))[0]);

        // Tampered signature → refused; viewer role (no bugtrace.triage) → refused; anonymous → refused.
        $this->h($dev)->get(ltrim(preg_replace('/sig=[0-9a-f]+/', 'sig=00', $a['url']), '/'))->assertStatus(403);
        $this->staff(['viewer']);
        $this->h()->get(ltrim($a['url'], '/'))->assertStatus(403);
        $this->setCookie('uc_session', null);
        $this->h()->get(ltrim($a['url'], '/'))->assertStatus(401);
    }

    public function testTwoStepUploadAndPolicyForScreenshots(): void
    {
        $init = $this->json($this->h($this->sdk(), 'json')->post('api/v1/bugtrace/attachments', ['kind' => 'log', 'size' => 100, 'content_type' => 'text/plain']));
        $this->assertSame('PUT', $init['method']);
        $put = $this->h(['Content-Type' => 'text/plain'], '', "12:00 sync ok for kasun@example.lk\n12:01 token=abcdef")->call('PUT', ltrim($init['upload_url'], '/'));
        $this->assertSame(200, $put->response()->getStatusCode(), (string) $put->response()->getBody());
        $row = db_connect()->table('bt_attachments')->where('public_id', $init['id'])->get()->getRowArray();
        $this->assertSame('STORED', $row['status']);
        $log = Vault::getBytes($row['storage_key']);
        $this->assertStringNotContainsString('kasun@example.lk', $log, 'logs are scrubbed before storage');
        $this->assertStringNotContainsString('abcdef', $log);

        $tpt = $this->tpt(['policy' => 'CRASHES_ONLY', 'screenshots' => false]);
        $this->h($this->sdk(['Content-Type' => 'image/png', 'X-WorkSuite-Tenant-Policy' => $tpt]), '', self::PNG)->post('api/v1/bugtrace/attachments?kind=screenshot')->assertStatus(403);
        $this->h($this->sdk(['Content-Type' => 'image/png']), '', 'not an image')->post('api/v1/bugtrace/attachments?kind=screenshot')->assertStatus(415);
    }

    public function testTriageFlowsAndPortalList(): void
    {
        $portal = $this->makeUser(['portal_user'], 'portal@example.lk', 'PORTAL');
        $csrf   = $this->actingAs($portal);
        $res    = $this->h($this->sdk($csrf), 'json')->post('api/v1/bugtrace/reports', $this->report(['client_report_id' => null, 'category' => 'Feature request', 'severity' => 'Annoying']));
        $this->assertSame(201, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $token = $this->json($res)['status_token'];
        $mine = $this->json($this->h()->get('api/v1/portal/bug-reports'));
        $this->assertSame('BR-20001', $mine['data'][0]['reference']);
        $this->assertSame('feature_request', $mine['data'][0]['status']);

        $qa    = $this->staff(['qa']);
        $issue = $this->json($this->h($qa, 'json')->post('api/v1/admin/bugtrace/reports/BR-20001/create-issue', []));
        $this->assertStringStartsWith('WS-WEB-', $issue['id']);
        $this->assertSame('LINKED', $this->json($this->h($qa)->get('api/v1/admin/bugtrace/reports/BR-20001'))['status']);
        $this->h($qa, 'json')->patch('api/v1/admin/bugtrace/reports/BR-20001', ['status' => 'CLOSED', 'close_reason' => 'Duplicate of roadmap item'])->assertStatus(200);
        $this->assertSame('closed', $this->json($this->h()->get('api/v1/bugtrace/reports/BR-20001/status?token=' . $token))['status']);
        $this->h($qa, 'json')->patch('api/v1/admin/bugtrace/reports/BR-20001', ['status' => 'LINKED', 'issue_id' => null])->assertStatus(422);
    }

    public function testPublicAnonymousReport(): void
    {
        $res = $this->h([], 'json')->post('api/v1/bugtrace/reports/public', ['app' => 'desktop', 'title' => 'Installer says the file is damaged', 'description' => 'macOS 15', 'captcha_token' => 'dev-ok', 'contact' => ['consent' => false, 'email' => 'x@y.lk']]);
        $this->assertSame(201, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $row = db_connect()->table('bt_reports')->get()->getRowArray();
        $this->assertSame('public', $row['source']);
        $this->assertNull($row['contact_email_enc'], 'no consent → no e-mail kept');
        $this->assertSame('lk.worksuite.desktop', $row['app']);
    }
}

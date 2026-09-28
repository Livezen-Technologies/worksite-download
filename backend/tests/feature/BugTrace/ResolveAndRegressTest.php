<?php

namespace Tests\Feature\BugTrace;

require_once __DIR__ . '/BugTraceTestCase.php';

use App\Services\BugTrace\BugTraceReleaseHooks;

/**
 * AC-C69 (reporters notified when the linked issue is fixed in a published
 * release — WF-C35) and AC-C68 (resolved in X, recurs in ≥ X → REGRESSED + alert).
 */
final class ResolveAndRegressTest extends BugTraceTestCase
{
    private function crashWithReport(): array
    {
        $this->release('server', '3.4.0', 34012);
        $ev = $this->webError();
        $this->envelope(['events' => [$ev]])->assertStatus(202);
        $r = $this->json($this->h(['X-BT-Key' => self::WEB_KEY], 'json')->post('api/v1/bugtrace/reports', [
            'title' => 'Mail crashed', 'severity' => 'BLOCKS_WORK', 'category' => 'BUG', 'release' => '3.4.0+34012',
            'related_event_id' => $ev['event_id'], 'contact' => ['consent' => true, 'email' => 'nimali@example.lk'],
        ]));
        $admin = $this->staff(['developer']);
        $this->h($admin, 'json')->patch('api/v1/admin/bugtrace/reports/' . $r['reference'], ['issue_id' => 'WS-WEB-100'])->assertStatus(200);

        return [$admin, $r];
    }

    public function testResolveInNextReleaseAutoResolvesAndNotifiesReporters(): void
    {
        [$admin, $r] = $this->crashWithReport();
        $res = $this->h($admin, 'json')->patch('api/v1/admin/bugtrace/issues/WS-WEB-100', ['status' => 'RESOLVED', 'resolution' => 'NEXT_RELEASE']);
        $this->assertSame(200, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $issue = $this->json($res);
        $this->assertSame('RESOLVED', $issue['status']);
        $this->assertSame('NEXT_RELEASE', $issue['resolution']);
        $this->assertNull($issue['resolve_version']);

        // An internal build does not carry the fix to customers; a DEV publish is ignored.
        (new BugTraceReleaseHooks())->onReleasePublished($this->release('server', '3.4.1', 34100, 'PUBLISHED', 'DEV'));
        $this->assertNull($this->json($this->h($admin)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'))['resolve_version']);

        $emailsBefore = count($this->notifier->emails);
        $published    = $this->release('server', '3.4.1', 34101);
        (new BugTraceReleaseHooks())->onReleasePublished($published);

        $issue = $this->json($this->h($admin)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'));
        $this->assertSame('RESOLVED', $issue['status']);
        $this->assertSame('3.4.1', $issue['resolve_version']);
        $status = $this->json($this->h()->get($r['status_url'] . '?token=' . $r['status_token']));
        $this->assertSame('fixed', $status['status']);
        $this->assertSame('3.4.1', $status['fixed_in']);
        $this->assertSame('Fixed in 3.4.1 — update available.', end($status['updates'])['message']);
        $mails = array_slice($this->notifier->emails, $emailsBefore);
        $this->assertCount(1, $mails);
        $this->assertSame('nimali@example.lk', $mails[0]['to']);
        $this->assertStringContainsString('3.4.1', $mails[0]['subject']);
        $this->seeInDatabase('audit_logs', ['action' => 'bugtrace.issue.auto_resolved', 'entity_id' => 'WS-WEB-100']);

        // Publishing again does not notify twice.
        (new BugTraceReleaseHooks())->onReleasePublished($published);
        $this->assertCount($emailsBefore + 1, $this->notifier->emails);

        // WF-C35 step 3: it occurs in ≥ 3.4.1 → REGRESSED and alerts.
        $this->notifier->notifications = [];
        $this->envelope(['events' => [$this->webError(['release' => '3.4.0+34012'])]])->assertStatus(202);
        $this->assertSame('RESOLVED', $this->json($this->h($admin)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'))['status'], 'old version in the field does not regress');
        $this->envelope(['events' => [$this->webError(['release' => '3.4.1+34101'])]])->assertStatus(202);
        $issue = $this->json($this->h($admin)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'));
        $this->assertSame('REGRESSED', $issue['status']);
        $this->assertNotNull($issue['regressed_at']);
        $titles = array_column(array_column($this->notifier->notifications, 'payload'), 'title');
        $this->assertContains('Regression WS-WEB-100', $titles);
        $this->assertSame(1, db_connect()->table('bt_alert_events')->where('kind', 'regression')->countAllResults());
    }

    public function testResolvedInVersionRegressionAlertsAssignee(): void
    {
        [$admin] = $this->crashWithReport();
        $dev     = db_connect()->table('users')->where('kind', 'STAFF')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->h($admin, 'json')->patch('api/v1/admin/bugtrace/issues/WS-WEB-100', ['assignee_id' => $dev['public_id'], 'priority' => 'P1'])->assertStatus(200);
        $issue = $this->json($this->h($admin)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'));
        $this->assertSame('TRIAGED', $issue['status'], 'owner set → TRIAGED');
        $this->assertSame('P1', $issue['priority']);

        $res = $this->h($admin, 'json')->patch('api/v1/admin/bugtrace/issues/WS-WEB-100', ['resolution' => 'VERSION', 'resolve_version' => '3.4.2']);
        $this->assertSame('RESOLVED', $this->json($res)['status']);
        $status = db_connect()->table('bt_report_updates')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertStringContainsString('3.4.2', $status['message']);

        $this->notifier->notifications = [];
        $this->envelope(['events' => [$this->webError(['release' => '3.4.1+34101'])]])->assertStatus(202);
        $this->assertSame('RESOLVED', $this->json($this->h($admin)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'))['status']);
        $this->envelope(['events' => [$this->webError(['release' => '3.4.2+34200'])]])->assertStatus(202);
        $this->assertSame('REGRESSED', $this->json($this->h($admin)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'))['status']);
        $toAssignee = array_filter($this->notifier->notifications, static fn ($n) => $n['users'] === [(int) $dev['id']]);
        $this->assertNotEmpty($toAssignee, 'the assignee is alerted about the regression');

        // Validation: VERSION needs a version; DUPLICATE only via merge.
        $this->h($admin, 'json')->patch('api/v1/admin/bugtrace/issues/WS-WEB-100', ['resolution' => 'VERSION'])->assertStatus(422);
        $this->h($admin, 'json')->patch('api/v1/admin/bugtrace/issues/WS-WEB-100', ['status' => 'DUPLICATE'])->assertStatus(422);
    }

    public function testWithdrawnReleaseRevertsNextReleaseResolution(): void
    {
        [$admin, $r] = $this->crashWithReport();
        $this->h($admin, 'json')->patch('api/v1/admin/bugtrace/issues/WS-WEB-100', ['status' => 'RESOLVED', 'resolution' => 'NEXT_RELEASE'])->assertStatus(200);
        $rel   = $this->release('server', '3.4.1', 34101);
        $hooks = new BugTraceReleaseHooks();
        $hooks->onReleasePublished($rel);
        $hooks->onReleaseWithdrawn($rel);
        $this->assertNull($this->json($this->h($admin)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'))['resolve_version']);
        $this->assertSame('linked', $this->json($this->h()->get($r['status_url'] . '?token=' . $r['status_token']))['status']);
    }

    public function testIgnoreUntilCountAndKnownIssues(): void
    {
        [$admin] = $this->crashWithReport();
        $this->h($admin, 'json')->patch('api/v1/admin/bugtrace/issues/WS-WEB-100', ['status' => 'IGNORED', 'ignore_until' => ['count' => 2], 'known_issue_public' => 'Opening shared folders may fail; reload the page.'])->assertStatus(200);
        $this->envelope(['events' => [$this->webError()]])->assertStatus(202);
        $this->assertSame('IGNORED', $this->json($this->h($admin)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'))['status']);
        $this->envelope(['events' => [$this->webError()]])->assertStatus(202);
        $this->assertSame('NEW', $this->json($this->h($admin)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'))['status']);
        $rel = db_connect()->table('releases')->where('version', '3.4.0')->get()->getRowArray();
        $this->assertSame([['id' => 'WS-WEB-100', 'text' => 'Opening shared folders may fail; reload the page.']], (new BugTraceReleaseHooks())->knownIssues((int) $rel['id']));
    }
}

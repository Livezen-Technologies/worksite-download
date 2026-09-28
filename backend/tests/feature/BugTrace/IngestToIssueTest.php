<?php

namespace Tests\Feature\BugTrace;

require_once __DIR__ . '/BugTraceTestCase.php';

use Tests\Unit\BugTrace\ScrubberTest;
use Tests\Unit\BugTrace\SourceMapTest;

require_once __DIR__ . '/../../unit/BugTrace/SourceMapTest.php';
require_once __DIR__ . '/../../unit/BugTrace/ScrubberTest.php';

/**
 * AC-C59 (web crash → issue with symbolicated stack), AC-C67 (release
 * linkage, first-seen release), AC-C66 (grouping, merge/split), AC-C71
 * (seeded PII never stored end-to-end).
 */
final class IngestToIssueTest extends BugTraceTestCase
{
    private function uploadMap(array $release): void
    {
        $ci  = $this->makeClient(['bugtrace.symbols.upload']);
        $res = $this->withHeaders($ci + ['Content-Type' => 'application/json'])->withBody(json_encode(SourceMapTest::mailMap()))
            ->post('api/v1/admin/bugtrace/symbols?release_id=' . $release['public_id'] . '&file_name=mail-8c1.js.map&artifact=assets/mail-8c1.js');
        $this->assertSame(201, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $j = $this->json($res);
        $this->assertSame('sourcemap', $j['kind']);
        $this->assertSame('3.4.0+34012', $j['release']);
        $this->assertArrayNotHasKey('storage_key', $j);
    }

    public function testWebCrashBecomesSymbolicatedIssueLinkedToRelease(): void
    {
        $release = $this->release('server', '3.4.0', 34012);
        $this->uploadMap($release);

        $ev  = $this->webError();
        $res = $this->envelope(['events' => [$ev], 'sessions' => [['sid' => 's1', 'init' => true, 'status' => 'started', 'release' => '3.4.0+34012', 'user_ref' => 'u_hmac:3a9f01']]]);
        $this->assertSame(202, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $j = $this->json($res);
        $this->assertSame([$ev['event_id']], $j['ids']);
        $this->assertSame(1, $j['sessions']);
        $this->assertSame('CRASHES_AND_ERRORS', $j['policy']['policy']);

        $admin = $this->staff(['developer']);
        $list  = $this->json($this->withHeaders($admin)->get('api/v1/admin/bugtrace/issues'));
        $this->assertSame(1, $list['total']);
        $issue = $list['data'][0];
        $this->assertSame('WS-WEB-100', $issue['id']);
        $this->assertSame('crash', $issue['type']);
        $this->assertSame('NEW', $issue['status']);
        $this->assertSame('3.4.0+34012', $issue['first_release']);
        $this->assertSame(['id' => 'btp_web', 'name' => 'WorkSuite Web client', 'platform' => 'web'], $issue['project']);
        $this->assertSame(1, $issue['events_count']);
        $this->assertSame(1, $issue['users_count']);
        $this->assertCount(24, $issue['sparkline']);
        $this->assertSame(1, array_sum($issue['sparkline']));
        $this->assertSame(1, $list['kpis']['unresolved']);
        $this->assertSame(1, $list['kpis']['events_24h']);

        $detail = $this->json($this->withHeaders($admin)->get('api/v1/admin/bugtrace/issues/WS-WEB-100'));
        $top    = $detail['frames'][0];
        $this->assertSame('src/pages/mail/MailFolderList.tsx', $top['file']);
        $this->assertSame(142, $top['line']);
        $this->assertSame('renderFolder', $top['function'], 'function name recovered from the caller frame');
        $this->assertSame('const parent = byId[current.parent].folderId;', $top['context']['line']);
        $this->assertTrue($top['in_app']);
        $this->assertSame('ok', $detail['symbolication']);
        $this->assertSame($release['public_id'], $detail['latest_event']['release_id']);
        $this->assertTrue($detail['latest_event']['release_known']);
        $this->assertSame('Chrome 129', $detail['latest_event']['browser']);
        $this->assertSame([['name' => '3.4.0+34012', 'count' => 1]], $detail['distributions']['release']);
        $this->assertSame('3.4.0+34012', $detail['releases'][0]['release']);
        $this->assertArrayNotHasKey('tenant_count', $detail, 'tenant attribution needs bugtrace.tenant.view');
        $this->assertSame('navigation', $detail['breadcrumbs'][0]['category']);

        $issueRow = db_connect()->table('bt_issues')->where('public_id', 'WS-WEB-100')->get()->getRowArray();
        $this->assertSame((int) $release['id'], (int) $issueRow['first_release_id']);
        $this->assertSame(1, (int) db_connect()->table('bt_sessions_daily')->where('release_id', $release['id'])->get()->getRow('sessions'));

        // The same error with another message / column groups into the same issue.
        $ev2 = $this->webError(['release' => '3.4.0+34012', 'user_ref' => 'u_hmac:other']);
        $ev2['exception'][0]['stacktrace']['frames'][1]['col'] = 40260; // same original line, other column
        $ev2['exception'][0]['value'] = "Cannot read properties of undefined (reading 'parentId')";
        $ev3 = $this->webError(['release' => '9.9.9-dev+1']);
        $ev3['exception'][0]['type'] = 'RangeError';
        $this->envelope(['events' => [$ev2, $ev3]], self::WEB_KEY, null, false)->assertStatus(202);

        $list = $this->json($this->withHeaders($admin)->get('api/v1/admin/bugtrace/issues?sort=events'));
        $this->assertSame(2, $list['total']);
        $unknown = db_connect()->table('bt_events')->where('event_id', $ev3['event_id'])->get()->getRowArray();
        $this->assertSame(0, (int) $unknown['release_known'], 'unknown versions are flagged');
        $this->assertNull($unknown['release_id']);
        $this->assertSame(1, $this->json($this->withHeaders($admin)->get('api/v1/admin/bugtrace/issues?release=' . $release['public_id']))['total']);
        $this->assertSame(1, $this->json($this->withHeaders($admin)->get('api/v1/admin/bugtrace/issues?q=RangeError'))['total']);

        // Client retry of the same event id does not double count.
        $this->envelope(['events' => [$ev]])->assertStatus(202);
        $this->assertSame(1, db_connect()->table('bt_events')->where('event_id', $ev['event_id'])->countAllResults());
        $issueRow = db_connect()->table('bt_issues')->where('public_id', 'WS-WEB-100')->get()->getRowArray();
        $this->assertSame(2, (int) $issueRow['events_count']);
        $this->assertSame(2, (int) $issueRow['users_count']);
        $this->assertTrue(in_array('bugtrace.alert', $this->notifier->kinds(), true), 'default "new crash issue" rule alerted');
    }

    public function testMergeAndSplit(): void
    {
        $a = $this->webError();
        $b = $this->webError(['user_ref' => 'u_hmac:b']);
        $b['exception'][0]['type'] = 'ReferenceError';
        $this->envelope(['events' => [$a, $b]])->assertStatus(202);
        $admin = $this->staff(['qa']);
        $ids   = array_column($this->json($this->withHeaders($admin)->get('api/v1/admin/bugtrace/issues'))['data'], 'id');
        sort($ids);
        [$first, $second] = $ids;

        $res = $this->withHeaders($admin)->withBodyFormat('json')->post("api/v1/admin/bugtrace/issues/{$second}/merge", ['into' => $first]);
        $this->assertSame(200, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $merged = $this->json($res);
        $this->assertSame(2, $merged['events_count']);
        $this->assertSame(2, $merged['users_count']);
        $list = $this->json($this->withHeaders($admin)->get('api/v1/admin/bugtrace/issues'));
        $this->assertSame(1, $list['total'], 'duplicates are hidden from the default list');

        // New events with the merged fingerprint go to the target.
        $c = $this->webError(['user_ref' => 'u_hmac:c']);
        $c['exception'][0]['type'] = 'ReferenceError';
        $this->envelope(['events' => [$c]])->assertStatus(202);
        $this->assertSame(3, $this->json($this->withHeaders($admin)->get("api/v1/admin/bugtrace/issues/{$first}"))['events_count']);

        $split = $this->json($this->withHeaders($admin)->withBodyFormat('json')->post("api/v1/admin/bugtrace/issues/{$second}/split", []));
        $this->assertSame('NEW', $split['status']);
        $this->assertSame(2, $split['events_count']);
        $target = $this->json($this->withHeaders($admin)->get("api/v1/admin/bugtrace/issues/{$first}"));
        $this->assertSame(1, $target['events_count']);
        $this->assertSame(1, $target['users_count']);
        $this->seeInDatabase('audit_logs', ['action' => 'bugtrace.issue.merged']);
    }

    public function testSeededPiiIsNeverStoredAnywhere(): void
    {
        $ev = ScrubberTest::seededEvent();
        $ev['type'] = 'crash';
        $this->envelope(['events' => [$ev]])->assertStatus(202);
        $this->withHeaders(['X-BT-Key' => self::WEB_KEY])->withBodyFormat('json')->post('api/v1/bugtrace/reports', [
            'client_report_id' => 'client-report-pii-1', 'title' => 'Card 4111111111111111 rejected for kasun.perera@example.lk',
            'description' => 'My NIC 901234567V and phone +94 77 123 4567, password=S3cr3tPassw0rd!', 'severity' => 'MINOR', 'category' => 'BUG',
            'context' => $ev['contexts'],
        ])->assertStatus(201);

        $db   = db_connect();
        $dump = '';
        foreach ($db->listTables() as $t) {
            if (str_starts_with($t, 'bt_') || $t === 'jobs') {
                $dump .= json_encode($db->table($t)->get()->getResultArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        $this->assertNotSame('', $dump);
        foreach (ScrubberTest::SEEDS as $seed) {
            $this->assertStringNotContainsString($seed, $dump, "Seed stored: {$seed}");
        }
        $row = $db->table('bt_events')->where('event_id', $ev['event_id'])->get()->getRowArray();
        $this->assertGreaterThan(10, (int) $row['scrub_count']);
    }

    public function testKeyAndSizeLimits(): void
    {
        $this->withHeaders(['X-BT-Key' => 'pk_unknown_0000000000'])->withBody('{}')->post('api/v1/bugtrace/envelope')->assertStatus(401);
        $this->withBody('{}')->post('api/v1/bugtrace/envelope')->assertStatus(401);
        $big = gzencode(random_bytes(1100000), 0);
        $res = $this->withHeaders(['X-BT-Key' => self::WEB_KEY, 'Content-Encoding' => 'gzip'])->withBody($big)->post('api/v1/bugtrace/envelope');
        $res->assertStatus(413);
        // Sentry-style newline envelope is accepted too.
        $ev  = $this->webError();
        $raw = json_encode(['event_id' => $ev['event_id']]) . "\n" . json_encode(['type' => 'event']) . "\n" . json_encode($ev) . "\n";
        $res = $this->withHeaders(['X-BT-Key' => self::WEB_KEY, 'Content-Type' => 'application/x-sentry-envelope'])->withBody($raw)->post('api/v1/bugtrace/envelope');
        $this->assertSame(202, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $this->assertSame(1, $this->json($res)['accepted']);
    }

    public function testQuotaCountsButDoesNotStoreExcess(): void
    {
        db_connect()->table('bt_projects')->where('public_key', self::WEB_KEY)->update(['rate_limit' => 2]);
        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->webError(['event_id' => 'quota-event-000' . $i]);
        }
        $j = $this->json($this->envelope(['events' => $events]));
        $this->assertSame(2, $j['accepted']);
        $this->assertSame(3, $j['dropped']['quota']);
        $this->assertSame(2, db_connect()->table('bt_events')->countAllResults());
        $this->assertSame(3, (int) db_connect()->table('bt_quota_counters')->like('bucket', 'i:', 'after')->get()->getRow('dropped'));
    }

    public function testServerSideSamplingForUnsampledHandledErrors(): void
    {
        $events = [];
        for ($i = 0; $i < 40; $i++) {
            $events[] = $this->webError(['type' => 'error', 'event_id' => sprintf('sampling-evt-%04d', $i)]);
        }
        $j = $this->json($this->envelope(['events' => $events]));
        $this->assertGreaterThan(0, $j['dropped']['sampled']);
        $this->assertSame(40, $j['accepted'] + $j['dropped']['sampled']);
        // Events sampled by the SDK (sample_rate present) are not sampled again.
        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = $this->webError(['type' => 'error', 'sample_rate' => 0.25, 'event_id' => sprintf('sdk-sampled-%04d', $i)]);
        }
        $this->assertSame(10, $this->json($this->envelope(['events' => $events]))['accepted']);
    }
}

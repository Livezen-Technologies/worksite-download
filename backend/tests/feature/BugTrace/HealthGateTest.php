<?php

namespace Tests\Feature\BugTrace;

require_once __DIR__ . '/BugTraceTestCase.php';

use App\Services\BugTrace\ReleaseHealthService;

/**
 * AC-C70 / FR-BT-HLT-01/02 / WF-C36: Release Health shows crash-free users
 * and sessions per release and fires rollout gate alerts.
 */
final class HealthGateTest extends BugTraceTestCase
{
    /** Send $n sessions for $n distinct users, $crashed of them crashing. */
    private function sessions(string $release, int $n, int $crashed, string $key = self::DESKTOP_KEY, string $prefix = 'u'): void
    {
        $items = [];
        for ($i = 0; $i < $n; $i++) {
            $items[] = ['sid' => "{$prefix}-s{$i}", 'init' => true, 'status' => 'started', 'release' => $release, 'user_ref' => "u_hmac:{$prefix}{$i}"];
            $items[] = ['sid' => "{$prefix}-s{$i}", 'status' => $i < $crashed ? 'crashed' : 'exited', 'release' => $release, 'user_ref' => "u_hmac:{$prefix}{$i}"];
        }
        foreach (array_chunk($items, 400) as $chunk) {
            $this->envelope(['sessions' => $chunk], $key)->assertStatus(202);
        }
    }

    public function testCrashFreeRatesAndGate(): void
    {
        $prev = $this->release('desktop', '2.6.5', 26500, 'SUPERSEDED', 'STABLE', gmdate('Y-m-d H:i:s', time() - 20 * 86400));
        $cur  = $this->release('desktop', '2.7.0', 27001);
        $this->sessions('2.6.5+26500', 200, 1, self::DESKTOP_KEY, 'p');
        $this->sessions('2.7.0+27001', 200, 4);

        $gate = (new ReleaseHealthService())->gateFor((int) $cur['id']);
        $this->assertSame('BLOCK', $gate['state']);
        $this->assertSame(98.0, $gate['crash_free_users']);
        $this->assertStringContainsString('Crash-free users 98.00% is below 99.0%', $gate['reasons'][0]);
        $this->assertStringContainsString('dropped 1.50 pt vs 2.6.5', $gate['reasons'][1]);

        $prevGate = (new ReleaseHealthService())->gateFor((int) $prev['id']);
        $this->assertSame('OK', $prevGate['state']);
        $this->assertSame(99.5, $prevGate['crash_free_users']);
        $this->assertSame(['state' => 'OK', 'reasons' => [], 'crash_free_users' => null], (new ReleaseHealthService())->gateFor(999999));

        $admin = $this->staff(['viewer']);
        $h     = $this->json($this->h($admin)->get('api/v1/admin/bugtrace/health?app=desktop'));
        $rows  = array_column($h['releases'], null, 'release');
        $this->assertSame(98.0, $rows['2.7.0']['crash_free_users']);
        $this->assertSame(98.0, $rows['2.7.0']['crash_free_sessions']);
        $this->assertSame(200, $rows['2.7.0']['sessions']);
        $this->assertSame('BLOCK', $rows['2.7.0']['gate']);
        $this->assertSame('OK', $rows['2.6.5']['gate']);
        $this->assertSame(50.0, $rows['2.7.0']['adoption_pct'], 'session share when no device telemetry');
        $this->assertSame(99.0, $h['gate_rules']['crash_free_users_below']);
        $this->assertNotEmpty(array_filter($h['series'], static fn ($p) => $p['release'] === '2.7.0' && $p['crash_free_users'] === 98.0));

        // CI can query health with release.view.
        $ci = $this->makeClient(['release.view']);
        $this->setCookie('uc_session', null);
        $one = $this->json($this->h($ci)->get('api/v1/admin/bugtrace/health?release_id=' . $cur['public_id']));
        $this->assertCount(1, $one['releases']);
        $this->assertSame('BLOCK', $one['releases'][0]['gate']);

        // Gate alerts reach the Release Manager once per state (WF-C36).
        $res = (new ReleaseHealthService())->evaluateGates();
        $this->assertContains(['release_id' => $cur['public_id'], 'state' => 'BLOCK', 'reasons' => $gate['reasons']], $res);
        $this->assertContains('rollout.alert', $this->notifier->kinds());
        $n = count($this->notifier->notifications);
        (new ReleaseHealthService())->evaluateGates();
        $this->assertCount($n, $this->notifier->notifications, 'no duplicate gate alert');
    }

    public function testNewP0IssueAndInstallFailures(): void
    {
        $cur = $this->release('desktop', '2.7.0', 27001);
        $this->sessions('2.7.0+27001', 100, 0);
        $this->assertSame('OK', (new ReleaseHealthService())->gateFor((int) $cur['id'])['state']);

        // Install failures above 5 % → WARN (update telemetry linked to release health).
        $db = db_connect();
        for ($i = 0; $i < 30; $i++) {
            $db->table('update_events')->insert(['event_id' => "ue-{$i}", 'device_ref' => "hmac:{$i}", 'application_id' => $cur['application_id'], 'release_id' => $cur['id'], 'type' => $i < 3 ? 'install_failed' : 'install_succeeded', 'error_code' => $i < 3 ? 'UPDATE_DISK_FULL' : null, 'platform' => 'windows', 'occurred_at' => gmdate('Y-m-d H:i:s'), 'received_at' => gmdate('Y-m-d H:i:s')]);
        }
        $gate = (new ReleaseHealthService())->gateFor((int) $cur['id']);
        $this->assertSame('WARN', $gate['state']);
        $this->assertStringContainsString('Update install failures 10.0% above 5.0%', $gate['reasons'][0]);

        // New P1 issue first seen in 2.7.0 affecting ≥ N users → BLOCK.
        $admin = $this->staff(['release_manager']);
        $this->h($admin, 'json')->put('api/v1/admin/bugtrace/health/gate-rules', ['new_p0p1_min_users' => 3])->assertStatus(200);
        $events = [];
        for ($i = 0; $i < 3; $i++) {
            $events[] = $this->webError(['platform' => 'windows', 'release' => '2.7.0+27001', 'user_ref' => "u_hmac:x{$i}"]);
        }
        $this->envelope(['events' => $events], self::DESKTOP_KEY)->assertStatus(202);
        $this->h($admin, 'json')->patch('api/v1/admin/bugtrace/issues/WS-DSK-100', ['priority' => 'P1'])->assertStatus(200);
        $gate = (new ReleaseHealthService())->gateFor((int) $cur['id']);
        $this->assertSame('BLOCK', $gate['state']);
        $this->assertContains('New P1 issue WS-DSK-100 affects 3 users', $gate['reasons']);
        $h = $this->json($this->h($admin)->get('api/v1/admin/bugtrace/health?release=2.7.0'));
        $this->assertSame(1, $h['releases'][0]['new_issues']);
        $this->assertSame(3, $h['releases'][0]['update_failures']);
        $csv = (string) $this->h($admin)->get('api/v1/admin/bugtrace/health?format=csv')->response()->getBody();
        $this->assertStringContainsString('2.7.0', $csv);
    }
}

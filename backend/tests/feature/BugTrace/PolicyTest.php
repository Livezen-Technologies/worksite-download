<?php

namespace Tests\Feature\BugTrace;

require_once __DIR__ . '/BugTraceTestCase.php';

/**
 * AC-C72 / FR-BT-SDK-10 / §18.9: the ingest API enforces the tenant policy
 * from the TPT even when a client misbehaves; OFF drops everything.
 */
final class PolicyTest extends BugTraceTestCase
{
    public function testPolicyOffDropsAllAutomaticReporting(): void
    {
        $tpt = $this->tpt(['policy' => 'OFF', 'user_reports' => true]);
        $res = $this->envelope(['events' => [$this->webError(), $this->webError(['type' => 'error', 'sample_rate' => 1])], 'sessions' => [['sid' => 'x', 'init' => true, 'release' => '3.4.0']]], self::WEB_KEY, $tpt);
        $this->assertSame(202, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $j = $this->json($res);
        $this->assertSame(0, $j['accepted']);
        $this->assertSame(2, $j['dropped']['policy']);
        $this->assertSame(0, $j['sessions']);
        $this->assertSame('OFF', $j['policy']['policy'], 'online clients learn the new policy immediately (WF-C38)');
        $this->assertSame(0, db_connect()->table('bt_events')->countAllResults());
        $this->assertSame(0, db_connect()->table('bt_sessions_daily')->countAllResults());
        $this->assertSame(0, db_connect()->table('bt_issues')->countAllResults());

        // Minidumps are dropped too.
        $pol = $this->json($this->withHeaders(['X-WorkSuite-Tenant-Policy' => $tpt])->get('api/v1/bugtrace/policy'));
        $this->assertSame('OFF', $pol['policy']);
        $this->assertSame(86400, $pol['ttl']);
        $this->assertTrue($pol['user_reports']);
    }

    public function testCrashesOnlyDropsHandledErrors(): void
    {
        $tpt = $this->tpt(['policy' => 'CRASHES_ONLY']);
        $j   = $this->json($this->envelope(['events' => [$this->webError(), $this->webError(['type' => 'error', 'sample_rate' => 1]), $this->webError(['type' => 'message', 'sample_rate' => 1])]], self::WEB_KEY, $tpt));
        $this->assertSame(1, $j['accepted']);
        $this->assertSame(2, $j['dropped']['policy']);
        $ev = db_connect()->table('bt_events')->get()->getRowArray();
        $this->assertSame('tnt_01TEST', $ev['tenant_ref'], 'tenant attributed from the TPT');
    }

    public function testFreeEditionDefaultsToCrashesOnlyAndMirrorRowApplies(): void
    {
        $this->assertSame('CRASHES_ONLY', $this->json($this->withHeaders(['X-WorkSuite-Tenant-Policy' => $this->tpt(null, 'tnt_FREE', 'FREE')])->get('api/v1/bugtrace/policy'))['policy']);
        $this->assertSame('CRASHES_AND_ERRORS', $this->json($this->withHeaders(['X-WorkSuite-Tenant-Policy' => $this->tpt(null, 'tnt_BIZ', 'BUSINESS')])->get('api/v1/bugtrace/policy'))['policy']);

        // Update Center mirror row (bt_tenant_policies) applies when the TPT has no claim.
        $admin = $this->staff(['super_admin']);
        $res   = $this->withHeaders($admin)->withBodyFormat('json')->put('api/v1/admin/bugtrace/policies/tnt_BIZ', ['policy' => 'OFF', 'user_reports' => false, 'reason' => 'Customer request']);
        $this->assertSame(200, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $this->seeInDatabase('audit_logs', ['action' => 'bugtrace.policy.updated', 'entity_id' => 'tnt_BIZ']);
        $tpt = $this->tpt(null, 'tnt_BIZ', 'BUSINESS');
        $this->setCookie('uc_session', null); // SDK requests carry no Update Center cookies
        $this->assertSame(0, $this->json($this->envelope(['events' => [$this->webError()]], self::WEB_KEY, $tpt))['accepted']);
        $rep = $this->withHeaders(['X-BT-Key' => self::WEB_KEY, 'X-WorkSuite-Tenant-Policy' => $tpt])->withBodyFormat('json')->post('api/v1/bugtrace/reports', ['title' => 'x', 'severity' => 'MINOR']);
        $rep->assertStatus(403);
        $this->assertSame('POLICY_DISABLED', $this->json($rep)['error']['code']);

        $this->staff(['super_admin']);
        $list = $this->json($this->get('api/v1/admin/bugtrace/policies'));
        $this->assertSame('OFF', $list['data'][0]['policy']);
        $this->assertSame('CRASHES_ONLY', $list['defaults']['self_hosted']['FREE']);
    }

    public function testServerReportingSwitchAndInvalidTpt(): void
    {
        $tpt = $this->tpt(['policy' => 'CRASHES_AND_ERRORS', 'server_reporting' => false]);
        $this->assertSame(0, $this->json($this->envelope(['events' => [$this->webError(['platform' => 'server'])]], self::SERVER_KEY, $tpt))['accepted']);
        $this->assertSame(1, $this->json($this->envelope(['events' => [$this->webError()]], self::WEB_KEY, $tpt))['accepted']);

        // An invalid TPT gets defaults (no tenant attribution) — never an error for the client.
        $pol = $this->json($this->withHeaders(['X-WorkSuite-Tenant-Policy' => 'garbage'])->get('api/v1/bugtrace/policy'));
        $this->assertSame('TENANT_POLICY_INVALID', $pol['warning']);
        $this->assertSame('default', $pol['source']);
    }

    public function testRequiredByContractDisablesUserOptOut(): void
    {
        $tpt = $this->tpt(['policy' => 'CRASHES_ONLY', 'required_by_contract' => true]);
        $this->assertFalse($this->json($this->withHeaders(['X-WorkSuite-Tenant-Policy' => $tpt])->get('api/v1/bugtrace/policy'))['user_opt_out_allowed']);
    }
}

<?php

namespace Tests\Feature;

use App\Libraries\Signing\Ed25519;
use App\Libraries\Storage\StorageFactory;
use App\Services\Catalog;
use App\Services\KeyRegistry;
use App\Services\ServerRingService;
use Tests\Support\Fixtures;
use Tests\Support\TrustFixture;
use Tests\Support\UcTestCase;

/**
 * WS-CORE-SYSUPDATE-SRS-001, portal side: installation registration, signed auto-update
 * plan, policy sync with If-Match (412), the publish two-person rule (FR-DL-REL-04),
 * public SHA256SUMS + .sig (FR-DL-REL-05), withdraw cancelling plans (AC-SU-14) and the
 * fleet health gate pausing ring 2 (AC-SU-15).
 */
final class ServerAutoUpdateTest extends UcTestCase
{
    private array $dev;
    private array $qa;
    private array $rm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dev = $this->makeUser(['developer'], 'dev@livezen.lk');
        $this->qa  = $this->makeUser(['qa'], 'qa@livezen.lk');
        $this->rm  = $this->makeUser(['release_manager'], 'rm@livezen.lk');
    }

    private function api(array $user, string $method, string $uri, array $body = []): array
    {
        $res = $this->withHeaders($this->actingAs($user))->withBodyFormat('json')->call($method, $uri, $body);

        return [$res->response()->getStatusCode(), $this->json($res)];
    }

    /** Call the server API as an installation (no staff session). */
    private function server(string $method, string $uri, array $headers, ?array $body = null): array
    {
        $this->setCookie('uc_session', null);
        $req = $this->withHeaders($headers);
        if ($body !== null) {
            $req = $req->withBodyFormat('json');
        }
        $res = $req->call($method, $uri, $body ?? []);

        return [$res->response()->getStatusCode(), $this->json($res), $res];
    }

    private function verifyResponse(array $doc): bool
    {
        $cfg = config('UpdateCenter');

        return Ed25519::verifyDocument($doc, [$cfg->responseKeyId => service('responseKeys')->publicKey($cfg->responseKeyId)]);
    }

    private function register(TrustFixture $trust, array $extra = []): array
    {
        [$s, $j] = $this->server('POST', 'api/v1/server/installations/register', [], ['license_token' => $trust->license(), 'name' => $trust->installationId . '.example', 'version' => '3.3.0', 'build' => 33001, 'channel' => 'stable'] + $extra);
        $this->assertContains($s, [200, 201], json_encode($j));

        return $j;
    }

    private function tusUpload(array $user, string $rel, array $meta, string $bytes): void
    {
        [$s, $j] = $this->api($user, 'POST', "api/v1/admin/releases/{$rel}/packages", $meta + ['size' => strlen($bytes)]);
        $this->assertSame(201, $s, json_encode($j));
        $csrf              = $this->actingAs($user)['X-CSRF-Token'];
        $this->bodyFormat  = '';
        $this->requestBody = '';
        $this->withHeaders(['X-CSRF-Token' => $csrf, 'Tus-Resumable' => '1.0.0', 'Upload-Length' => (string) strlen($bytes), 'Upload-Metadata' => 'upload_id ' . base64_encode($j['upload']['id'])])->post('api/v1/uploads/tus')->assertStatus(201);
        $this->withHeaders(['X-CSRF-Token' => $csrf, 'Tus-Resumable' => '1.0.0', 'Upload-Offset' => '0', 'Content-Type' => 'application/offset+octet-stream'])->withBody($bytes)->patch('api/v1/uploads/tus/' . $j['upload']['id'])->assertStatus(204);
        $this->requestBody = '';
    }

    /** DL-2 flow up to TESTING with a QA pass: create, upload the Business ZIP, staging test, submit, QA. */
    private function serverReleaseInTesting(string $version, int $build, string $severity = 'MINOR', ?array $qaUser = null): string
    {
        [$s, $rel] = $this->api($this->dev, 'POST', 'api/v1/admin/server/releases', [
            'version' => $version, 'build' => $build, 'commit' => '07ebd36c', 'channel' => 'stable', 'severity' => $severity, 'min_from_version' => '3.2.0',
            'notes' => "## New Features\n- Rental Management\n\n## Bug Fixes\n- Mail folder list", 'known_issues' => 'None', 'upgrade_notes' => 'Run during a window.',
            'migrations_reversible' => true, 'editions' => ['business'], 'desktop_compat' => '2.6.0 - 2.8.x', 'admin_notice' => 'Brief downtime (~2 min).',
        ]);
        $this->assertSame(201, $s, json_encode($rel));
        $this->assertSame($severity, $rel['severity']);
        $id  = $rel['id'];
        $tmp = sys_get_temp_dir() . '/uc-su-' . getmypid() . '.zip';
        Fixtures::serverZip($tmp, $version, $build, 'BUSINESS', ['core', 'mail', 'crm']);
        $this->tusUpload($this->dev, $id, ['edition' => 'BUSINESS', 'file_name' => "worksuite-server-business-{$version}+{$build}.zip"], (string) file_get_contents($tmp));
        unlink($tmp);
        $this->api($this->dev, 'PUT', "api/v1/admin/releases/{$id}/server-meta", ['staging_test' => ['status' => 'PASSED']]);
        [$s, $j] = $this->api($this->dev, 'POST', "api/v1/admin/releases/{$id}/submit-test");
        $this->assertSame(200, $s, json_encode($j));
        [$s, $j] = $this->api($qaUser ?? $this->qa, 'POST', "api/v1/admin/releases/{$id}/qa", ['decision' => 'PASS', 'comment' => 'staging ok']);
        $this->assertSame(200, $s, json_encode($j));

        return $id;
    }

    private function publishedServerRelease(string $version, int $build, string $severity = 'MINOR'): string
    {
        $id      = $this->serverReleaseInTesting($version, $build, $severity);
        [$s, $j] = $this->api($this->rm, 'POST', "api/v1/admin/server/releases/{$id}/publish", ['confirm_version' => $version]);
        $this->assertSame(200, $s, json_encode($j));
        $this->assertSame('PUBLISHED', $j['status']);

        return $id;
    }

    // ---------------------------------------------------------------------------------

    public function testRegisterPlanAndPolicyWithIfMatch(): void
    {
        $trust = new TrustFixture('inst_REG1', 'tnt_REG1', 'BUSINESS');
        $trust->pinRoot();

        [$s, $j] = $this->server('POST', 'api/v1/server/installations/register', [], []);
        $this->assertSame(422, $s);
        [$s, $j] = $this->server('POST', 'api/v1/server/installations/register', [], ['license_token' => 'garbage']);
        $this->assertSame([401, 'LICENSE_TOKEN_INVALID'], [$s, $j['error']['code']]);
        [$s, $j] = $this->server('POST', 'api/v1/server/installations/register', [], ['license_token' => $trust->license(), 'channel' => 'lts']);
        $this->assertSame(422, $s, 'lts is Enterprise only');

        // Licence token in → installation_id + key out, signed, 15-minute validity.
        $reg = $this->register($trust, ['admin_contacts' => ['it@customer.lk', 'not-an-email']]);
        $this->assertSame('installation_registration', $reg['type']);
        $this->assertSame('inst_REG1', $reg['installation_id']);
        $this->assertStringStartsWith('wsik_', $reg['installation_key']);
        $this->assertSame([2, 'broad', 'business', 'stable', 0], [$reg['ring'], $reg['ring_name'], $reg['edition'], $reg['channel'], $reg['policy_version']]);
        $this->assertTrue($this->verifyResponse($reg));
        $this->assertSame(900, strtotime($reg['expires_at']) - strtotime($reg['issued_at']));
        $row = db_connect()->table('server_installations')->where('installation_id', 'inst_REG1')->get()->getRowArray();
        $this->assertSame(hash('sha256', $reg['installation_key']), $row['key_hash']); // only the hash is stored
        $this->assertSame(['it@customer.lk'], json_decode($row['admin_contacts'], true));
        $this->seeInDatabase('audit_logs', ['action' => 'server.installation_registered', 'entity_id' => 'inst_REG1', 'actor_type' => 'INSTALLATION']);

        // Re-registering issues a new key; the old one stops working.
        $again = $this->register($trust);
        $this->assertNotSame($reg['installation_key'], $again['installation_key']);
        $old = ['Authorization' => 'Installation ' . $reg['installation_key']];
        $key = ['Authorization' => 'Installation ' . $again['installation_key']];
        $this->assertSame(401, $this->server('GET', 'api/v1/server/auto-update/plan', $old)[0]);
        [$s, $j] = $this->server('GET', 'api/v1/server/auto-update/plan', ['Authorization' => 'License ' . $trust->license()]);
        $this->assertSame([401, 'INSTALLATION_KEY_REQUIRED'], [$s, $j['error']['code']]);

        // Plan: nothing published yet → NONE, signed, 2-hour expiry.
        [$s, $plan] = $this->server('GET', 'api/v1/server/auto-update/plan', $key);
        $this->assertSame(200, $s, json_encode($plan));
        $this->assertSame(['auto_update_plan', 'NONE', null, 2], [$plan['type'], $plan['state'], $plan['release'], $plan['ring']]);
        $this->assertSame(7200, strtotime($plan['expires_at']) - strtotime($plan['issued_at']));
        $this->assertTrue($this->verifyResponse($plan));

        // The existing update API accepts the installation key too.
        $this->assertSame(200, $this->server('GET', 'api/v1/server/update?version=3.3.0&edition=business', $key)[0]);

        // Policy: If-Match required.
        $policy = ['mode' => 'AUTO_SECURITY', 'windows' => [['days' => ['sat', 'sun'], 'start' => '02:00', 'length_minutes' => 120]], 'blackouts' => [['name' => 'Month-end payroll', 'from' => '2026-10-28', 'to' => '2026-11-02']],
            'timezone' => 'Asia/Colombo', 'ring' => 'EARLY', 'backup_before' => true, 'auto_rollback' => true, 'max_active_users' => 25, 'allow_one_way_migrations' => false,
            'web_idle_minutes' => 10, 'web_forced_hours' => 24, 'web_toast_non_admin' => true, 'updated_by' => 'admin (tenant)'];
        [$s, $j] = $this->server('PUT', 'api/v1/server/auto-update/policy', $key, $policy);
        $this->assertSame([428, 'PRECONDITION_REQUIRED'], [$s, $j['error']['code']]);

        [$s, $j, $res] = $this->server('PUT', 'api/v1/server/auto-update/policy', $key + ['If-Match' => '"0"'], $policy);
        $this->assertSame(200, $s, json_encode($j));
        $this->assertSame([1, 'AUTO_SECURITY', 'EARLY'], [$j['version'], $j['policy']['mode'], $j['policy']['ring']]);
        $this->assertSame('"1"', $res->response()->getHeaderLine('ETag'));
        $this->assertTrue($this->verifyResponse($j));
        $this->seeInDatabase('audit_logs', ['action' => 'server.policy_synced', 'entity_id' => 'inst_REG1']);

        // AC-SU-13 at the portal: a second save based on version 0 is stale → 412 with the newer version.
        [$s, $j] = $this->server('PUT', 'api/v1/server/auto-update/policy', $key + ['If-Match' => '"0"'], ['mode' => 'AUTO_ALL'] + $policy);
        $this->assertSame([412, 'POLICY_VERSION_CONFLICT', 1, 'AUTO_SECURITY'], [$s, $j['error']['code'], $j['error']['details']['current_version'], $j['error']['details']['policy']['mode']]);

        [$s, $j] = $this->server('PUT', 'api/v1/server/auto-update/policy', $key + ['If-Match' => 'W/"1"'], ['mode' => 'AUTO_ALL'] + $policy);
        $this->assertSame([200, 2, 'AUTO_ALL'], [$s, $j['version'], $j['policy']['mode']]);
        $this->assertSame(2, db_connect()->table('audit_logs')->where('action', 'server.policy_synced')->countAllResults());

        // Validation: auto-rollback cannot be switched off; invalid windows rejected.
        [$s, $j] = $this->server('PUT', 'api/v1/server/auto-update/policy', $key + ['If-Match' => '"2"'], ['auto_rollback' => false] + $policy);
        $this->assertSame(422, $s);
        $this->assertArrayHasKey('auto_rollback', $j['error']['details']);
        [$s] = $this->server('PUT', 'api/v1/server/auto-update/policy', $key + ['If-Match' => '"2"'], ['windows' => [['days' => ['xyz'], 'start' => '25:00', 'length_minutes' => 5]]] + $policy);
        $this->assertSame(422, $s);

        [$s, $j, $res] = $this->server('GET', 'api/v1/server/auto-update/policy', $key);
        $this->assertSame([200, 2, '"2"'], [$s, $j['version'], $res->response()->getHeaderLine('ETag')]);

        // The preferred ring follows the policy (EARLY → ring 1).
        [, $plan] = $this->server('GET', 'api/v1/server/auto-update/plan', $key);
        $this->assertSame([1, 'early', 'AUTO_ALL', 2], [$plan['ring'], $plan['ring_name'], $plan['policy_mode'], $plan['policy_version']]);

        // Portal lock (audited policy override) wins over the tenant's value and bumps the version.
        [$s, $o] = $this->api($this->rm, 'PUT', 'api/v1/admin/server/installations/inst_REG1/override', ['locks' => ['mode' => 'NOTIFY'], 'reason' => 'Customer change freeze']);
        $this->assertSame(200, $s, json_encode($o));
        $this->seeInDatabase('audit_logs', ['action' => 'server.policy_override', 'entity_id' => 'inst_REG1']);
        [$s, $j] = $this->server('PUT', 'api/v1/server/auto-update/policy', $key + ['If-Match' => '"3"'], ['mode' => 'AUTO_ALL'] + $policy);
        $this->assertSame([200, 'NOTIFY', ['mode']], [$s, $j['policy']['mode'], $j['overridden']], json_encode($j));
        $this->assertSame('mode', $j['policy']['locked_fields'][0]['field']);
    }

    public function testTwoPersonRuleSwitchLetsTheQaApproverPublish(): void
    {
        $qaAndRm = $this->makeUser(['qa', 'release_manager'], 'qa-rm-solo@livezen.lk');
        $id      = $this->serverReleaseInTesting('3.4.1', 34013, 'PATCH', $qaAndRm);
        config('UpdateCenter')->twoPersonRuleDisabled = true;

        try {
            [$s, $j] = $this->api($qaAndRm, 'POST', "api/v1/admin/server/releases/{$id}/publish", ['confirm_version' => '3.4.1', 'reason' => 'One-person release']);
            $this->assertSame([200, 'PUBLISHED'], [$s, $j['status'] ?? null], json_encode($j));
        } finally {
            config('UpdateCenter')->twoPersonRuleDisabled = false;
        }
    }

    public function testPublishNeedsTwoPeopleMfaAndTypedVersionAndWritesSignedChecksums(): void
    {
        $qaAndRm = $this->makeUser(['qa', 'release_manager'], 'qa-rm@livezen.lk');
        $id      = $this->serverReleaseInTesting('3.4.0', 34012, 'MINOR', $qaAndRm);

        [, $full] = $this->api($this->rm, 'GET', "api/v1/admin/releases/{$id}");
        $this->assertSame(['MINOR', '07ebd36c', true, ['BUSINESS'], 'Run during a window.'], [$full['server_meta']['severity'], $full['server_meta']['commit'], $full['server_meta']['migrations_reversible'], $full['server_meta']['editions'], $full['server_meta']['upgrade_notes']]);
        $keys = array_column($full['checklist']['items'], 'status', 'key');
        $this->assertSame('pass', $keys['packages'], json_encode($full['checklist']));
        $this->assertSame('pass', $keys['reversibility']);

        // The person who gave the QA approval cannot publish (FR-DL-REL-04) — on any channel.
        [$s, $j] = $this->api($qaAndRm, 'POST', "api/v1/admin/server/releases/{$id}/publish", ['confirm_version' => '3.4.0']);
        $this->assertSame([403, 'SOD_VIOLATION'], [$s, $j['error']['code']], json_encode($j));
        // Type-to-confirm.
        [$s] = $this->api($this->rm, 'POST', "api/v1/admin/server/releases/{$id}/publish", ['confirm_version' => '3.4']);
        $this->assertSame(422, $s);
        // Step-up MFA in production.
        config('UpdateCenter')->environmentName = 'PRODUCTION';
        db_connect()->table('user_sessions')->update(['mfa_verified_at' => null]);
        $res = $this->withHeaders(['X-CSRF-Token' => $this->sessionCsrfWithoutMfa($this->rm)])->withBodyFormat('json')->post("api/v1/admin/server/releases/{$id}/publish", ['confirm_version' => '3.4.0']);
        $this->assertSame('MFA_REQUIRED', $this->json($res)['error']['code'] ?? null, (string) $res->response()->getBody());
        config('UpdateCenter')->environmentName = 'DEV';

        // A different Release Manager publishes.
        [$s, $j] = $this->api($this->rm, 'POST', "api/v1/admin/server/releases/{$id}/publish", ['confirm_version' => '3.4.0', 'reason' => 'Release day']);
        $this->assertSame([200, 'PUBLISHED'], [$s, $j['status']], json_encode($j));
        $this->seeInDatabase('audit_logs', ['action' => 'release.approved', 'entity_id' => $id]);
        $this->seeInDatabase('audit_logs', ['action' => 'release.published', 'entity_id' => $id]);

        // Ring 0 is open; the rest wait.
        [$s, $ro] = $this->api($this->rm, 'GET', "api/v1/admin/server/releases/{$id}/rollout");
        $this->assertSame(200, $s, json_encode($ro));
        $this->assertSame(['OPEN', 'WAITING', 'WAITING', 'WAITING'], array_column($ro['rings'], 'state'));
        $this->assertSame('PASSING', $ro['gate']['state']);

        // FR-DL-REL-05: package copied to server/<edition>/<channel>/<version>+<build>/ with SHA256SUMS + .sig.
        $provider = StorageFactory::fromRow(Catalog::activeStorage());
        $dir      = 'server/business/stable/3.4.0+34012/';
        $this->assertNotNull($provider->head($dir . 'worksuite-server-business-3.4.0+34012.zip'));
        $sums = stream_get_contents($provider->stream($dir . 'SHA256SUMS'));
        $sig  = json_decode((string) stream_get_contents($provider->stream($dir . 'SHA256SUMS.sig')), true);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  worksuite-server-business-3\.4\.0\+34012\.zip\n$/', $sums);
        $this->assertSame('checksums', $sig['type']);
        $this->assertSame(hash('sha256', $sums), $sig['sha256sums_sha256']);
        $this->assertTrue(Ed25519::verifyDocument($sig, KeyRegistry::releaseKeys()), 'SHA256SUMS.sig verifies under the release key');
        $manifest = json_decode((string) stream_get_contents($provider->stream($dir . 'release-manifest.json')), true);
        $this->assertTrue(Ed25519::verifyDocument($manifest, KeyRegistry::releaseKeys()));

        // DL-1 lists it with severity, editions and the ring summary.
        [$s, $list] = $this->api($this->rm, 'GET', 'api/v1/admin/server/releases?channel=stable&status=published');
        $this->assertSame(200, $s);
        $this->assertSame(['3.4.0', 'MINOR', 'Business', 'Ring 0 · open'], [$list['data'][0]['version'], $list['data'][0]['severity'], $list['data'][0]['editions_label'], $list['data'][0]['ring_summary']]);
        $this->assertArrayHasKey('linked', $list['kpis']);
    }

    /** Session cookie without a recent MFA verification (to exercise step-up). */
    private function sessionCsrfWithoutMfa(array $user): string
    {
        $s = (new \App\Services\AuthService())->createSession((int) $user['id'], '127.0.0.1', 'phpunit', false);
        $this->setCookie(\App\Services\AuthService::COOKIE, $s['token']);
        db_connect()->table('users')->where('id', $user['id'])->update(['mfa_enabled' => 1]);

        return $s['csrf'];
    }

    public function testWithdrawCancelsScheduledPlans(): void
    {
        $trust = new TrustFixture('inst_WD1', 'tnt_WD1', 'BUSINESS');
        $trust->pinRoot();
        $key = ['Authorization' => 'Installation ' . $this->register($trust)['installation_key']];
        $this->server('PUT', 'api/v1/server/auto-update/policy', $key + ['If-Match' => '"0"'], ['mode' => 'AUTO_ALL', 'ring' => 'BROAD', 'windows' => [['days' => ['sun'], 'start' => '03:00', 'length_minutes' => 60]]]);

        $id = $this->publishedServerRelease('3.4.0', 34012);
        [$s, $plan] = $this->server('GET', 'api/v1/server/auto-update/plan', $key);
        $this->assertSame(200, $s, json_encode($plan));
        $this->assertSame(['PLANNED', '3.4.0', true, false, 2], [$plan['state'], $plan['release']['version'], $plan['auto_install'], $plan['ring_open'], $plan['ring']]);
        $this->assertNotNull($plan['ring_open_at']); // projected: ring 0 (24 h) + ring 1 (48 h)
        $this->assertNotNull($plan['latest_at']);
        // The tenant schedules the run in its next window.
        $this->server('POST', 'api/v1/server/update-events', $key, ['events' => [['event_id' => 'sch-1', 'type' => 'scheduled', 'from' => '3.3.0', 'to' => '3.4.0', 'at' => gmdate('c')]]]);
        $this->seeInDatabase('server_update_plans', ['state' => 'PLANNED']);

        // AC-SU-14: withdrawing cancels the scheduled run before its window.
        [$s, $j] = $this->api($this->rm, 'POST', "api/v1/admin/releases/{$id}/withdraw", ['reason' => 'Data migration bug', 'confirm_version' => '3.4.0']);
        $this->assertSame([200, 'WITHDRAWN'], [$s, $j['status']], json_encode($j));
        $this->seeInDatabase('server_update_plans', ['state' => 'CANCELLED']);
        $this->dontSeeInDatabase('server_update_plans', ['state' => 'PLANNED']);
        $this->seeInDatabase('audit_logs', ['action' => 'server.plans_cancelled', 'entity_id' => $id]);

        [, $plan] = $this->server('GET', 'api/v1/server/auto-update/plan', $key);
        $this->assertSame(['NONE', null, '3.4.0', false], [$plan['state'], $plan['release'], $plan['cancelled'][0]['version'], $plan['cancelled'][0]['rollback_requested']]);
        $this->assertStringContainsString('Data migration bug', $plan['cancelled'][0]['reason']);

        // The signed update response marks it withdrawn for the tenant catalog (≤ 15 min validity).
        [, $up] = $this->server('GET', 'api/v1/server/update?version=3.3.0&edition=business&channel=stable', $key);
        $this->assertSame('CURRENT', $up['status']);
        $this->assertSame('3.4.0', $up['withdrawn_releases'][0]['version']);
        $this->assertLessThanOrEqual(900, strtotime($up['expires_at']) - strtotime($up['issued_at']));
    }

    public function testHealthGatePausesRingTwoOnRollbacksInRingOne(): void
    {
        $trust = new TrustFixture('inst_R2', 'tnt_R2', 'BUSINESS');
        $trust->pinRoot();
        $key = ['Authorization' => 'Installation ' . $this->register($trust)['installation_key']];
        $this->server('PUT', 'api/v1/server/auto-update/policy', $key + ['If-Match' => '"0"'], ['mode' => 'AUTO_MINOR', 'ring' => 'BROAD']);

        $id      = $this->publishedServerRelease('3.4.0', 34012);
        $release = Catalog::release($id);
        $db      = db_connect();
        // 100 ring-1 installations; ring 0 held its 24 h and ring 1 has been open 50 h, so ring 2 is due.
        $now = Catalog::now();
        for ($i = 1; $i <= 100; $i++) {
            $db->table('server_installations')->insert(['installation_id' => "inst_r1_{$i}", 'edition' => 'BUSINESS', 'version' => '3.3.0', 'build' => 33001, 'channel' => 'STABLE', 'mode' => 'SELF_HOSTED', 'ring' => 1, 'last_report_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids  = array_column($db->table('server_installations')->select('id')->like('installation_id', 'inst_r1_', 'after')->orderBy('id')->get()->getResultArray(), 'id');
        $iso  = static fn (int $hoursAgo) => gmdate('Y-m-d\TH:i:s\Z', time() - $hoursAgo * 3600);
        $db->table('release_rollouts')->where('release_id', $release['id'])->update(['rings' => json_encode(['rings' => [0 => ['opened_at' => $iso(80), 'extended_hours' => 0], 1 => ['opened_at' => $iso(50), 'extended_hours' => 0]], 'pause' => null])]);
        $event = static fn (int $instPk, string $type, int $n) => ['event_id' => "ev-{$type}-{$n}", 'installation_id' => $instPk, 'release_id' => $release['id'], 'type' => $type, 'from_version' => '3.3.0', 'to_version' => '3.4.0', 'occurred_at' => $now];
        foreach ($ids as $n => $pk) {
            $db->table('server_update_events')->insert($event((int) $pk, $n < 98 ? 'completed' : 'rolled_back', $n));
        }

        // 2 of 100 is not over 2 %: watch, no pause.
        $gate = (new ServerRingService())->gate($release);
        $this->assertSame(['WATCH', 'WATCH'], [$gate['state'], $gate['signals'][0]['state']], json_encode($gate));

        // AC-SU-15: the third rollback (3 of 100 = 3 %) pauses ring 2 automatically.
        $db->table('server_update_events')->where('installation_id', $ids[97])->update(['type' => 'rolled_back']);
        $out = (new ServerRingService())->evaluateAll();
        $this->assertStringContainsString('paused ring 2', implode("\n", $out));

        [, $ro] = $this->api($this->rm, 'GET', "api/v1/admin/server/releases/{$id}/rollout");
        $this->assertSame(['PAUSED', 'GATE', 2], [$ro['status'], $ro['pause']['kind'], $ro['pause']['ring']]);
        $this->assertSame(['DONE', 'DONE', 'PAUSED', 'WAITING'], array_column($ro['rings'], 'state'));
        $this->assertNull($ro['rings'][2]['opened_at']);
        $this->assertSame(['FAIL', '3 of 100 · 3 %'], [$ro['gate']['signals'][0]['state'], $ro['gate']['signals'][0]['display']]);
        $this->assertSame('FAILING', $ro['gate']['state']);
        $this->seeInDatabase('audit_logs', ['action' => 'server.ring_auto_paused', 'entity_id' => $id]);

        // A ring-2 installation's plan is held; nothing opens while paused.
        [, $plan] = $this->server('GET', 'api/v1/server/auto-update/plan', $key);
        $this->assertSame(['HELD', 2, false, 'HEALTH_GATE'], [$plan['state'], $plan['ring'], $plan['ring_open'], $plan['holds'][0]['code']]);
        $this->assertSame([], (new ServerRingService())->evaluateAll());

        // Release Manager extends ring 1 by 24 h, then resumes: ring 2 still waits for the extended hold.
        [$s, $ro] = $this->api($this->rm, 'POST', "api/v1/admin/server/releases/{$id}/rollout/extend", ['hours' => 24, 'reason' => 'Watch the rollbacks']);
        $this->assertSame([200, 24], [$s, $ro['rings'][1]['extended_hours']], json_encode($ro));
        [$s, $ro] = $this->api($this->rm, 'POST', "api/v1/admin/server/releases/{$id}/rollout/resume", ['reason' => 'Root cause was disk space']);
        $this->assertSame([200, 'ACTIVE', null], [$s, $ro['status'], $ro['pause']]);
        $this->seeInDatabase('audit_logs', ['action' => 'server.ring_resume', 'entity_id' => $id]);
        (new ServerRingService())->evaluateAll();
        $this->assertFalse((new ServerRingService())->isOpen(Catalog::release($id), 2), 'acknowledged signals do not re-pause; the extended hold keeps ring 2 closed');
        [, $plan] = $this->server('GET', 'api/v1/server/auto-update/plan', $key);
        $this->assertSame('PLANNED', $plan['state']);

        // Once the extended hold has passed, ring 2 opens.
        $db->table('release_rollouts')->where('release_id', $release['id'])->update(['rings' => json_encode(['rings' => [0 => ['opened_at' => $iso(110), 'extended_hours' => 0], 1 => ['opened_at' => $iso(80), 'extended_hours' => 24]], 'pause' => null, 'ack' => ['rollback_rate']])]);
        $this->assertStringContainsString('opened ring 2', implode("\n", (new ServerRingService())->evaluateAll()));
        [, $plan] = $this->server('GET', 'api/v1/server/auto-update/plan', $key);
        $this->assertSame([true, 'PLANNED', true], [$plan['ring_open'], $plan['state'], $plan['auto_install']]);
    }

    public function testSecurityReleaseCompressesRingsAndGateCountsNewSevereIssues(): void
    {
        $id      = $this->publishedServerRelease('3.4.1', 34101, 'SECURITY');
        $release = Catalog::release($id);
        $ring    = new ServerRingService();
        $this->assertTrue(ServerRingService::compressed($release));
        $state = $ring->state($release);
        // Rings 1 and 2 both open after ring 0's hold (0 → 2).
        $this->assertSame($ring->openAt($release, 1, $state), $ring->openAt($release, 2, $state));
        $this->assertSame($ring->openAt($release, 0, $state) + 24 * 3600, $ring->openAt($release, 2, $state));

        // A new P0 issue on 3 installations fails the gate.
        $db  = db_connect();
        $app = Catalog::release($id)['application_id'];
        $project = $db->table('bt_projects')->where('application_id', $app)->where('platform', 'server')->get()->getRowArray();
        $this->assertNotNull($project, 'catalog seed has a Bug Trace project for the server');
        $db->table('bt_issues')->insert(['public_id' => 'WS-SRV-T1', 'project_id' => $project['id'], 'fingerprint' => str_repeat('a', 64), 'type' => 'error', 'title' => 'Fatal in Posting', 'status' => 'NEW', 'priority' => 'P0', 'first_release_id' => $release['id'], 'installations_count' => 3, 'users_count' => 0, 'events_count' => 9, 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        $gate = $ring->gate($release);
        $this->assertSame('FAILING', $gate['state']);
        $this->assertSame('FAIL', array_column($gate['signals'], 'state', 'key')['new_p0p1']);
    }

    public function testFleetViewHeartbeatAndMinimumVersion(): void
    {
        $trust = new TrustFixture('inst_FL1', 'tnt_FL1', 'BUSINESS');
        $trust->pinRoot();
        $key = ['Authorization' => 'Installation ' . $this->register($trust, ['version' => '3.3.0'])['installation_key']];
        // FR-SU-LINK-03 heartbeat with runtime, disk, policy mode and health.
        [$s] = $this->server('POST', 'api/v1/server/installations/report', $key, ['version' => '3.3.0', 'build' => 33001, 'node_version' => '22.11.0', 'mariadb_version' => '10.11.8', 'disk_free_mb' => 18000, 'policy_mode' => 'AUTO_SECURITY', 'health' => 'OK', 'offline_mode' => false]);
        $this->assertSame(200, $s);
        $this->publishedServerRelease('3.4.0', 34012);

        // FR-DL-MIN-01: minimum 3.4.0 on stable → the installation is below it and the update becomes REQUIRED.
        [$s, $j] = $this->api($this->rm, 'PUT', 'api/v1/admin/applications/server/minimum-versions', ['channel' => 'STABLE', 'platform' => null, 'minimum_version' => '3.4.0', 'enforce_from' => null, 'message' => 'Security baseline', 'reason' => 'Advisory']);
        $this->assertSame(200, $s, json_encode($j));
        [, $up] = $this->server('GET', 'api/v1/server/update?version=3.3.0&edition=business&channel=stable', $key);
        $this->assertSame(['UPDATE_REQUIRED', true, '3.4.0'], [$up['status'], $up['below_minimum'], $up['minimum_supported_version']], json_encode($up));
        $this->assertSame(['ring' => 2, 'ring_name' => 'broad', 'ring_open' => false], array_intersect_key($up['rollout'], ['ring' => 1, 'ring_name' => 1, 'ring_open' => 1]));

        // FR-DL-FLEET-01: fleet rows carry heartbeat, policy, ring and the below-minimum flag; segments filter.
        [$s, $list] = $this->api($this->rm, 'GET', 'api/v1/admin/installations?segment=below_minimum');
        $this->assertSame(200, $s);
        $row = $list['data'][0];
        $this->assertSame(['inst_FL1', '22.11.0', 18000, 'OK', 'AUTO_SECURITY', 2, 'broad', true, true], [$row['installation_id'], $row['node_version'], $row['disk_free_mb'], $row['health'], $row['policy_mode'], $row['ring'], $row['ring_name'], $row['below_minimum'], $row['linked']]);
        $this->assertSame('10.11.8', $row['db_version']);
        [, $stats] = $this->api($this->rm, 'GET', 'api/v1/admin/installations/stats');
        $this->assertSame(1, $stats['segments']['below_minimum']);
        $this->assertSame('3.4.0', $stats['adoption'][0]['version']);
        [, $console] = $this->api($this->rm, 'GET', 'api/v1/admin/server/releases');
        $this->assertSame([1, 1, '3.4.0'], [$console['kpis']['linked'], $console['kpis']['below_minimum'], $console['kpis']['minimum_stable']]);
        $this->assertSame([], $this->api($this->rm, 'GET', 'api/v1/admin/installations?segment=stale')[1]['data']);

        // A server release published before ring rollouts existed is open in every ring.
        db_connect()->table('release_rollouts')->update(['mode' => 'STAGED', 'rings' => null]);
        [, $plan] = $this->server('GET', 'api/v1/server/auto-update/plan', $key);
        $this->assertSame(['3.4.0', true, 'PLANNED'], [$plan['release']['version'], $plan['ring_open'], $plan['state']]);
    }
}

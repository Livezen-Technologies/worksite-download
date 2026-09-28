<?php

namespace Tests\Feature;

use Tests\Support\Fixtures;
use Tests\Support\TrustFixture;
use Tests\Support\UcTestCase;

/**
 * §17 server editions (AC-C41/C44/C45/C47) and §2.4 tenant policy tokens (AC-C33).
 */
final class ServerAndTenantTest extends UcTestCase
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

    private function tusUpload(array $user, string $rel, array $meta, string $bytes): string
    {
        [$s, $j] = $this->api($user, 'POST', "api/v1/admin/releases/{$rel}/packages", $meta + ['size' => strlen($bytes)]);
        $this->assertSame(201, $s, json_encode($j));
        $csrf              = $this->actingAs($user)['X-CSRF-Token'];
        $this->bodyFormat  = '';
        $this->requestBody = '';
        $this->withHeaders(['X-CSRF-Token' => $csrf, 'Tus-Resumable' => '1.0.0', 'Upload-Length' => (string) strlen($bytes), 'Upload-Metadata' => 'upload_id ' . base64_encode($j['upload']['id'])])->post('api/v1/uploads/tus')->assertStatus(201);
        $this->withHeaders(['X-CSRF-Token' => $csrf, 'Tus-Resumable' => '1.0.0', 'Upload-Offset' => '0', 'Content-Type' => 'application/offset+octet-stream'])->withBody($bytes)->patch('api/v1/uploads/tus/' . $j['upload']['id'])->assertStatus(204);
        $this->requestBody = '';

        return $j['package']['id'];
    }

    public function testServerEditionReleaseEndToEnd(): void
    {
        [$s, $rel] = $this->api($this->dev, 'POST', 'api/v1/admin/releases', ['app_id' => 'server', 'version' => '3.4.0', 'build' => 34012, 'channel' => 'STABLE', 'minimum_no_change' => true]);
        $this->assertSame(201, $s, json_encode($rel));
        $id  = $rel['id'];
        $tmp = sys_get_temp_dir() . '/uc-srv-' . getmypid() . '.zip';
        $mods = ['FREE' => ['core', 'mail'], 'BUSINESS' => ['core', 'mail', 'crm'], 'ENTERPRISE' => ['core', 'crm', 'payroll']];
        foreach ($mods as $edition => $m) {
            Fixtures::serverZip($tmp, '3.4.0', 34012, $edition, $m);
            $this->tusUpload($this->dev, $id, ['edition' => $edition, 'file_name' => 'worksuite-server-3.4.0-' . strtolower($edition) . '.zip'], (string) file_get_contents($tmp));
        }
        // AC-C44: Business ZIP with Enterprise module → rejected.
        Fixtures::serverZip($tmp, '3.4.0', 34012, 'BUSINESS', ['core', 'payroll']);
        $bad = $this->tusUpload($this->dev, $id, ['edition' => 'BUSINESS', 'file_name' => 'bad-business.zip', 'reason' => 'retry'], (string) file_get_contents($tmp));
        [, $p] = $this->api($this->dev, 'GET', "api/v1/admin/releases/{$id}/packages/{$bad}");
        $this->assertSame('EDITION_CONTENT_VIOLATION', $p['revisions'][0]['reject_code']);
        $this->assertSame('READY', $p['status']); // earlier good revision stays active
        unlink($tmp);

        [, $full] = $this->api($this->rm, 'GET', "api/v1/admin/releases/{$id}");
        $this->assertSame('3.2.0', $full['server_meta']['min_from_version']); // synced from worksuite-package.json
        $this->api($this->dev, 'PUT', "api/v1/admin/releases/{$id}/server-meta", ['staging_test' => ['status' => 'PASSED'], 'admin_notice' => 'Brief downtime (~2 min).']);
        $this->api($this->dev, 'PUT', "api/v1/admin/releases/{$id}/changelog", ['summary' => 'Server 3.4.0', 'sections' => ['new_features' => ['Desktop catalog sync']]]);

        // Installation registered by its license (with webhook opt-in) before publish.
        $trust = new TrustFixture('inst_BIZ1', 'tnt_BIZ1', 'BUSINESS');
        $trust->pinRoot();
        $lic   = 'License ' . $trust->license();
        $this->withHeaders(['Authorization' => $lic])->withBodyFormat('json')->post('api/v1/server/installations/report', ['version' => '3.3.2', 'build' => 33204, 'mode' => 'SELF_HOSTED', 'notify_opt_in' => true, 'notify_contact' => 'admin@customer.lk', 'webhook_url' => 'https://customer.lk/hooks/worksuite'])->assertStatus(200);

        [$s, $j] = $this->api($this->dev, 'POST', "api/v1/admin/releases/{$id}/submit-test");
        $this->assertSame(200, $s, json_encode($j));
        $this->api($this->qa, 'POST', "api/v1/admin/releases/{$id}/qa", ['decision' => 'PASS', 'comment' => 'staging ok']);
        $this->api($this->rm, 'POST', "api/v1/admin/releases/{$id}/approve");
        [$s, $j] = $this->api($this->rm, 'POST', "api/v1/admin/releases/{$id}/publish");
        $this->assertSame('PUBLISHED', $j['status'], json_encode($j));

        // AC-C45: campaign sent to the eligible installation.
        [, $c] = $this->api($this->rm, 'GET', "api/v1/admin/releases/{$id}/campaign");
        $this->assertSame(1, $c['sent_count']);
        $this->seeInDatabase('campaign_deliveries', ['channel' => 'webhook']);

        // Server update API with the installation license (no staff session from here on).
        $this->setCookie('uc_session', null);
        $res = $this->withHeaders(['Authorization' => $lic])->get('api/v1/server/update?version=3.3.2&build=33204&edition=business&channel=stable');
        $up  = $this->json($res);
        $this->assertSame('UPDATE_RECOMMENDED', $up['status'], json_encode($up));
        $this->assertSame('business', $up['package']['edition']);
        $this->assertSame('3.4.0', $up['package']['package_json']['version']);
        // Business license cannot ask for Enterprise.
        $this->assertSame(403, $this->withHeaders(['Authorization' => $lic])->get('api/v1/server/update?version=3.3.2&edition=enterprise')->response()->getStatusCode());

        // AC-C47: Business download requires a matching license; Free is public.
        $this->assertSame(302, $this->get('download/server/3.4.0/server/free')->response()->getStatusCode());
        $this->assertSame(401, $this->withHeaders(['Authorization' => ''])->get('download/server/3.4.0/server/business')->response()->getStatusCode());
        $this->assertSame(302, $this->withHeaders(['Authorization' => $lic])->get('download/server/3.4.0/server/business')->response()->getStatusCode());
        $this->assertSame(403, $this->withHeaders(['Authorization' => $lic])->get('download/server/3.4.0/server/enterprise')->response()->getStatusCode());

        // Update events update the registry.
        $this->withHeaders(['Authorization' => $lic])->withBodyFormat('json')->post('api/v1/server/update-events', ['events' => [['event_id' => 'e1', 'type' => 'completed', 'from' => '3.3.2', 'to' => '3.4.0']]])->assertStatus(202);
        $this->seeInDatabase('server_installations', ['installation_id' => 'inst_BIZ1', 'version' => '3.4.0', 'last_run_status' => 'COMPLETED']);
    }

    public function testTenantPolicyTokenChangesChannelAndMinimum(): void
    {
        // Publish stable 2.7.0 and beta 2.8.0-beta.1 quickly through the services.
        $trust = new TrustFixture();
        $trust->pinRoot();
        $stable = $this->quickPublish('2.7.0', 27001, 'STABLE');
        $beta   = $this->quickPublish('2.8.0-beta.1', 28001, 'BETA');
        $this->setCookie('uc_session', null);
        $q = 'api/v1/desktop/update?app_id=lk.worksuite.desktop&current_version=2.6.0&platform=windows&architecture=x64&device_id=hmac:x';

        // No TPT: beta hint ignored → stable.
        $r = $this->json($this->get($q . '&channel=beta'));
        $this->assertSame('stable', $r['channel']);
        $this->assertSame('absent', $r['tenant_policy']);

        // TPT assigns beta and an org minimum above the device version → UPDATE_REQUIRED on beta.
        $tpt = $trust->tpt(['channel' => 'beta', 'allowed_channels' => ['stable', 'beta'], 'org_minimum' => ['windows' => '2.6.5']]);
        $r   = $this->json($this->withHeaders(['X-WorkSuite-Tenant-Policy' => $tpt])->get($q));
        $this->assertSame('applied', $r['tenant_policy']);
        $this->assertSame('beta', $r['channel']);
        $this->assertSame('2.8.0-beta.1', $r['latest_version']);
        $this->assertSame('UPDATE_REQUIRED', $r['status']);
        $this->assertSame('2.6.5', $r['effective_minimum']);

        // Invalid TPT → defaults, still answered.
        $r = $this->json($this->withHeaders(['X-WorkSuite-Tenant-Policy' => 'garbage'])->get($q));
        $this->assertSame('invalid', $r['tenant_policy']);
        $this->assertSame('stable', $r['channel']);

        // Rollout hold for the org → offered previous (none) → CURRENT.
        db_connect()->table('release_rollouts')->update(['percentage' => 5, 'status' => 'ACTIVE']);
        $hold = $trust->tpt(['rollout_hold' => true]);
        $r    = $this->json($this->withHeaders(['X-WorkSuite-Tenant-Policy' => $hold])->get($q));
        $this->assertSame('CURRENT', $r['status']);
        unset($stable, $beta);
    }

    private function quickPublish(string $version, int $build, string $channel): string
    {
        [, $rel] = $this->api($this->dev, 'POST', 'api/v1/admin/releases', ['app_id' => 'desktop', 'version' => $version, 'build' => $build, 'channel' => $channel, 'minimum_supported_version' => '2.5.0']);
        foreach ([['windows', 'x64', 'exe', Fixtures::pe()], ['macos', 'arm64', 'dmg', Fixtures::dmg()], ['macos', 'x64', 'dmg', Fixtures::dmg()], ['linux', 'x64', 'appimage', Fixtures::appImage()]] as [$pl, $ar, $ty, $bytes]) {
            $this->tusUpload($this->dev, $rel['id'], ['platform' => $pl, 'architecture' => $ar, 'package_type' => $ty, 'file_name' => "ws-{$version}-{$pl}-{$ar}.{$ty}"], $bytes);
        }
        $this->api($this->dev, 'PUT', "api/v1/admin/releases/{$rel['id']}/changelog", ['summary' => $version, 'sections' => ['improvements' => ['Faster']]]);
        $this->api($this->dev, 'POST', "api/v1/admin/releases/{$rel['id']}/submit-test");
        $this->api($this->qa, 'POST', "api/v1/admin/releases/{$rel['id']}/qa", ['decision' => 'PASS', 'comment' => 'ok']);
        $this->api($this->rm, 'POST', "api/v1/admin/releases/{$rel['id']}/approve");
        [$s, $j] = $this->api($this->rm, 'POST', "api/v1/admin/releases/{$rel['id']}/publish");
        $this->assertSame('PUBLISHED', $j['status'] ?? null, json_encode($j));
        db_connect()->table('release_rollouts')->update(['percentage' => 100, 'status' => 'DONE']);

        return $rel['id'];
    }
}

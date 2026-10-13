<?php

namespace Tests\Feature;

use App\Libraries\Signing\Ed25519;
use App\Services\LicenseService;
use Tests\Support\UcTestCase;

/**
 * WorkSuite Server licences: staff generate and manage keys; servers activate a key
 * and revalidate it, receiving entitlements signed with the update-response key.
 */
final class LicensesTest extends UcTestCase
{
    private array $sa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sa = $this->makeUser(['super_admin'], 'sa-lic@livezen.lk');
    }

    private function api(array $user, string $method, string $uri, array $body = []): array
    {
        $res = $this->withHeaders($this->actingAs($user))->withBodyFormat('json')->call($method, $uri, $body);

        return [$res->response()->getStatusCode(), $this->json($res)];
    }

    private function server(string $method, string $uri, array $headers, array $body = []): array
    {
        $this->setCookie('uc_session', null);
        $res = $this->withHeaders($headers)->withBodyFormat('json')->call($method, $uri, $body);

        return [$res->response()->getStatusCode(), $this->json($res)];
    }

    private function verified(array $doc): bool
    {
        $cfg = config('UpdateCenter');

        return Ed25519::verifyDocument($doc, [$cfg->responseKeyId => service('responseKeys')->publicKey($cfg->responseKeyId)]);
    }

    private function newLicence(array $over = []): array
    {
        [$s, $j] = $this->api($this->sa, 'POST', 'api/v1/admin/licenses', $over + [
            'organization' => 'Acme Lanka (Pvt) Ltd', 'contact_email' => 'it@acme.lk', 'edition' => 'business',
            'expires_at' => gmdate('Y-m-d', time() + 365 * 86400), 'max_activations' => 1,
        ]);
        $this->assertSame(201, $s, json_encode($j));

        return $j;
    }

    public function testKeysAreGeneratedOnceAndStoredOnlyAsAHash(): void
    {
        $lic = $this->newLicence();
        $this->assertMatchesRegularExpression('/^WS-([A-HJ-NP-Z2-9]{4}-){4}[A-HJ-NP-Z2-9]{4}$/', $lic['license_key']);
        $this->assertSame(['BUSINESS', 'ACTIVE', 'ACTIVE'], [$lic['edition'], $lic['status'], $lic['state']]);
        $this->assertStringEndsWith(substr($lic['license_key'], -4), $lic['license_key_masked']);
        $this->seeInDatabase('licenses', ['public_id' => $lic['id'], 'key_hash' => LicenseService::hashKey($lic['license_key'])]);
        $this->dontSeeInDatabase('licenses', ['key_hash' => $lic['license_key']]);
        $this->seeInDatabase('audit_logs', ['action' => 'license.created', 'entity_id' => $lic['id']]);

        [, $detail] = $this->api($this->sa, 'GET', "api/v1/admin/licenses/{$lic['id']}");
        $this->assertArrayNotHasKey('license_key', $detail);

        // Keys are case and separator insensitive.
        $this->assertSame(LicenseService::hashKey($lic['license_key']), LicenseService::hashKey(strtolower(str_replace('-', ' ', $lic['license_key']))));
    }

    public function testActivationReturnsSignedEntitlementAndInstallationKeyThenValidates(): void
    {
        $lic = $this->newLicence();
        [$s, $act] = $this->server('POST', 'api/v1/server/licenses/activate', [], ['license_key' => $lic['license_key'], 'fingerprint' => 'fp-1', 'hostname' => 'erp.acme.lk', 'version' => '3.1.0']);
        $this->assertSame(201, $s, json_encode($act));
        $this->assertTrue($this->verified($act));
        $this->assertSame(['ACTIVE', 'business', true, 'erp.acme.lk'], [$act['entitlement']['status'], $act['entitlement']['edition'], $act['entitlement']['update_eligible'], $act['entitlement']['hostname']]);
        $this->assertContains('crm', $act['entitlement']['modules']);   // Business
        $this->assertContains('mail', $act['entitlement']['modules']);  // and Free
        $this->assertNotContains('garage', $act['entitlement']['modules']); // an add-on, not in Business
        $this->assertSame([true, 50, false], [$act['entitlement']['entitlements']['crm.enabled'], $act['entitlement']['entitlements']['core.users.max'], $act['entitlement']['entitlements']['garage.enabled']]);
        $this->seeInDatabase('server_installations', ['installation_id' => $act['installation_id'], 'edition' => 'BUSINESS']);

        // Same server again: same slot, new installation key.
        [$s, $again] = $this->server('POST', 'api/v1/server/licenses/activate', [], ['license_key' => $lic['license_key'], 'fingerprint' => 'fp-1']);
        $this->assertSame([200, $act['installation_id']], [$s, $again['installation_id']]);

        // A second server is over the limit of one.
        [$s, $j] = $this->server('POST', 'api/v1/server/licenses/activate', [], ['license_key' => $lic['license_key'], 'fingerprint' => 'fp-2']);
        $this->assertSame([409, 'LICENSE_ACTIVATION_LIMIT'], [$s, $j['error']['code']]);

        // Revalidate with the (latest) installation key.
        [$s, $val] = $this->server('POST', 'api/v1/server/licenses/validate', ['Authorization' => 'Installation ' . $again['installation_key']], ['version' => '3.1.0']);
        $this->assertSame([200, 'license_entitlement', 'ACTIVE'], [$s, $val['type'], $val['status']], json_encode($val));
        $this->assertTrue($this->verified($val));

        // Suspension shows on the next validation; resume restores it.
        [$s] = $this->api($this->sa, 'POST', "api/v1/admin/licenses/{$lic['id']}/suspend", ['reason' => 'Unpaid invoice']);
        $this->assertSame(200, $s);
        [, $val] = $this->server('POST', 'api/v1/server/licenses/validate', ['Authorization' => 'Installation ' . $again['installation_key']]);
        $this->assertSame(['SUSPENDED', false], [$val['status'], $val['update_eligible']]);
        $this->api($this->sa, 'POST', "api/v1/admin/licenses/{$lic['id']}/resume");
        [, $val] = $this->server('POST', 'api/v1/server/licenses/validate', ['Authorization' => 'Installation ' . $again['installation_key']]);
        $this->assertSame('ACTIVE', $val['status']);
    }

    public function testExpiredUnknownAndRevokedKeysAreRefused(): void
    {
        [$s, $j] = $this->server('POST', 'api/v1/server/licenses/activate', [], ['license_key' => 'WS-AAAA-BBBB-CCCC-DDDD-EEEE']);
        $this->assertSame([404, 'LICENSE_KEY_INVALID'], [$s, $j['error']['code']]);

        $lic = $this->newLicence();
        db_connect()->table('licenses')->where('public_id', $lic['id'])->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        [$s, $j] = $this->server('POST', 'api/v1/server/licenses/activate', [], ['license_key' => $lic['license_key']]);
        $this->assertSame([403, 'LICENSE_EXPIRED'], [$s, $j['error']['code']]);

        // Renew puts it back.
        [$s, $r] = $this->api($this->sa, 'POST', "api/v1/admin/licenses/{$lic['id']}/renew", ['expires_at' => gmdate('Y-m-d', time() + 30 * 86400)]);
        $this->assertSame([200, 'ACTIVE'], [$s, $r['state']], json_encode($r));

        [$s] = $this->api($this->sa, 'POST', "api/v1/admin/licenses/{$lic['id']}/revoke", ['reason' => 'Replaced']);
        $this->assertSame(200, $s);
        [$s, $j] = $this->server('POST', 'api/v1/server/licenses/activate', [], ['license_key' => $lic['license_key']]);
        $this->assertSame([403, 'LICENSE_REVOKED'], [$s, $j['error']['code']]);
    }

    public function testDeactivatingAServerFreesTheSlot(): void
    {
        $lic = $this->newLicence();
        $this->server('POST', 'api/v1/server/licenses/activate', [], ['license_key' => $lic['license_key'], 'fingerprint' => 'fp-1']);
        [, $detail] = $this->api($this->sa, 'GET', "api/v1/admin/licenses/{$lic['id']}");
        $this->assertSame(1, $detail['active_activations']);
        [$s] = $this->api($this->sa, 'POST', "api/v1/admin/licenses/{$lic['id']}/activations/{$detail['activations'][0]['id']}/deactivate", ['reason' => 'Server retired']);
        $this->assertSame(200, $s);
        [$s] = $this->server('POST', 'api/v1/server/licenses/activate', [], ['license_key' => $lic['license_key'], 'fingerprint' => 'fp-2']);
        $this->assertSame(201, $s);
    }

    public function testViewersCannotManageLicences(): void
    {
        $viewer = $this->makeUser(['viewer'], 'viewer-lic@livezen.lk');
        [$s] = $this->api($viewer, 'POST', 'api/v1/admin/licenses', ['organization' => 'X', 'edition' => 'free', 'expires_at' => '2030-01-01']);
        $this->assertSame(403, $s);
    }

    public function testInspectPreviewsAKeyWithoutActivatingIt(): void
    {
        $lic = $this->newLicence(['edition' => 'enterprise']);
        [$s, $doc] = $this->server('POST', 'api/v1/server/licenses/inspect', [], ['license_key' => $lic['license_key']]);
        $this->assertSame([200, 'license_preview', 'enterprise', 0, 1], [$s, $doc['type'], $doc['edition'], $doc['active_activations'], $doc['token_version']], json_encode($doc));
        $this->assertTrue($this->verified($doc));
        $this->dontSeeInDatabase('license_activations', ['installation_id' => 'x']);
        $this->assertSame(0, db_connect()->table('license_activations')->countAllResults());
    }

    public function testTheDownloadCenterRefusesUpdatesToALapsedLicence(): void
    {
        $lic = $this->newLicence();
        [, $act] = $this->server('POST', 'api/v1/server/licenses/activate', [], ['license_key' => $lic['license_key'], 'fingerprint' => 'fp-u']);
        $auth = ['Authorization' => 'Installation ' . $act['installation_key']];
        $res  = $this->withHeaders($auth)->call('GET', 'api/v1/server/update', ['from' => '3.0.0', 'build' => 1, 'edition' => 'business', 'channel' => 'stable']);
        $this->assertNotSame(402, $res->response()->getStatusCode());

        db_connect()->table('licenses')->where('public_id', $lic['id'])->update(['updates_until' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $this->setCookie('uc_session', null);
        $res = $this->withHeaders($auth)->call('GET', 'api/v1/server/update', ['from' => '3.0.0', 'build' => 1, 'edition' => 'business', 'channel' => 'stable']);
        $this->assertSame(402, $res->response()->getStatusCode());
        $this->assertSame('LICENSE_UPDATE_NOT_ELIGIBLE', $this->json($res)['error']['code'] ?? null);
    }

    public function testEditionValuesAndAddOnsFlowIntoTheSignedEntitlement(): void
    {
        $lic = $this->newLicence(); // Business
        [$s, $m] = $this->api($this->sa, 'GET', 'api/v1/admin/entitlements');
        $this->assertSame(200, $s);
        $this->assertFalse($m['editions']['BUSINESS']['garage.enabled']);
        $this->assertTrue($m['editions']['ENTERPRISE']['garage.enabled']);

        // An add-on for this licence, and a new limit for the edition.
        [$s, $j] = $this->api($this->sa, 'PUT', "api/v1/admin/licenses/{$lic['id']}/entitlements", ['values' => ['garage.enabled' => true]]);
        $this->assertSame([200, true], [$s, $j['resolved']['garage.enabled']], json_encode($j));
        [$s] = $this->api($this->sa, 'PUT', 'api/v1/admin/entitlements/editions/business', ['values' => ['core.users.max' => 75]]);
        $this->assertSame(200, $s);
        [$s, $bad] = $this->api($this->sa, 'PUT', 'api/v1/admin/entitlements/editions/business', ['values' => ['core.users.max' => 'lots']]);
        $this->assertSame(422, $s, json_encode($bad));

        [, $act] = $this->server('POST', 'api/v1/server/licenses/activate', [], ['license_key' => $lic['license_key'], 'fingerprint' => 'fp-e']);
        $this->assertTrue($this->verified($act));
        $this->assertSame([true, 75], [$act['entitlement']['entitlements']['garage.enabled'], $act['entitlement']['entitlements']['core.users.max']]);
        $this->assertContains('garage', $act['entitlement']['modules']);

        // Removing the add-on takes it away again; updates.core false ends update eligibility.
        $this->api($this->sa, 'PUT', "api/v1/admin/licenses/{$lic['id']}/entitlements", ['remove' => ['garage.enabled'], 'values' => ['updates.core' => false]]);
        [, $val] = $this->server('POST', 'api/v1/server/licenses/validate', ['Authorization' => 'Installation ' . $act['installation_key']]);
        $this->assertSame([false, false], [$val['entitlements']['garage.enabled'], $val['update_eligible']]);

        // A new key in the catalog appears for every edition, off.
        [$s, $m] = $this->api($this->sa, 'POST', 'api/v1/admin/entitlements', ['key' => 'repairmate.enabled', 'label' => 'RepairMate', 'kind' => 'bool', 'category' => 'apps']);
        $this->assertSame([201, false], [$s, $m['editions']['ENTERPRISE']['repairmate.enabled']], json_encode($m));

        // The public catalog shows labels and values; viewers cannot change them.
        $this->setCookie('uc_session', null);
        $res = $this->get('api/v1/catalog/entitlements');
        $this->assertSame(200, $res->response()->getStatusCode());
        $viewer = $this->makeUser(['viewer'], 'viewer-ent@livezen.lk');
        [$s] = $this->api($viewer, 'PUT', 'api/v1/admin/entitlements/editions/free', ['values' => ['crm.enabled' => false]]);
        $this->assertSame(403, $s);
    }
}

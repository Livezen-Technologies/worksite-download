<?php

namespace Tests\Feature;

use Tests\Support\UcTestCase;

/**
 * Portal › Licences: a customer sees and manages only the licences whose contact email is theirs.
 */
final class PortalLicensesTest extends UcTestCase
{
    private array $sa;
    private array $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sa       = $this->makeUser(['super_admin'], 'sa-portal@livezen.lk');
        $this->customer = $this->makeUser(['portal_user'], 'it@acme.lk', 'PORTAL');
    }

    private function api(array $user, string $method, string $uri, array $body = []): array
    {
        $res = $this->withHeaders($this->actingAs($user))->withBodyFormat('json')->call($method, $uri, $body);

        return [$res->response()->getStatusCode(), $this->json($res)];
    }

    private function licence(string $email, string $edition = 'business'): array
    {
        [$s, $l] = $this->api($this->sa, 'POST', 'api/v1/admin/licenses', ['organization' => 'Acme', 'contact_email' => $email, 'edition' => $edition, 'expires_at' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $this->assertSame(201, $s, json_encode($l));

        return $l;
    }

    public function testACustomerSeesOnlyTheirOwnLicences(): void
    {
        $mine  = $this->licence('IT@acme.lk');
        $other = $this->licence('someone@else.lk');
        [$s, $list] = $this->api($this->customer, 'GET', 'api/v1/portal/licenses');
        $this->assertSame(200, $s, json_encode($list));
        $this->assertSame([$mine['id']], array_column($list['data'], 'id'));
        $this->assertArrayNotHasKey('notes', $list['data'][0]);
        [$s] = $this->api($this->customer, 'GET', "api/v1/portal/licenses/{$other['id']}");
        $this->assertSame(404, $s, "someone else's licence does not exist for them");
        [$s, $d] = $this->api($this->customer, 'GET', "api/v1/portal/licenses/{$mine['id']}");
        $this->assertSame([200, true], [$s, $d['entitlements']['crm.enabled']]);
        $this->assertSame('Licence issued', end($d['history'])['event']);
    }

    public function testRevealReplaceAndDeactivate(): void
    {
        $l = $this->licence('it@acme.lk');
        [$s, $r] = $this->api($this->customer, 'POST', "api/v1/portal/licenses/{$l['id']}/reveal");
        $this->assertSame([200, $l['license_key']], [$s, $r['license_key'] ?? null], json_encode($r));

        // A server activates; the customer deactivates it (lost server) and its credentials stop working.
        $this->setCookie('uc_session', null);
        $act = $this->json($this->withBodyFormat('json')->post('api/v1/server/licenses/activate', ['license_key' => $l['license_key'], 'fingerprint' => 'lost']));
        [, $d] = $this->api($this->customer, 'GET', "api/v1/portal/licenses/{$l['id']}");
        [$s] = $this->api($this->customer, 'POST', "api/v1/portal/licenses/{$l['id']}/activations/{$d['activations'][0]['id']}/deactivate", ['reason' => 'Server stolen']);
        $this->assertSame(200, $s);
        $this->setCookie('uc_session', null);
        $res = $this->withHeaders(['Authorization' => 'Installation ' . $act['installation_key']])->withBodyFormat('json')->post('api/v1/server/licenses/validate', []);
        $this->assertSame(401, $res->response()->getStatusCode());

        // A new key: the old one activates nothing more.
        [$s, $n] = $this->api($this->customer, 'POST', "api/v1/portal/licenses/{$l['id']}/replace-key");
        $this->assertSame(200, $s);
        $this->assertNotSame($l['license_key'], $n['license_key']);
        $this->setCookie('uc_session', null);
        $this->assertSame(404, $this->withBodyFormat('json')->post('api/v1/server/licenses/activate', ['license_key' => $l['license_key']])->response()->getStatusCode());
        $this->assertSame(201, $this->withBodyFormat('json')->post('api/v1/server/licenses/activate', ['license_key' => $n['license_key'], 'fingerprint' => 'new'])->response()->getStatusCode());
    }

    public function testEditionPreviewAndOneTrialPerAccount(): void
    {
        $l = $this->licence('it@acme.lk');
        [$s, $p] = $this->api($this->customer, 'GET', "api/v1/portal/licenses/{$l['id']}/edition-preview?edition=enterprise");
        $this->assertSame(200, $s, json_encode($p));
        $this->assertContains('garage.enabled', array_column($p['adds'], 'key'));

        [, $list] = $this->api($this->customer, 'GET', 'api/v1/portal/licenses');
        $this->assertTrue($list['trial_available']);
        [$s, $t] = $this->api($this->customer, 'POST', 'api/v1/portal/licenses/trial', ['edition' => 'enterprise', 'organization' => 'Acme']);
        $this->assertSame([201, 'ENTERPRISE', true], [$s, $t['edition'], $t['is_trial']], json_encode($t));
        $this->assertMatchesRegularExpression('/^WS-/', $t['license_key']);
        [$s, $j] = $this->api($this->customer, 'POST', 'api/v1/portal/licenses/trial', ['edition' => 'business']);
        $this->assertSame([409, 'TRIAL_USED'], [$s, $j['error']['code']]);
    }

    public function testAnUnverifiedEmailSeesNothing(): void
    {
        $u = $this->makeUser(['portal_user'], 'new@acme.lk', 'PORTAL');
        db_connect()->table('users')->where('id', $u['id'])->update(['email_verified_at' => null]);
        $u = db_connect()->table('users')->where('id', $u['id'])->get()->getRowArray();
        $this->licence('new@acme.lk');
        [$s, $j] = $this->api($u, 'GET', 'api/v1/portal/licenses');
        $this->assertSame([403, 'EMAIL_NOT_VERIFIED'], [$s, $j['error']['code'] ?? null], json_encode($j));
    }
}

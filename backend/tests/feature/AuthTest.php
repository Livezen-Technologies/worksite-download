<?php

namespace Tests\Feature;

use App\Libraries\SecretStore;
use App\Libraries\Totp;
use Tests\Support\UcTestCase;

final class AuthTest extends UcTestCase
{
    public function testLoginWithoutMfaReturnsUserAndSetsCookie(): void
    {
        $this->makeUser(['release_manager'], 'rm@livezen.lk');
        $res = $this->withBodyFormat('json')->post('api/v1/auth/login', ['email' => 'rm@livezen.lk', 'password' => 'correct horse battery']);
        $res->assertStatus(200);
        $j = $this->json($res);
        $this->assertFalse($j['mfa_required']);
        $this->assertContains('release.publish', $j['user']['permissions']);
        $this->assertTrue($res->response()->hasCookie('uc_session'));
    }

    public function testWrongPasswordIsRejectedAndAudited(): void
    {
        $this->makeUser(['viewer'], 'v@livezen.lk');
        $res = $this->withBodyFormat('json')->post('api/v1/auth/login', ['email' => 'v@livezen.lk', 'password' => 'nope nope nope']);
        $res->assertStatus(401);
        $this->assertSame('INVALID_CREDENTIALS', $this->json($res)['error']['code']);
        $this->seeInDatabase('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function testMfaFlow(): void
    {
        $u      = $this->makeUser(['qa'], 'qa@livezen.lk');
        $secret = Totp::generateSecret();
        db_connect()->table('users')->where('id', $u['id'])->update(['mfa_enabled' => 1, 'mfa_totp_secret' => SecretStore::seal($secret)]);

        $res = $this->withBodyFormat('json')->post('api/v1/auth/login', ['email' => 'qa@livezen.lk', 'password' => 'correct horse battery']);
        $j   = $this->json($res);
        $this->assertTrue($j['mfa_required']);
        $this->assertSame(['totp'], $j['methods']);

        $token = db_connect()->table('user_sessions')->where('user_id', $u['id'])->get()->getRowArray();
        $this->assertSame('1', (string) $token['mfa_pending']);
        $cookie = $res->response()->getCookie('uc_session')->getValue();
        $this->setCookie('uc_session', $cookie);

        // A pending session cannot use the API.
        $this->get('api/v1/auth/me')->assertStatus(401);

        $bad = $this->withHeaders(['X-CSRF-Token' => $j['csrf']])->withBodyFormat('json')->post('api/v1/auth/mfa', ['code' => '000000']);
        $bad->assertStatus(401);
        $ok = $this->withHeaders(['X-CSRF-Token' => $j['csrf']])->withBodyFormat('json')->post('api/v1/auth/mfa', ['code' => Totp::code($secret)]);
        $this->assertSame(200, $ok->response()->getStatusCode(), (string) $ok->response()->getBody());
        $this->get('api/v1/auth/me')->assertStatus(200);
    }

    public function testMfaSwitchedOffAsksNoStepUpOfPeopleButStillOfCiInProduction(): void
    {
        $cfg = config('UpdateCenter');
        $cfg->environmentName = 'PRODUCTION';
        $cfg->mfaDisabled     = true;
        try {
            $person       = new \App\Services\AuthContext();
            $person->user = $this->makeUser(['release_manager']);
            $this->assertFalse((new \App\Services\ReleaseService($person))->stepUpNeeded(), 'a person publishes without a code');

            $ci            = new \App\Services\AuthContext();
            $ci->apiClient = ['id' => 1];
            $this->assertTrue((new \App\Services\ReleaseService($ci))->stepUpNeeded(), 'a CI client still cannot publish');

            $cfg->mfaDisabled = false;
            $this->assertTrue((new \App\Services\ReleaseService($person))->stepUpNeeded(), 'switch off: production asks again');
        } finally {
            $cfg->environmentName = 'DEV';
            $cfg->mfaDisabled     = false;
        }
    }

    public function testMfaSwitchedOffSignsInWithThePasswordAlone(): void
    {
        $u = $this->makeUser(['release_manager'], 'off@livezen.lk');
        db_connect()->table('users')->where('id', $u['id'])->update(['mfa_enabled' => 1, 'mfa_totp_secret' => SecretStore::seal(Totp::generateSecret())]);
        $cfg              = config('UpdateCenter');
        $cfg->mfaDisabled = true;

        try {
            $res = $this->withBodyFormat('json')->post('api/v1/auth/login', ['email' => 'off@livezen.lk', 'password' => 'correct horse battery']);
            $res->assertStatus(200);
            $this->assertFalse($this->json($res)['mfa_required']);
            $session = db_connect()->table('user_sessions')->where('user_id', $u['id'])->get()->getRowArray();
            $this->assertSame('0', (string) $session['mfa_pending']);
            $this->setCookie('uc_session', $res->response()->getCookie('uc_session')->getValue());
            $this->get('api/v1/auth/me')->assertStatus(200);
        } finally {
            $cfg->mfaDisabled = false;
        }
    }

    public function testCsrfRequiredForCookieSessions(): void
    {
        $u = $this->makeUser(['super_admin']);
        $this->actingAs($u);
        $this->withBodyFormat('json')->post('api/v1/auth/step-up', ['code' => '1'])->assertStatus(403);
    }

    public function testTotpRfcVector(): void
    {
        // RFC 6238 test secret "12345678901234567890" at T=59 → 94287082 (8 digits) → last 6 = 287082
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, 59));
    }
}

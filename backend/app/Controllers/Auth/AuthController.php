<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Services\AuthService;
use CodeIgniter\Cookie\Cookie;

/**
 * Sign-in, MFA, passkeys, password reset and portal registration (FR-UC-AUTH).
 */
class AuthController extends BaseApiController
{
    private function svc(): AuthService
    {
        return new AuthService();
    }

    private function setSessionCookies(string $token, string $csrf): void
    {
        $secure = config('UpdateCenter')->isProduction() || str_starts_with(config('UpdateCenter')->publicBaseUrl, 'https://');
        $this->response->setCookie(new Cookie(AuthService::COOKIE, $token, ['path' => '/', 'httponly' => true, 'secure' => $secure, 'samesite' => Cookie::SAMESITE_LAX, 'raw' => true, 'expires' => 0]));
        $this->response->setCookie(new Cookie(AuthService::CSRF_COOKIE, $csrf, ['path' => '/', 'httponly' => false, 'secure' => $secure, 'samesite' => Cookie::SAMESITE_LAX, 'raw' => true, 'expires' => 0]));
    }

    public static function describeUser(array $user, array $roles, array $permissions): array
    {
        $passkeys = db_connect()->table('webauthn_credentials')->where('user_id', $user['id'])->countAllResults();

        return [
            'id'          => $user['public_id'],
            'email'       => $user['email'],
            'name'        => $user['name'],
            'kind'        => $user['kind'],
            'mfa_enabled' => (bool) $user['mfa_enabled'],
            'passkeys'    => $passkeys,
            'roles'       => $roles,
            'permissions' => $permissions,
            'beta_opt_in' => (bool) $user['beta_opt_in'],
        ];
    }

    private function meFromContext(): array
    {
        $rp = $this->svc()->rolesAndPermissions((int) $this->auth->user['id']);

        return self::describeUser($this->auth->user, $rp['roles'], $rp['permissions']);
    }

    public function login()
    {
        $b = $this->body();
        $r = $this->svc()->login((string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''), $this->request->getIPAddress(), (string) $this->request->getUserAgent());
        $this->setSessionCookies($r['token'], $r['csrf']);
        $methods = [];
        if ($r['mfa_required']) {
            if (! empty($r['user']['mfa_totp_secret'])) {
                $methods[] = 'totp';
            }
            if (db_connect()->table('webauthn_credentials')->where('user_id', $r['user']['id'])->countAllResults() > 0) {
                $methods[] = 'passkey';
            }
        }
        $user = null;
        if (! $r['mfa_required']) {
            $rp   = $this->svc()->rolesAndPermissions((int) $r['user']['id']);
            $user = self::describeUser($r['user'], $rp['roles'], $rp['permissions']);
        }

        return ['mfa_required' => $r['mfa_required'], 'csrf' => $r['csrf'], 'user' => $user, 'methods' => $methods];
    }

    public function mfa()
    {
        $this->svc()->completeMfa($this->auth, (string) ($this->body()['code'] ?? ''));

        return ['user' => $this->meFromContext(), 'csrf' => $this->auth->session['csrf_token']];
    }

    public function passkeyOptions()
    {
        return $this->svc()->passkeyAssertOptions($this->auth->user);
    }

    public function passkeyVerify()
    {
        $this->svc()->passkeyAssert($this->auth, $this->body());

        return ['user' => $this->meFromContext(), 'csrf' => $this->auth->session['csrf_token']];
    }

    public function logout()
    {
        $this->svc()->logout($this->auth);
        $this->response->deleteCookie(AuthService::COOKIE);
        $this->response->deleteCookie(AuthService::CSRF_COOKIE);

        return ['ok' => true];
    }

    public function me()
    {
        if ($this->auth->user === null) {
            throw new ApiException('UNAUTHENTICATED', 'Sign in required', 401);
        }
        $until = null;
        if (! empty($this->auth->session['mfa_verified_at'])) {
            $t = strtotime($this->auth->session['mfa_verified_at'] . ' UTC') + config('UpdateCenter')->stepUpSeconds;
            if ($t > time()) {
                $until = gmdate('Y-m-d\TH:i:s\Z', $t);
            }
        }

        return ['user' => $this->meFromContext(), 'csrf' => $this->auth->session['csrf_token'] ?? null, 'environment' => config('UpdateCenter')->environmentName, 'step_up_valid_until' => $until];
    }

    public function stepUp()
    {
        $code = (string) ($this->body()['code'] ?? '');
        service('rateLimiter')->hit('mfa:' . $this->auth->user['id'], 5, 60);
        if (! $this->svc()->verifySecondFactor($this->auth->user, $code)) {
            throw new ApiException('MFA_INVALID', 'Code is not valid', 401);
        }
        $this->svc()->markMfa($this->auth);

        return ['step_up_valid_until' => gmdate('Y-m-d\TH:i:s\Z', time() + config('UpdateCenter')->stepUpSeconds)];
    }

    public function totpSetup()
    {
        return $this->svc()->beginTotpEnrollment($this->auth->user);
    }

    public function totpConfirm()
    {
        return ['recovery_codes' => $this->svc()->confirmTotpEnrollment($this->auth, (string) ($this->body()['code'] ?? ''))];
    }

    public function passkeyRegisterOptions()
    {
        return $this->svc()->passkeyRegisterOptions($this->auth->user);
    }

    public function passkeyRegister()
    {
        $this->svc()->passkeyRegister($this->auth, $this->body());

        return ['ok' => true];
    }

    public function forgot()
    {
        $this->svc()->requestPasswordReset((string) ($this->body()['email'] ?? ''));

        return $this->ok(['ok' => true], 202);
    }

    public function reset()
    {
        $b = $this->body();
        $this->svc()->resetPassword((string) ($b['token'] ?? ''), (string) ($b['password'] ?? ''));

        return ['ok' => true];
    }

    public function register()
    {
        $b = $this->body();
        $this->svc()->registerPortalUser((string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''), trim((string) ($b['name'] ?? '')) ?: 'WorkSuite user');

        return $this->ok(['status' => 'verification_sent'], 201);
    }

    public function verifyEmail()
    {
        $this->svc()->verifyEmail((string) ($this->body()['token'] ?? ''));

        return ['ok' => true];
    }
}

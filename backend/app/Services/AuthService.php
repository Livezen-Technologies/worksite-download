<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\Base64Url;
use App\Libraries\Rbac;
use App\Libraries\SecretStore;
use App\Libraries\Totp;
use App\Libraries\Ulid;

/**
 * Staff/portal authentication (FR-UC-AUTH-01..05): Argon2id passwords, TOTP,
 * recovery codes, WebAuthn passkeys, DB-backed sessions with idle/absolute
 * timeouts, CSRF tokens, brute-force limits and API client credentials.
 */
class AuthService
{
    public const COOKIE = 'uc_session';
    public const CSRF_COOKIE = 'uc_csrf';

    private AuditService $audit;

    public function __construct()
    {
        $this->audit = service('audit');
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    // ---- Users ---------------------------------------------------------

    /** @param list<string> $roles */
    public function createUser(string $email, string $password, string $name, array $roles = [], string $kind = 'STAFF', bool $verified = true): array
    {
        $db    = db_connect();
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ApiException::validation(['email' => 'Invalid email']);
        }
        if (strlen($password) < 12) {
            throw ApiException::validation(['password' => 'Password must be at least 12 characters']);
        }
        if ($db->table('users')->where('email', $email)->countAllResults() > 0) {
            throw ApiException::conflict('EMAIL_TAKEN', 'An account with this email exists');
        }
        $now = gmdate('Y-m-d H:i:s');
        $db->table('users')->insert([
            'public_id'          => Ulid::prefixed('usr'),
            'email'              => $email,
            'password_hash'      => self::hashPassword($password),
            'name'               => $name,
            'kind'               => $kind,
            'status'             => 'ACTIVE',
            'email_verified_at'  => $verified ? $now : null,
            'email_verify_token' => $verified ? null : bin2hex(random_bytes(24)),
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $id = (int) $db->insertID();
        $this->setRoles($id, $roles);

        return $db->table('users')->where('id', $id)->get()->getRowArray();
    }

    /** @param list<string> $roles */
    public function setRoles(int $userId, array $roles): void
    {
        $db = db_connect();
        $db->table('user_roles')->where('user_id', $userId)->delete();
        foreach ($roles as $slug) {
            $role = $db->table('roles')->where('slug', $slug)->get()->getRowArray();
            if ($role === null) {
                throw ApiException::validation(['roles' => "Unknown role {$slug}"]);
            }
            $db->table('user_roles')->insert(['user_id' => $userId, 'role_id' => $role['id']]);
        }
        // Privilege change → rotate sessions (§12.1).
        $db->table('user_sessions')->where('user_id', $userId)->where('revoked_at', null)->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** @return array{roles: list<string>, permissions: list<string>} */
    public function rolesAndPermissions(int $userId): array
    {
        $db    = db_connect();
        $roles = array_column($db->table('user_roles ur')->select('r.slug')->join('roles r', 'r.id = ur.role_id')->where('ur.user_id', $userId)->get()->getResultArray(), 'slug');
        $perms = array_column($db->table('user_roles ur')->distinct()->select('p.slug')
            ->join('role_permissions rp', 'rp.role_id = ur.role_id')->join('permissions p', 'p.id = rp.permission_id')
            ->where('ur.user_id', $userId)->get()->getResultArray(), 'slug');

        return ['roles' => $roles, 'permissions' => array_values(array_unique($perms))];
    }

    // ---- Login ---------------------------------------------------------

    /**
     * @return array{token: string, csrf: string, mfa_required: bool, user: array<string,mixed>}
     */
    public function login(string $email, string $password, string $ip, string $ua): array
    {
        $email = strtolower(trim($email));
        $rl    = service('rateLimiter');
        $rl->hit('login:acct:' . hash('sha256', $email), 5, 60);
        $rl->hit('login:ip:' . $ip, 20, 60);

        $db   = db_connect();
        $user = $db->table('users')->where('email', $email)->get()->getRowArray();
        $fail = static fn () => new ApiException('INVALID_CREDENTIALS', 'Email or password is incorrect', 401);

        if ($user === null) {
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$RdescudvJCsgt3ub+b+dWRWJTmaaJObG'); // equalise timing
            $this->audit->record('auth.login_failed', 'user', null, null, ['email' => $email], null, ['type' => 'USER', 'id' => null, 'label' => $email]);

            throw $fail();
        }
        if (! empty($user['locked_until']) && strtotime($user['locked_until'] . ' UTC') > time()) {
            throw new ApiException('ACCOUNT_LOCKED', 'Account temporarily locked after failed attempts', 423, ['locked_until' => $user['locked_until']]);
        }
        if (! password_verify($password, $user['password_hash'])) {
            $fails  = (int) $user['failed_logins'] + 1;
            $update = ['failed_logins' => $fails];
            if ($fails >= 5) {
                // Exponential lockout: 1, 2, 4 … minutes, capped at 1 h.
                $update['locked_until'] = gmdate('Y-m-d H:i:s', time() + min(3600, 60 * 2 ** ($fails - 5)));
            }
            $db->table('users')->where('id', $user['id'])->update($update);
            $this->audit->record('auth.login_failed', 'user', $user['public_id'], null, null, null, ['type' => 'USER', 'id' => $user['public_id'], 'label' => $email]);

            throw $fail();
        }
        if ($user['status'] !== 'ACTIVE') {
            throw new ApiException('ACCOUNT_DISABLED', 'Account is not active', 403);
        }
        if ($user['kind'] === 'PORTAL' && empty($user['email_verified_at'])) {
            throw new ApiException('EMAIL_NOT_VERIFIED', 'Verify your email address first', 403);
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            $db->table('users')->where('id', $user['id'])->update(['password_hash' => self::hashPassword($password)]);
        }
        $db->table('users')->where('id', $user['id'])->update(['failed_logins' => 0, 'locked_until' => null]);

        $mfaRequired = (bool) $user['mfa_enabled'];
        $session     = $this->createSession((int) $user['id'], $ip, $ua, $mfaRequired);
        if (! $mfaRequired) {
            $this->completeLogin($user);
        }

        return $session + ['mfa_required' => $mfaRequired, 'user' => $user];
    }

    private function completeLogin(array $user): void
    {
        db_connect()->table('users')->where('id', $user['id'])->update(['last_login_at' => gmdate('Y-m-d H:i:s')]);
        $this->audit->record('auth.login', 'user', $user['public_id'], null, null, null, ['type' => 'USER', 'id' => $user['public_id'], 'label' => $user['email']]);
    }

    /** @return array{token: string, csrf: string} */
    public function createSession(int $userId, string $ip, string $ua, bool $mfaPending): array
    {
        $cfg   = config('UpdateCenter');
        $token = Base64Url::encode(random_bytes(32));
        $csrf  = bin2hex(random_bytes(16));
        $now   = time();
        db_connect()->table('user_sessions')->insert([
            'token_hash'   => hash('sha256', $token),
            'user_id'      => $userId,
            'csrf_token'   => $csrf,
            'ip'           => $ip,
            'user_agent'   => substr($ua, 0, 255),
            'mfa_pending'  => $mfaPending ? 1 : 0,
            'last_seen_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at'   => gmdate('Y-m-d H:i:s', $now + $cfg->sessionAbsoluteSeconds),
            'created_at'   => gmdate('Y-m-d H:i:s', $now),
        ]);

        return ['token' => $token, 'csrf' => $csrf];
    }

    public function loadSession(string $token, AuthContext $ctx, bool $allowMfaPending = false): void
    {
        $cfg = config('UpdateCenter');
        $db  = db_connect();
        $s   = $db->table('user_sessions')->where('token_hash', hash('sha256', $token))->where('revoked_at', null)->get()->getRowArray();
        if ($s === null) {
            return;
        }
        $now = time();
        if (strtotime($s['expires_at'] . ' UTC') < $now || strtotime($s['last_seen_at'] . ' UTC') < $now - $cfg->sessionIdleSeconds) {
            $db->table('user_sessions')->where('id', $s['id'])->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);

            return;
        }
        if ($s['mfa_pending'] && ! $allowMfaPending) {
            return;
        }
        $user = $db->table('users')->where('id', $s['user_id'])->where('status', 'ACTIVE')->get()->getRowArray();
        if ($user === null) {
            return;
        }
        if (strtotime($s['last_seen_at'] . ' UTC') < $now - 60) {
            $db->table('user_sessions')->where('id', $s['id'])->update(['last_seen_at' => gmdate('Y-m-d H:i:s', $now)]);
        }
        $rp               = $this->rolesAndPermissions((int) $user['id']);
        $ctx->user        = $user;
        $ctx->session     = $s;
        $ctx->roles       = $rp['roles'];
        $ctx->permissions = $s['mfa_pending'] ? [] : $rp['permissions'];
    }

    public function logout(AuthContext $ctx): void
    {
        if ($ctx->session !== null) {
            db_connect()->table('user_sessions')->where('id', $ctx->session['id'])->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);
            $this->audit->record('auth.logout', 'user', $ctx->actorId());
        }
    }

    // ---- MFA -----------------------------------------------------------

    public function verifySecondFactor(array $user, string $code): bool
    {
        $code = trim($code);
        if (! empty($user['mfa_totp_secret'])) {
            $secret = SecretStore::get($user['mfa_totp_secret']);
            if ($secret !== null && Totp::verify($secret, $code)) {
                return true;
            }
        }
        // One-time recovery code.
        $codes = json_decode((string) ($user['recovery_codes'] ?? '[]'), true) ?: [];
        $hash  = hash('sha256', strtoupper(str_replace('-', '', $code)));
        $idx   = array_search($hash, $codes, true);
        if ($idx !== false) {
            unset($codes[$idx]);
            db_connect()->table('users')->where('id', $user['id'])->update(['recovery_codes' => json_encode(array_values($codes))]);
            $this->audit->record('auth.recovery_code_used', 'user', $user['public_id']);

            return true;
        }

        return false;
    }

    /** Complete a pending (password-ok) login with a second factor. */
    public function completeMfa(AuthContext $ctx, string $code): void
    {
        if ($ctx->session === null || $ctx->user === null) {
            throw new ApiException('UNAUTHENTICATED', 'No pending sign-in', 401);
        }
        service('rateLimiter')->hit('mfa:' . $ctx->user['id'], 5, 60);
        if (! $this->verifySecondFactor($ctx->user, $code)) {
            $this->audit->record('auth.mfa_failed', 'user', $ctx->user['public_id'], null, null, null, ['type' => 'USER', 'id' => $ctx->user['public_id'], 'label' => $ctx->user['email']]);

            throw new ApiException('MFA_INVALID', 'Code is not valid', 401);
        }
        $this->markMfa($ctx);
        $this->completeLogin($ctx->user);
    }

    public function markMfa(AuthContext $ctx): void
    {
        if ($ctx->session === null) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        db_connect()->table('user_sessions')->where('id', $ctx->session['id'])->update(['mfa_pending' => 0, 'mfa_verified_at' => $now]);
        $ctx->session['mfa_pending']     = 0;
        $ctx->session['mfa_verified_at'] = $now;
    }

    /** @return array{secret: string, otpauth_uri: string} */
    public function beginTotpEnrollment(array $user): array
    {
        $secret = Totp::generateSecret();
        cache()->save('totp_enroll_' . $user['id'], $secret, 900);

        return ['secret' => $secret, 'otpauth_uri' => Totp::uri($secret, $user['email'])];
    }

    /** @return list<string> recovery codes (shown once) */
    public function confirmTotpEnrollment(AuthContext $ctx, string $code): array
    {
        $user   = $ctx->user;
        $secret = cache()->get('totp_enroll_' . $user['id']);
        if (! is_string($secret) || ! Totp::verify($secret, $code)) {
            throw new ApiException('MFA_INVALID', 'Code is not valid — scan the QR code again', 422);
        }
        cache()->delete('totp_enroll_' . $user['id']);
        $codes  = [];
        $hashes = [];
        for ($i = 0; $i < 10; $i++) {
            $c        = strtoupper(bin2hex(random_bytes(5)));
            $codes[]  = substr($c, 0, 5) . '-' . substr($c, 5);
            $hashes[] = hash('sha256', $c);
        }
        db_connect()->table('users')->where('id', $user['id'])->update([
            'mfa_totp_secret' => SecretStore::seal($secret),
            'mfa_enabled'     => 1,
            'recovery_codes'  => json_encode($hashes),
            'updated_at'      => gmdate('Y-m-d H:i:s'),
        ]);
        $this->markMfa($ctx);
        $this->audit->record('auth.mfa_enabled', 'user', $user['public_id']);

        return $codes;
    }

    /** Lost-device recovery by a Super Admin (FR-UC-AUTH-02). */
    public function resetMfa(int $userId, string $reason): void
    {
        $db   = db_connect();
        $user = $db->table('users')->where('id', $userId)->get()->getRowArray() ?? throw ApiException::notFound('USER_NOT_FOUND', 'User not found');
        $db->table('users')->where('id', $userId)->update(['mfa_totp_secret' => null, 'mfa_enabled' => 0, 'recovery_codes' => null]);
        $db->table('webauthn_credentials')->where('user_id', $userId)->delete();
        $db->table('user_sessions')->where('user_id', $userId)->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);
        $this->audit->record('auth.mfa_reset', 'user', $user['public_id'], null, null, $reason);
    }

    // ---- Passkeys (WebAuthn) ---------------------------------------------

    private function webauthn(): \lbuchs\WebAuthn\WebAuthn
    {
        $host = parse_url(config('UpdateCenter')->publicBaseUrl, PHP_URL_HOST) ?: 'localhost';

        return new \lbuchs\WebAuthn\WebAuthn('WorkSuite Update Center', $host, ['none', 'packed', 'fido-u2f', 'apple', 'android-key', 'tpm'], true);
    }

    public function passkeyRegisterOptions(array $user): array
    {
        $wa       = $this->webauthn();
        $existing = array_column(db_connect()->table('webauthn_credentials')->where('user_id', $user['id'])->get()->getResultArray(), 'credential_id');
        $args     = $wa->getCreateArgs(Base64Url::decode(Base64Url::encode($user['public_id'])), $user['email'], $user['name'], 60, true, true, null, array_map([Base64Url::class, 'decode'], $existing));
        cache()->save('wa_reg_' . $user['id'], Base64Url::encode($wa->getChallenge()->getBinaryString()), 300);

        return json_decode(json_encode($args), true);
    }

    public function passkeyRegister(AuthContext $ctx, array $body): void
    {
        $challenge = cache()->get('wa_reg_' . $ctx->user['id']);
        if (! is_string($challenge)) {
            throw new ApiException('PASSKEY_CHALLENGE_EXPIRED', 'Start passkey registration again', 422);
        }
        try {
            $data = $this->webauthn()->processCreate(Base64Url::decode($body['clientDataJSON'] ?? ''), Base64Url::decode($body['attestationObject'] ?? ''), Base64Url::decode($challenge), true, true, false);
        } catch (\Throwable $e) {
            throw new ApiException('PASSKEY_INVALID', 'Passkey registration failed: ' . $e->getMessage(), 422);
        }
        db_connect()->table('webauthn_credentials')->insert([
            'user_id'       => $ctx->user['id'],
            'credential_id' => Base64Url::encode($data->credentialId),
            'public_key'    => $data->credentialPublicKey,
            'sign_count'    => (int) ($data->signatureCounter ?? 0),
            'name'          => substr((string) ($body['name'] ?? 'Passkey'), 0, 100),
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        db_connect()->table('users')->where('id', $ctx->user['id'])->update(['mfa_enabled' => 1]);
        $this->audit->record('auth.passkey_added', 'user', $ctx->user['public_id']);
    }

    public function passkeyAssertOptions(array $user): array
    {
        $wa    = $this->webauthn();
        $creds = array_column(db_connect()->table('webauthn_credentials')->where('user_id', $user['id'])->get()->getResultArray(), 'credential_id');
        if ($creds === []) {
            throw new ApiException('NO_PASSKEY', 'No passkey registered', 422);
        }
        $args = $wa->getGetArgs(array_map([Base64Url::class, 'decode'], $creds), 60, true, true, true, true, true, true);
        cache()->save('wa_get_' . $user['id'], Base64Url::encode($wa->getChallenge()->getBinaryString()), 300);

        return json_decode(json_encode($args), true);
    }

    /** Verify a passkey assertion — counts as the second factor / step-up. */
    public function passkeyAssert(AuthContext $ctx, array $body): void
    {
        $user      = $ctx->user;
        $challenge = cache()->get('wa_get_' . $user['id']);
        $cred      = db_connect()->table('webauthn_credentials')->where('user_id', $user['id'])->where('credential_id', (string) ($body['id'] ?? ''))->get()->getRowArray();
        if (! is_string($challenge) || $cred === null) {
            throw new ApiException('PASSKEY_INVALID', 'Unknown passkey or expired challenge', 401);
        }
        $wa = $this->webauthn();
        try {
            $wa->processGet(Base64Url::decode($body['clientDataJSON'] ?? ''), Base64Url::decode($body['authenticatorData'] ?? ''), Base64Url::decode($body['signature'] ?? ''), $cred['public_key'], Base64Url::decode($challenge), (int) $cred['sign_count'], true);
        } catch (\Throwable $e) {
            log_message('warning', 'Passkey assertion failed for credential {id}: {why}', ['id' => $cred['id'], 'why' => $e->getMessage()]);
            throw new ApiException('PASSKEY_INVALID', 'Passkey verification failed', 401);
        }
        cache()->delete('wa_get_' . $user['id']);
        // The authenticator's own counter, not ours plus one: synced passkeys (iCloud
        // Keychain, Google Password Manager) always report 0, and storing 1 made the
        // library refuse their second sign-in as a cloned key.
        db_connect()->table('webauthn_credentials')->where('id', $cred['id'])->update([
            'last_used_at' => gmdate('Y-m-d H:i:s'),
            'sign_count'   => (int) ($wa->getSignatureCounter() ?? 0),
        ]);
        $wasPending = (bool) ($ctx->session['mfa_pending'] ?? false);
        $this->markMfa($ctx);
        if ($wasPending) {
            $this->completeLogin($user);
        }
    }

    // ---- Password reset & portal accounts ------------------------------

    public function requestPasswordReset(string $email): ?string
    {
        $user = db_connect()->table('users')->where('email', strtolower(trim($email)))->get()->getRowArray();
        if ($user === null) {
            return null;
        }
        $token = Base64Url::encode(random_bytes(24));
        db_connect()->table('password_resets')->insert([
            'user_id'    => $user['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        service('notifier')->email($user['email'], 'Reset your WorkSuite Update Center password', "Use this link within one hour:\n" . config('UpdateCenter')->publicBaseUrl . '/reset-password?token=' . $token);
        $this->audit->record('auth.password_reset_requested', 'user', $user['public_id'], null, null, null, ['type' => 'USER', 'id' => $user['public_id'], 'label' => $user['email']]);

        return $token;
    }

    public function resetPassword(string $token, string $password): void
    {
        $db  = db_connect();
        $row = $db->table('password_resets')->where('token_hash', hash('sha256', $token))->where('used_at', null)->get()->getRowArray();
        if ($row === null || strtotime($row['expires_at'] . ' UTC') < time()) {
            throw new ApiException('TOKEN_INVALID', 'Reset link is invalid or expired', 422);
        }
        if (strlen($password) < 12) {
            throw ApiException::validation(['password' => 'Password must be at least 12 characters']);
        }
        $db->table('users')->where('id', $row['user_id'])->update(['password_hash' => self::hashPassword($password), 'failed_logins' => 0, 'locked_until' => null]);
        $db->table('password_resets')->where('id', $row['id'])->update(['used_at' => gmdate('Y-m-d H:i:s')]);
        $db->table('user_sessions')->where('user_id', $row['user_id'])->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);
        $user = $db->table('users')->where('id', $row['user_id'])->get()->getRowArray();
        $this->audit->record('auth.password_reset', 'user', $user['public_id'], null, null, null, ['type' => 'USER', 'id' => $user['public_id'], 'label' => $user['email']]);
    }

    public function registerPortalUser(string $email, string $password, string $name): array
    {
        $user = $this->createUser($email, $password, $name, ['portal_user'], 'PORTAL', false);
        service('notifier')->email($user['email'], 'Confirm your WorkSuite Downloads account', "Confirm your email:\n" . config('UpdateCenter')->publicBaseUrl . '/portal/verify?token=' . $user['email_verify_token']);

        return $user;
    }

    public function verifyEmail(string $token): void
    {
        $db   = db_connect();
        $user = $db->table('users')->where('email_verify_token', $token)->get()->getRowArray();
        if ($user === null) {
            throw new ApiException('TOKEN_INVALID', 'Verification link is invalid', 422);
        }
        $db->table('users')->where('id', $user['id'])->update(['email_verified_at' => gmdate('Y-m-d H:i:s'), 'email_verify_token' => null]);
    }

    // ---- API clients ---------------------------------------------------

    /**
     * @param list<string> $permissions
     * @param list<string> $ipAllowlist
     *
     * @return array{client: array<string,mixed>, secret: string}
     */
    public function createApiClient(string $name, string $kind, array $permissions, array $ipAllowlist = [], ?string $expiresAt = null, ?int $createdBy = null, ?int $tenantId = null): array
    {
        $valid = array_keys(Rbac::MATRIX);
        foreach ($permissions as $p) {
            if (! in_array($p, $valid, true)) {
                throw ApiException::validation(['permissions' => "Unknown permission {$p}"]);
            }
        }
        $clientId = 'uc_' . strtolower($kind) . '_' . bin2hex(random_bytes(8));
        $secret   = Base64Url::encode(random_bytes(32));
        $now      = gmdate('Y-m-d H:i:s');
        $db       = db_connect();
        $db->table('api_clients')->insert([
            'public_id'    => Ulid::prefixed('cli'),
            'name'         => $name,
            'kind'         => $kind,
            'client_id'    => $clientId,
            'secret_hash'  => password_hash($secret, PASSWORD_ARGON2ID),
            'permissions'  => json_encode(array_values($permissions)),
            'ip_allowlist' => json_encode(array_values($ipAllowlist)),
            'tenant_id'    => $tenantId,
            'expires_at'   => $expiresAt,
            'created_by'   => $createdBy,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $client = $db->table('api_clients')->where('id', $db->insertID())->get()->getRowArray();
        $this->audit->record('api_client.created', 'api_client', $client['public_id'], null, ['name' => $name, 'kind' => $kind, 'permissions' => $permissions]);
        if (in_array('release.publish', $permissions, true)) {
            $this->audit->record('api_client.publish_granted', 'api_client', $client['public_id'], null, null, 'CI token granted release.publish (not recommended)');
        }

        return ['client' => $client, 'secret' => $clientId . '.' . $secret];
    }

    public function rotateApiClient(int $id): string
    {
        $db     = db_connect();
        $client = $db->table('api_clients')->where('id', $id)->get()->getRowArray() ?? throw ApiException::notFound('CLIENT_NOT_FOUND', 'API client not found');
        $secret = Base64Url::encode(random_bytes(32));
        $db->table('api_clients')->where('id', $id)->update(['secret_hash' => password_hash($secret, PASSWORD_ARGON2ID), 'rotated_at' => gmdate('Y-m-d H:i:s')]);
        $this->audit->record('api_client.rotated', 'api_client', $client['public_id']);

        return $client['client_id'] . '.' . $secret;
    }

    public function authenticateClient(string $bearer, string $ip): ?array
    {
        if (! str_contains($bearer, '.')) {
            return null;
        }
        [$clientId, $secret] = explode('.', $bearer, 2);
        $db                  = db_connect();
        $client              = $db->table('api_clients')->where('client_id', $clientId)->where('revoked_at', null)->get()->getRowArray();
        if ($client === null || empty($client['secret_hash'])) {
            return null;
        }
        if (! empty($client['expires_at']) && strtotime($client['expires_at'] . ' UTC') < time()) {
            return null;
        }
        $cacheKey = 'cli_ok_' . hash('sha256', $bearer);
        if (cache()->get($cacheKey) !== $client['secret_hash'] && ! password_verify($secret, $client['secret_hash'])) {
            return null;
        }
        cache()->save($cacheKey, $client['secret_hash'], 300);
        $allow = json_decode((string) $client['ip_allowlist'], true) ?: [];
        if ($allow !== [] && ! self::ipAllowed($ip, $allow)) {
            return null;
        }
        if (empty($client['last_used_at']) || strtotime($client['last_used_at'] . ' UTC') < time() - 300) {
            $db->table('api_clients')->where('id', $client['id'])->update(['last_used_at' => gmdate('Y-m-d H:i:s')]);
        }

        return $client;
    }

    /** @param list<string> $cidrs */
    public static function ipAllowed(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (! str_contains($cidr, '/')) {
                if ($ip === $cidr) {
                    return true;
                }

                continue;
            }
            [$net, $bits] = explode('/', $cidr, 2);
            $ipBin        = @inet_pton($ip);
            $netBin       = @inet_pton($net);
            if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
                continue;
            }
            $bits  = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            $rem = $bits % 8;
            if ($rem === 0 || ((ord($ipBin[$bytes]) ^ ord($netBin[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace Tests\Support;

use App\Libraries\SecretStore;
use App\Services\AuthService;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Base test case: fresh SQLite schema + RBAC/catalog seeds, inline queue, no
 * rate limits, dev keys under a temp dir. Helpers to act as a user/client.
 */
abstract class UcTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;
    protected $seed        = 'App\Database\Seeds\DatabaseSeeder';

    protected static ?string $keyDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::$keyDir ??= sys_get_temp_dir() . '/uc-test-keys-' . getmypid();
        $cfg                   = config('UpdateCenter');
        $cfg->queueSync        = true;
        $cfg->rateLimitEnabled = false;
        $cfg->devKeyDir        = self::$keyDir;
        $cfg->environmentName  = 'DEV';
        $cfg->requireApprovals = true;
        $cfg->requireAttestation = false;
        $cfg->publicBaseUrl    = 'http://example.com';
        $cfg->cdnHost          = 'http://example.com/_dev-cdn';
        Services::reset(true);
        cache()->clean();
        SecretStore::override('UC_APP_SECRET', 'test-app-secret');
        SecretStore::override('UC_CDN_SECRET', 'test-cdn-secret');
        $_COOKIE = [];
        service('superglobals')->setCookieArray([]);
    }

    /** @param list<string> $roles */
    protected function makeUser(array $roles = ['super_admin'], ?string $email = null, string $kind = 'STAFF'): array
    {
        return (new AuthService())->createUser($email ?? (implode('-', $roles) . '-' . bin2hex(random_bytes(3)) . '@livezen.lk'), 'correct horse battery', 'Test ' . implode(' ', $roles), $roles, $kind);
    }

    /**
     * Sign in as a user: returns headers to pass with withHeaders().
     *
     * @return array<string, string>
     */
    protected function actingAs(array $user): array
    {
        $s = (new AuthService())->createSession((int) $user['id'], '127.0.0.1', 'phpunit', false);
        db_connect()->table('user_sessions')->where('token_hash', hash('sha256', $s['token']))->update(['mfa_verified_at' => gmdate('Y-m-d H:i:s')]);
        $this->setCookie(AuthService::COOKIE, $s['token']);

        return ['X-CSRF-Token' => $s['csrf']];
    }

    /** @param list<string> $permissions */
    protected function makeClient(array $permissions, string $kind = 'CI'): array
    {
        $r = (new AuthService())->createApiClient('test-' . strtolower($kind), $kind, $permissions);

        return ['Authorization' => 'Bearer ' . $r['secret']];
    }

    protected function setCookie(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_COOKIE[$name]);
            service('superglobals')->unsetCookie($name);

            return;
        }
        $_COOKIE[$name] = $value;
        service('superglobals')->setCookie($name, $value);
    }

    protected function json(\CodeIgniter\Test\TestResponse $res): array
    {
        return json_decode((string) $res->response()->getBody(), true) ?? [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        service('superglobals')->setCookieArray([]);
        Factories::reset();
        parent::tearDown();
    }
}

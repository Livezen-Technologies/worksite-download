<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Jobs\StorageMigrationJob;
use App\Libraries\Base64Url;
use App\Libraries\Queue;
use App\Libraries\Rbac;
use App\Libraries\SecretStore;
use App\Libraries\Storage\StorageFactory;
use App\Libraries\Ulid;
use App\Services\AuthService;
use App\Services\Catalog;
use App\Services\StorageAdminService;

/**
 * Administration: users, roles, API clients, tenants, storage/CDN, signing
 * keys, audit log and settings (§7.1, FR-UC-AUTH/CI/STO/SEC/AUD).
 */
class AdministrationController extends BaseApiController
{
    public static function presentAudit(array $a): array
    {
        return [
            'id' => (int) $a['id'], 'actor_type' => $a['actor_type'], 'actor_id' => $a['actor_id'], 'actor_label' => $a['actor_label'],
            'action' => $a['action'], 'entity_type' => $a['entity_type'], 'entity_id' => $a['entity_id'],
            'before' => $a['before'] !== null ? json_decode($a['before'], true) : null, 'after' => $a['after'] !== null ? json_decode($a['after'], true) : null,
            'reason' => $a['reason'], 'ip' => $a['ip'], 'created_at' => Catalog::iso($a['created_at']), 'hash' => $a['hash'],
        ];
    }

    // ---- Users ---------------------------------------------------------

    private function presentUser(array $u): array
    {
        $rp = (new AuthService())->rolesAndPermissions((int) $u['id']);

        return ['id' => $u['public_id'], 'email' => $u['email'], 'name' => $u['name'], 'kind' => $u['kind'], 'status' => $u['status'], 'roles' => $rp['roles'], 'mfa_enabled' => (bool) $u['mfa_enabled'], 'last_login_at' => Catalog::iso($u['last_login_at']), 'created_at' => Catalog::iso($u['created_at'])];
    }

    private function user(string $id): array
    {
        return db_connect()->table('users')->where('public_id', $id)->get()->getRowArray() ?? throw ApiException::notFound('USER_NOT_FOUND', 'User not found');
    }

    public function users()
    {
        $q = db_connect()->table('users')->orderBy('kind')->orderBy('name');
        if ($k = $this->request->getGet('kind')) {
            $q->where('kind', strtoupper((string) $k));
        }

        return ['data' => array_map([$this, 'presentUser'], $q->get()->getResultArray())];
    }

    public function createUser()
    {
        $b     = $this->body();
        $roles = array_values((array) ($b['roles'] ?? []));
        $u     = (new AuthService())->createUser((string) ($b['email'] ?? ''), (string) ($b['password'] ?? bin2hex(random_bytes(12))), trim((string) ($b['name'] ?? '')) ?: 'New user', $roles, strtoupper((string) ($b['kind'] ?? 'STAFF')));
        service('audit')->record('user.created', 'user', $u['public_id'], null, ['email' => $u['email'], 'roles' => $roles]);

        return $this->ok($this->presentUser($u), 201);
    }

    public function updateUser(string $id)
    {
        $u   = $this->user($id);
        $b   = $this->body();
        $set = [];
        if (isset($b['name'])) {
            $set['name'] = trim((string) $b['name']);
        }
        if (isset($b['status'])) {
            $s = strtoupper((string) $b['status']);
            if (! in_array($s, ['ACTIVE', 'DISABLED'], true)) {
                throw ApiException::validation(['status' => 'ACTIVE or DISABLED']);
            }
            if ($u['id'] == $this->auth->userId() && $s === 'DISABLED') {
                throw ApiException::validation(['status' => 'You cannot disable yourself']);
            }
            $set['status'] = $s;
        }
        $before = $this->presentUser($u);
        if ($set) {
            db_connect()->table('users')->where('id', $u['id'])->update($set + ['updated_at' => Catalog::now()]);
            if (($set['status'] ?? '') === 'DISABLED') {
                db_connect()->table('user_sessions')->where('user_id', $u['id'])->update(['revoked_at' => Catalog::now()]);
            }
        }
        if (isset($b['roles'])) {
            (new AuthService())->setRoles((int) $u['id'], array_values((array) $b['roles']));
        }
        $after = $this->presentUser($this->user($id));
        service('audit')->record('user.updated', 'user', $u['public_id'], ['status' => $before['status'], 'roles' => $before['roles']], ['status' => $after['status'], 'roles' => $after['roles']]);

        return $after;
    }

    public function resetMfa(string $id)
    {
        $b = $this->body();
        $this->reason($b);
        $this->requireStepUp();
        (new AuthService())->resetMfa((int) $this->user($id)['id'], (string) $b['reason']);

        return ['ok' => true];
    }

    public function roles()
    {
        $perms = [];
        foreach (Rbac::MATRIX as $slug => [$label, $roles]) {
            $perms[] = ['slug' => $slug, 'name' => $label, 'roles' => $roles];
        }

        return ['roles' => array_map(static fn ($s, $n) => ['slug' => $s, 'name' => $n], array_keys(Rbac::ROLES), Rbac::ROLES), 'permissions' => $perms];
    }

    // ---- API clients & tenants -------------------------------------------

    private function presentClient(array $c): array
    {
        return ['id' => $c['public_id'], 'name' => $c['name'], 'kind' => $c['kind'], 'client_id' => $c['client_id'], 'permissions' => json_decode((string) $c['permissions'], true) ?: [], 'ip_allowlist' => json_decode((string) $c['ip_allowlist'], true) ?: [], 'tenant_id' => $c['tenant_id'] ? db_connect()->table('tenants')->select('public_id')->where('id', $c['tenant_id'])->get()->getRow('public_id') : null, 'expires_at' => Catalog::iso($c['expires_at']), 'last_used_at' => Catalog::iso($c['last_used_at']), 'rotated_at' => Catalog::iso($c['rotated_at']), 'revoked_at' => Catalog::iso($c['revoked_at']), 'created_at' => Catalog::iso($c['created_at'])];
    }

    private function client(string $id): array
    {
        return db_connect()->table('api_clients')->where('public_id', $id)->get()->getRowArray() ?? throw ApiException::notFound('CLIENT_NOT_FOUND', 'API client not found');
    }

    public function apiClients()
    {
        return ['data' => array_map([$this, 'presentClient'], db_connect()->table('api_clients')->orderBy('id', 'DESC')->get()->getResultArray())];
    }

    public function createApiClient()
    {
        $b    = $this->body();
        $kind = strtoupper((string) ($b['kind'] ?? 'CI'));
        if (! in_array($kind, ['CI', 'TENANT', 'INTEGRATION'], true)) {
            throw ApiException::validation(['kind' => 'CI, TENANT or INTEGRATION']);
        }
        $perms    = array_values((array) ($b['permissions'] ?? ($kind === 'CI' ? Rbac::CI_DEFAULT : ['release.view'])));
        $tenantId = null;
        if (! empty($b['tenant_id'])) {
            $tenantId = (int) (db_connect()->table('tenants')->select('id')->where('public_id', $b['tenant_id'])->get()->getRow('id') ?? throw ApiException::validation(['tenant_id' => 'Unknown tenant']));
        }
        $r = (new AuthService())->createApiClient(trim((string) ($b['name'] ?? '')) ?: 'API client', $kind, $perms, array_values((array) ($b['ip_allowlist'] ?? [])), Catalog::toDb($b['expires_at'] ?? null), $this->auth->userId(), $tenantId);

        return $this->ok(['client' => $this->presentClient($r['client']), 'secret' => $r['secret']], 201);
    }

    public function updateApiClient(string $id)
    {
        $c   = $this->client($id);
        $b   = $this->body();
        $set = [];
        if (isset($b['name'])) {
            $set['name'] = (string) $b['name'];
        }
        if (isset($b['permissions'])) {
            foreach ((array) $b['permissions'] as $p) {
                if (! isset(Rbac::MATRIX[$p])) {
                    throw ApiException::validation(['permissions' => "Unknown permission {$p}"]);
                }
            }
            $set['permissions'] = json_encode(array_values((array) $b['permissions']));
        }
        if (isset($b['ip_allowlist'])) {
            $set['ip_allowlist'] = json_encode(array_values((array) $b['ip_allowlist']));
        }
        if (array_key_exists('expires_at', $b)) {
            $set['expires_at'] = Catalog::toDb($b['expires_at']);
        }
        db_connect()->table('api_clients')->where('id', $c['id'])->update($set + ['updated_at' => Catalog::now()]);
        service('audit')->record('api_client.updated', 'api_client', $c['public_id'], array_intersect_key($c, $set), $set);

        return $this->presentClient($this->client($id));
    }

    public function rotateApiClient(string $id)
    {
        return ['secret' => (new AuthService())->rotateApiClient((int) $this->client($id)['id'])];
    }

    public function revokeApiClient(string $id)
    {
        $c = $this->client($id);
        db_connect()->table('api_clients')->where('id', $c['id'])->update(['revoked_at' => Catalog::now()]);
        service('audit')->record('api_client.revoked', 'api_client', $c['public_id']);

        return $this->presentClient($this->client($id));
    }

    public function tenants()
    {
        $db = db_connect();

        return ['data' => array_map(static fn ($t) => [
            'id' => $t['public_id'], 'tenant_ref' => $t['tenant_ref'], 'name' => $t['name'], 'license_installation_id' => $t['license_installation_id'], 'status' => $t['status'],
            'last_catalog_sync_at' => Catalog::iso($t['last_catalog_sync_at']),
            'webhooks' => array_map(static fn ($w) => ['id' => (int) $w['id'], 'url' => $w['url'], 'events' => json_decode((string) $w['events'], true), 'active' => (bool) $w['active'], 'last_success_at' => Catalog::iso($w['last_success_at']), 'last_failure_at' => Catalog::iso($w['last_failure_at']), 'failure_count' => (int) $w['failure_count']], $db->table('tenant_webhooks')->where('tenant_id', $t['id'])->get()->getResultArray()),
        ], $db->table('tenants')->orderBy('name')->get()->getResultArray())];
    }

    public function createTenant()
    {
        $b   = $this->body();
        $ref = trim((string) ($b['tenant_ref'] ?? ''));
        if (! preg_match('/^tnt_[A-Za-z0-9]{4,60}$/', $ref)) {
            throw ApiException::validation(['tenant_ref' => 'Tenant id as issued in TPTs, e.g. tnt_01J6…']);
        }
        $db = db_connect();
        if ($db->table('tenants')->where('tenant_ref', $ref)->countAllResults()) {
            throw ApiException::conflict('TENANT_EXISTS', 'Tenant already registered');
        }
        $db->table('tenants')->insert(['public_id' => Ulid::prefixed('ten'), 'tenant_ref' => $ref, 'name' => trim((string) ($b['name'] ?? $ref)), 'license_installation_id' => $b['license_installation_id'] ?? null, 'status' => 'ACTIVE', 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        service('audit')->record('tenant.created', 'tenant', $ref);

        return $this->ok($this->tenants(), 201);
    }

    public function addWebhook(string $id)
    {
        $t = db_connect()->table('tenants')->where('public_id', $id)->get()->getRowArray() ?? throw ApiException::notFound('TENANT_NOT_FOUND', 'Tenant not found');
        $b = $this->body();
        if (! preg_match('#^https://#', (string) ($b['url'] ?? '')) && config('UpdateCenter')->isProduction()) {
            throw ApiException::validation(['url' => 'HTTPS URL required']);
        }
        $secret = Base64Url::encode(random_bytes(32));
        db_connect()->table('tenant_webhooks')->insert(['tenant_id' => $t['id'], 'url' => (string) $b['url'], 'secret_ref' => SecretStore::seal($secret), 'events' => json_encode(array_values((array) ($b['events'] ?? ['*']))), 'active' => 1, 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        service('audit')->record('tenant.webhook_added', 'tenant', $t['tenant_ref'], null, ['url' => $b['url']]);

        return $this->ok(['webhook' => ['url' => $b['url'], 'events' => $b['events'] ?? ['*']], 'secret' => $secret], 201);
    }

    // ---- Storage (A5) ----------------------------------------------------

    public function storage()
    {
        return (new StorageAdminService())->overview();
    }

    public function addStorage()
    {
        $b    = $this->body();
        $kind = strtoupper((string) ($b['kind'] ?? ''));
        if (! in_array($kind, ['R2', 'S3', 'SPACES', 'MINIO', 'LOCAL'], true)) {
            throw ApiException::validation(['kind' => 'R2, S3, SPACES, MINIO or LOCAL']);
        }
        if (isset($b['access_key']) || isset($b['secret_key'])) {
            throw ApiException::validation(['credentials_ref' => 'Credentials are never accepted directly — store them in the secret service and pass credentials_ref']);
        }
        if (empty($b['bucket'])) {
            throw ApiException::validation(['bucket' => 'Required']);
        }
        db_connect()->table('storage_providers')->insert([
            'name' => (string) ($b['name'] ?? $kind), 'kind' => $kind, 'bucket' => (string) $b['bucket'], 'region' => $b['region'] ?? null, 'endpoint' => $b['endpoint'] ?? null,
            'cdn_host' => $b['cdn_host'] ?? null, 'credentials_ref' => $b['credentials_ref'] ?? null, 'is_active' => 0, 'versioning' => ! empty($b['versioning']) ? 1 : 0,
            'replication' => json_encode($b['replication'] ?? []), 'status' => 'UNKNOWN', 'created_at' => Catalog::now(), 'updated_at' => Catalog::now(),
        ]);
        service('audit')->record('storage.provider_added', 'storage', (string) db_connect()->insertID(), null, ['kind' => $kind, 'bucket' => $b['bucket']]);

        return $this->ok($this->storage(), 201);
    }

    public function testStorage(int $id)
    {
        $row = Catalog::storageById($id);
        try {
            $res = StorageFactory::fromRow($row)->testConnection();
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'detail' => $e->getMessage(), 'latency_ms' => 0];
        }
        db_connect()->table('storage_providers')->where('id', $id)->update(['status' => $res['ok'] ? 'OK' : 'DOWN', 'status_detail' => $res['detail'], 'last_check_at' => Catalog::now()]);

        return $res;
    }

    /** WF-C18: copy-and-verify migration, then switch (public URLs unchanged). */
    public function activateStorage(int $id)
    {
        $this->requireStepUp();
        $target = Catalog::storageById($id);
        $source = Catalog::activeStorage();
        if ((int) $source['id'] === $id) {
            throw new ApiException('ALREADY_ACTIVE', 'Provider is already active', 409);
        }
        $test = $this->testStorage($id);
        if (! $test['ok']) {
            throw new ApiException('STORAGE_UNREACHABLE', 'Test connection failed: ' . $test['detail'], 422);
        }
        db_connect()->table('storage_migrations')->insert(['from_provider_id' => $source['id'], 'to_provider_id' => $id, 'status' => 'PENDING', 'started_by' => $this->auth->userId(), 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        $mid = (int) db_connect()->insertID();
        service('audit')->record('storage.migration_started', 'storage', (string) $id, ['active' => $source['name']], ['target' => $target['name']]);
        Queue::push(StorageMigrationJob::class, ['migration_id' => $mid], 'default', 0, 1);

        return $this->storage();
    }

    public function probeStorage()
    {
        return (new StorageAdminService())->probe();
    }

    // ---- Signing keys ----------------------------------------------------

    public function signingKeys()
    {
        \App\Services\KeyRegistry::registerDevKeys();

        return ['data' => array_map(static fn ($k) => ['key_id' => $k['key_id'], 'purpose' => $k['purpose'], 'algorithm' => $k['algorithm'], 'public_key' => $k['public_key'], 'kms_ref' => $k['kms_ref'] ? preg_replace('/[^\/:]+$/', '…', (string) $k['kms_ref']) : null, 'status' => $k['status'], 'created_at' => Catalog::iso($k['created_at']), 'rotated_at' => Catalog::iso($k['rotated_at']), 'retire_after' => Catalog::iso($k['retire_after'])],
            db_connect()->table('signing_keys')->orderBy('purpose')->orderBy('id', 'DESC')->get()->getResultArray())];
    }

    /**
     * WF-C19: rotation with a second approver. The new key's public half comes
     * from KMS (kms_ref) — private material never reaches this service.
     */
    public function rotateKey()
    {
        $b = $this->body();
        $this->reason($b);
        $this->requireStepUp();
        $purpose = strtoupper((string) ($b['purpose'] ?? 'RELEASE'));
        $newId   = (string) ($b['new_key_id'] ?? '');
        $pub     = Base64Url::decode((string) ($b['public_key'] ?? ''));
        if (! preg_match('/^[A-Za-z0-9._-]{3,64}$/', $newId) || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw ApiException::validation(['new_key_id' => 'new_key_id and a 32-byte base64url Ed25519 public_key from KMS are required']);
        }
        // Second approver: another holder of signing_key.rotate confirms with their MFA code.
        $approver = db_connect()->table('users')->where('email', strtolower((string) ($b['approver_email'] ?? '')))->get()->getRowArray();
        if (! $approver || (int) $approver['id'] === $this->auth->userId()) {
            throw new ApiException('SOD_VIOLATION', 'A second approver (different user) is required', 403);
        }
        $rp = (new AuthService())->rolesAndPermissions((int) $approver['id']);
        if (! in_array('signing_key.rotate', $rp['permissions'], true) || ! (new AuthService())->verifySecondFactor($approver, (string) ($b['approver_code'] ?? ''))) {
            throw new ApiException('SOD_VIOLATION', 'Second approver could not be verified', 403);
        }
        $db  = db_connect();
        $old = $db->table('signing_keys')->where('purpose', $purpose)->where('status', 'ACTIVE')->orderBy('id', 'DESC')->get()->getRowArray();
        $overlapDays = max(7, (int) ($b['overlap_days'] ?? 90));
        $doc = ['type' => 'key-rotation', 'purpose' => strtolower($purpose), 'old_key_id' => $old['key_id'] ?? null, 'new_key_id' => $newId, 'new_public_key' => Base64Url::encode($pub), 'overlap_until' => gmdate('Y-m-d\TH:i:s\Z', time() + $overlapDays * 86400), 'issued_at' => gmdate('Y-m-d\TH:i:s\Z')];
        if ($purpose === 'RELEASE') {
            $doc = service('signingClient')->sign('ROTATION', $doc, []); // signed by the old (current) key
        }
        $db->transStart();
        if ($old) {
            $db->table('signing_keys')->where('id', $old['id'])->update(['status' => 'OVERLAP', 'rotated_at' => Catalog::now(), 'retire_after' => gmdate('Y-m-d H:i:s', time() + $overlapDays * 86400)]);
        }
        $db->table('signing_keys')->insert(['key_id' => $newId, 'purpose' => $purpose, 'algorithm' => 'EdDSA', 'kms_ref' => $b['kms_ref'] ?? null, 'public_key' => Base64Url::encode($pub), 'status' => 'ACTIVE', 'rotation_doc' => json_encode($doc), 'approved_by' => $approver['id'], 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        $db->transComplete();
        service('audit')->record('signing_key.rotated', 'signing_key', $newId, ['key_id' => $old['key_id'] ?? null], ['key_id' => $newId, 'purpose' => $purpose, 'second_approver' => $approver['email']], (string) $b['reason']);

        return ['rotation_document' => $doc] + $this->signingKeys();
    }

    // ---- Audit -----------------------------------------------------------

    public function audit()
    {
        [$limit, $offset] = $this->paging(100);
        $g = $this->request->getGet();
        $q = static function () use ($g) {
            $b = db_connect()->table('audit_logs');
            foreach (['action', 'entity_type', 'entity_id'] as $f) {
                if (! empty($g[$f])) {
                    $f === 'action' && str_ends_with((string) $g[$f], '*') ? $b->like('action', rtrim((string) $g[$f], '*'), 'after') : $b->where($f, (string) $g[$f]);
                }
            }
            if (! empty($g['actor'])) {
                $b->groupStart()->like('actor_label', (string) $g['actor'])->orWhere('actor_id', (string) $g['actor'])->groupEnd();
            }
            if (! empty($g['from'])) {
                $b->where('created_at >=', Catalog::toDb((string) $g['from']));
            }
            if (! empty($g['to'])) {
                $b->where('created_at <=', Catalog::toDb((string) $g['to']));
            }

            return $b;
        };
        if (($g['format'] ?? '') === 'csv') {
            $csv = "id,created_at,actor_type,actor,action,entity_type,entity_id,reason,ip,hash\n";
            foreach ($q()->orderBy('id')->limit(100000)->get()->getResultArray() as $a) {
                $csv .= implode(',', array_map(static fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', [$a['id'], $a['created_at'], $a['actor_type'], $a['actor_label'], $a['action'], $a['entity_type'], $a['entity_id'], $a['reason'], $a['ip'], $a['hash']])) . "\n";
            }

            return $this->response->setContentType('text/csv')->setHeader('Content-Disposition', 'attachment; filename="audit.csv"')->setBody($csv);
        }

        return ['data' => array_map([self::class, 'presentAudit'], $q()->orderBy('id', 'DESC')->limit($limit, $offset)->get()->getResultArray()), 'total' => $q()->countAllResults(), 'limit' => $limit, 'offset' => $offset];
    }

    public function verifyAudit()
    {
        return service('audit')->verifyChain();
    }

    // ---- Settings --------------------------------------------------------

    public function settings()
    {
        $cfg = config('UpdateCenter');

        return [
            'environment'            => $cfg->environmentName,
            'cdn_url_lifetime_s'     => $cfg->downloadUrlTtl,
            'update_response_ttl_s'  => $cfg->updateResponseTtl,
            'session_idle_s'         => $cfg->sessionIdleSeconds,
            'session_absolute_s'     => $cfg->sessionAbsoluteSeconds,
            'step_up_s'              => $cfg->stepUpSeconds,
            'require_staff_mfa'      => $cfg->requireStaffMfa || $cfg->isProduction(),
            'require_attestation'    => $cfg->requireAttestation || $cfg->isProduction(),
            'scanner'                => $cfg->scanner,
            'signature_verifier'     => $cfg->signatureVerifier,
            'signing_driver'         => $cfg->isProduction() ? 'remote' : $cfg->signingDriver,
            'notify_chat_webhook_set' => (bool) env('updatecenter.chatWebhookUrl'),
            'smtp_configured'        => (bool) env('email.SMTPHost'),
        ];
    }

    public function saveSettings()
    {
        throw new ApiException('SETTINGS_READ_ONLY', 'Runtime settings are managed through environment configuration and the secret service', 409);
    }
}

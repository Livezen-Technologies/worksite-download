<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Identity, RBAC, sessions, API clients, audit, notifications (§11, §7.5, §8.1).
 */
class CreateIdentityTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('users', [
            'public_id'          => $this->str(40),
            'email'              => $this->str(191),
            'password_hash'      => $this->str(255),
            'name'               => $this->str(191),
            'kind'               => $this->str(16, false, 'STAFF'), // STAFF | PORTAL
            'status'             => $this->str(16, false, 'ACTIVE'), // ACTIVE | DISABLED | PENDING
            'mfa_totp_secret'    => $this->text(), // encrypted
            'mfa_enabled'        => $this->bool(),
            'recovery_codes'     => $this->text(), // JSON list of sha256 hashes
            'email_verified_at'  => $this->dt(),
            'email_verify_token' => $this->str(64, true),
            'beta_opt_in'        => $this->bool(),
            'failed_logins'      => $this->int(),
            'locked_until'       => $this->dt(),
            'last_login_at'      => $this->dt(),
        ], ['status'], ['email', 'public_id']);

        $this->table('webauthn_credentials', [
            'user_id'       => $this->fk(),
            'credential_id' => $this->str(512),
            'public_key'    => $this->text(false),
            'sign_count'    => $this->bigint(),
            'name'          => $this->str(100),
            'last_used_at'  => $this->dt(),
        ], ['user_id']);

        $this->table('roles', [
            'slug' => $this->str(64),
            'name' => $this->str(100),
        ], [], ['slug']);

        $this->table('permissions', [
            'slug' => $this->str(64),
            'name' => $this->str(150),
        ], [], ['slug']);

        $this->table('role_permissions', [
            'role_id'       => $this->fk(),
            'permission_id' => $this->fk(),
        ], [], [['role_id', 'permission_id']], false);

        $this->table('user_roles', [
            'user_id' => $this->fk(),
            'role_id' => $this->fk(),
        ], [], [['user_id', 'role_id']], false);

        $this->table('user_sessions', [
            'token_hash'      => $this->str(64),
            'user_id'         => $this->fk(),
            'csrf_token'      => $this->str(64),
            'ip'              => $this->str(64, true),
            'user_agent'      => $this->str(255, true),
            'mfa_pending'     => $this->bool(), // password ok, awaiting 2nd factor
            'mfa_verified_at' => $this->dt(),
            'last_seen_at'    => $this->dt(),
            'expires_at'      => $this->dt(),
            'revoked_at'      => $this->dt(),
        ], ['user_id'], ['token_hash']);

        $this->table('password_resets', [
            'user_id'    => $this->fk(),
            'token_hash' => $this->str(64),
            'expires_at' => $this->dt(),
            'used_at'    => $this->dt(),
        ], ['user_id'], ['token_hash']);

        $this->table('api_clients', [
            'public_id'    => $this->str(40),
            'name'         => $this->str(150),
            'kind'         => $this->str(16), // CI | TENANT | INTEGRATION
            'client_id'    => $this->str(64),
            'secret_hash'  => $this->str(255, true),
            'oidc_subject' => $this->str(255, true),
            'permissions'  => $this->text(), // JSON
            'ip_allowlist' => $this->text(), // JSON list of CIDRs
            'tenant_id'    => $this->fk(true),
            'expires_at'   => $this->dt(),
            'rotated_at'   => $this->dt(),
            'revoked_at'   => $this->dt(),
            'last_used_at' => $this->dt(),
            'created_by'   => $this->fk(true),
        ], ['kind'], ['client_id', 'public_id']);

        $this->table('audit_logs', [
            'actor_type'  => $this->str(16), // USER | API_CLIENT | SYSTEM | TENANT
            'actor_id'    => $this->str(64, true),
            'actor_label' => $this->str(191, true),
            'action'      => $this->str(100),
            'entity_type' => $this->str(64, true),
            'entity_id'   => $this->str(64, true),
            'before'      => $this->longtext(),
            'after'       => $this->longtext(),
            'reason'      => $this->text(),
            'ip'          => $this->str(64, true),
            'user_agent'  => $this->str(255, true),
            'prev_hash'   => $this->str(64, true),
            'hash'        => $this->str(64),
            'created_at'  => $this->dt(),
        ], ['action', ['entity_type', 'entity_id'], 'created_at'], [], false);

        $this->table('notifications', [
            'user_id' => $this->fk(),
            'kind'    => $this->str(64),
            'payload' => $this->text(),
            'read_at' => $this->dt(),
        ], [['user_id', 'read_at']]);

        $this->table('settings', [
            'key'   => $this->str(100),
            'value' => $this->text(),
        ], [], ['key']);

        $this->table('jobs', [
            'queue'        => $this->str(64, false, 'default'),
            'job'          => $this->str(150),
            'payload'      => $this->longtext(),
            'attempts'     => $this->int(),
            'max_attempts' => $this->int(false, 5),
            'available_at' => $this->dt(false),
            'reserved_at'  => $this->dt(),
            'reserved_by'  => $this->str(64, true),
            'failed_at'    => $this->dt(),
            'last_error'   => $this->text(),
            'done_at'      => $this->dt(),
        ], [['queue', 'available_at'], 'done_at']);

        $this->table('rate_limit_hits', [
            'bucket' => $this->str(191),
            'at'     => $this->bigint(), // unix ms
        ], [['bucket', 'at']], [], false);
    }

    public function down(): void
    {
        foreach (['rate_limit_hits', 'jobs', 'settings', 'notifications', 'audit_logs', 'api_clients', 'password_resets', 'user_sessions', 'user_roles', 'role_permissions', 'permissions', 'roles', 'webauthn_credentials', 'users'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}

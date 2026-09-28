<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Analytics, telemetry, tenants, webhooks, storage, signing keys,
 * subscriptions, health (§11, §8.15–8.20).
 */
class CreateDistributionTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('download_events', [
            'application_id'    => $this->fk(),
            'release_id'        => $this->fk(true),
            'package_id'        => $this->fk(true),
            'revision_id'       => $this->fk(true),
            'channel'           => $this->str(32, true),
            'version'           => $this->str(64, true),
            'platform'          => $this->str(16, true),
            'architecture'      => $this->str(16, true),
            'package_type'      => $this->str(16, true),
            'country_code'      => $this->str(2, true),
            'referrer_host'     => $this->str(191, true),
            'user_agent_family' => $this->str(64, true),
            'source'            => $this->str(16, false, 'web'), // web | desktop | server | ci
            'is_bot'            => $this->bool(),
            'redirect_ok'       => $this->bool(true),
            'error_code'        => $this->str(40, true),
            'created_at'        => $this->dt(false),
        ], [['application_id', 'created_at'], ['release_id', 'created_at']], [], false);

        $this->table('download_daily_stats', [
            'day'            => ['type' => 'DATE', 'null' => false],
            'application_id' => $this->fk(),
            'release_id'     => $this->fk(true),
            'platform'       => $this->str(16, false, ''),
            'architecture'   => $this->str(16, false, ''),
            'package_type'   => $this->str(16, false, ''),
            'country_code'   => $this->str(2, false, ''),
            'downloads'      => $this->bigint(),
            'failed'         => $this->bigint(),
            'bots'           => $this->bigint(),
        ], ['day'], [['day', 'application_id', 'release_id', 'platform', 'architecture', 'package_type', 'country_code']], false);

        $this->table('device_versions', [
            'device_ref'     => $this->str(128),
            'tenant_id'      => $this->str(64, true),
            'application_id' => $this->fk(),
            'version'        => $this->str(64),
            'build'          => $this->bigint(),
            'platform'       => $this->str(16),
            'architecture'   => $this->str(16),
            'channel'        => $this->str(32, true),
            'country_code'   => $this->str(2, true),
            'first_seen'     => $this->dt(),
            'last_seen'      => $this->dt(),
        ], [['application_id', 'last_seen']], [['device_ref', 'application_id']], false);

        $this->table('update_events', [
            'event_id'       => $this->str(64),
            'device_ref'     => $this->str(128),
            'tenant_id'      => $this->str(64, true),
            'application_id' => $this->fk(),
            'release_id'     => $this->fk(true),
            'type'           => $this->str(32),
            'from_version'   => $this->str(64, true),
            'to_version'     => $this->str(64, true),
            'platform'       => $this->str(16, true),
            'architecture'   => $this->str(16, true),
            'channel'        => $this->str(32, true),
            'error_code'     => $this->str(64, true),
            'occurred_at'    => $this->dt(),
            'received_at'    => $this->dt(),
        ], [['application_id', 'occurred_at'], ['release_id', 'type']], ['event_id'], false);

        $this->table('update_stats_hourly', [
            'hour'         => $this->dt(false),
            'release_id'   => $this->fk(),
            'platform'     => $this->str(16, false, ''),
            'type'         => $this->str(32),
            'error_code'   => $this->str(64, false, ''),
            'count'        => $this->bigint(),
        ], ['release_id'], [['hour', 'release_id', 'platform', 'type', 'error_code']], false);

        $this->table('tenants', [
            'public_id'               => $this->str(40),
            'tenant_ref'              => $this->str(64), // tnt_… as issued in TPT
            'name'                    => $this->str(191),
            'license_installation_id' => $this->str(64, true),
            'catalog_client_id'       => $this->fk(true),
            'status'                  => $this->str(16, false, 'ACTIVE'),
            'last_catalog_sync_at'    => $this->dt(),
        ], [], ['public_id', 'tenant_ref']);

        $this->table('tenant_webhooks', [
            'tenant_id'       => $this->fk(),
            'url'             => $this->str(512),
            'secret_ref'      => $this->str(255), // secret service reference (encrypted value in dev)
            'events'          => $this->text(),
            'active'          => $this->bool(true),
            'last_success_at' => $this->dt(),
            'last_failure_at' => $this->dt(),
            'failure_count'   => $this->int(),
        ], ['tenant_id']);

        $this->table('webhook_deliveries', [
            'webhook_id'   => $this->fk(true),
            'target_url'   => $this->str(512),
            'event'        => $this->str(64),
            'payload'      => $this->longtext(false),
            'status'       => $this->str(16, false, 'PENDING'), // PENDING | DELIVERED | FAILED
            'attempts'     => $this->int(),
            'last_status'  => $this->int(true, null),
            'last_error'   => $this->text(),
            'next_attempt_at' => $this->dt(),
            'delivered_at' => $this->dt(),
        ], ['status', 'webhook_id']);

        $this->table('catalog_changes', [
            'seq_kind'       => $this->str(40), // release.published | release.paused | …
            'application_id' => $this->fk(),
            'release_id'     => $this->fk(true),
            'channel_id'     => $this->fk(true),
            'payload'        => $this->longtext(),
        ], ['application_id'], [], true);

        $this->table('storage_providers', [
            'name'            => $this->str(100),
            'kind'            => $this->str(16), // R2 | S3 | SPACES | MINIO | LOCAL
            'bucket'          => $this->str(191),
            'region'          => $this->str(64, true),
            'endpoint'        => $this->str(255, true),
            'cdn_host'        => $this->str(255, true),
            'credentials_ref' => $this->str(255, true),
            'is_active'       => $this->bool(),
            'versioning'      => $this->bool(),
            'replication'     => $this->text(),
            'last_check_at'   => $this->dt(),
            'status'          => $this->str(16, false, 'UNKNOWN'),
            'status_detail'   => $this->text(),
            'usage_bytes'     => $this->bigint(),
            'object_count'    => $this->bigint(),
            'credentials_rotated_at' => $this->dt(),
        ]);

        $this->table('storage_migrations', [
            'from_provider_id' => $this->fk(),
            'to_provider_id'   => $this->fk(),
            'status'           => $this->str(16, false, 'PENDING'), // PENDING | RUNNING | VERIFIED | FAILED | SWITCHED
            'total'            => $this->int(),
            'copied'           => $this->int(),
            'mismatches'       => $this->text(),
            'started_by'       => $this->fk(true),
        ]);

        $this->table('signing_keys', [
            'key_id'      => $this->str(64),
            'purpose'     => $this->str(16), // RELEASE | RESPONSE | CI_ATTESTATION | TENANT_ROOT
            'algorithm'   => $this->str(16, false, 'EdDSA'),
            'kms_ref'     => $this->str(255, true),
            'public_key'  => $this->text(false),
            'status'      => $this->str(16, false, 'ACTIVE'), // ACTIVE | OVERLAP | RETIRED | REVOKED
            'rotated_at'  => $this->dt(),
            'retire_after' => $this->dt(),
            'rotation_doc' => $this->longtext(),
            'approved_by' => $this->fk(true),
        ], ['purpose'], ['key_id']);

        $this->table('subscriptions', [
            'email'             => $this->str(191),
            'application_id'    => $this->fk(),
            'channel'           => $this->str(32, false, 'STABLE'),
            'confirm_token'     => $this->str(64, true),
            'confirmed_at'      => $this->dt(),
            'unsubscribe_token' => $this->str(64),
        ], [], [['email', 'application_id', 'channel'], 'unsubscribe_token']);

        $this->table('health_checks', [
            'component'  => $this->str(32),
            'status'     => $this->str(16), // OK | DEGRADED | DOWN
            'latency_ms' => $this->int(true, null),
            'detail'     => $this->text(),
            'checked_at' => $this->dt(),
        ], [], ['component'], false);
    }

    public function down(): void
    {
        foreach (['health_checks', 'subscriptions', 'signing_keys', 'storage_migrations', 'storage_providers', 'catalog_changes', 'webhook_deliveries', 'tenant_webhooks', 'tenants', 'update_stats_hourly', 'update_events', 'device_versions', 'download_daily_stats', 'download_events'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}

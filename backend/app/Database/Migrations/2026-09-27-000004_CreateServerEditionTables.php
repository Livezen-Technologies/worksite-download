<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * WorkSuite Web (Server) editions, installations and notification campaigns (§17.11).
 */
class CreateServerEditionTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('editions', [
            'code'            => $this->str(16), // FREE | BUSINESS | ENTERPRISE | CUSTOM
            'name'            => $this->str(64),
            'rank'            => $this->int(),
            'public_download' => $this->bool(),
            'description'     => $this->text(),
        ], [], ['code']);

        $this->table('edition_modules', [
            'edition_code'  => $this->str(16),
            'module_slug'   => $this->str(64),
            'since_version' => $this->str(64, true),
        ], [], [['edition_code', 'module_slug']]);

        $this->table('release_server_meta', [
            'release_id'         => $this->fk(),
            'min_from_version'   => $this->str(64, true),
            'requires'           => $this->text(),
            'migrations'         => $this->text(),
            'desktop_compat'     => $this->str(64, true),
            'downtime_estimate_s' => $this->int(true, null),
            'staging_test'       => $this->text(), // JSON {status, run_url, editions:{...}}
            'admin_notice'       => $this->text(),
            'advisory'           => $this->text(),
        ], [], ['release_id']);

        $this->table('server_installations', [
            'installation_id'  => $this->str(64),
            'tenant_id'        => $this->fk(true),
            'name'             => $this->str(191, true),
            'edition'          => $this->str(16),
            'version'          => $this->str(64, true),
            'build'            => $this->bigint(),
            'channel'          => $this->str(32, false, 'STABLE'),
            'mode'             => $this->str(16, false, 'SELF_HOSTED'), // MANAGED | SELF_HOSTED | AIR_GAPPED
            'php_version'      => $this->str(32, true),
            'db_version'       => $this->str(64, true),
            'notify_contact'   => $this->str(191, true),
            'webhook_url'      => $this->str(512, true),
            'maintenance_window' => $this->str(64, true),
            'support_state'    => $this->str(16, false, 'SUPPORTED'),
            'advisory_ack_at'  => $this->dt(),
            'last_report_at'   => $this->dt(),
            'last_check_at'    => $this->dt(),
            'last_run_status'  => $this->str(20, true),
        ], ['edition', 'mode'], ['installation_id']);

        $this->table('notification_campaigns', [
            'release_id'       => $this->fk(),
            'audience'         => $this->text(), // JSON {editions, channels, version_range, modes}
            'urgency'          => $this->str(16, false, 'RECOMMENDED'), // OPTIONAL | RECOMMENDED | MANDATORY | CRITICAL
            'deadline'         => $this->dt(),
            'rollout_pct'      => $this->int(false, 100),
            'channels'         => $this->text(), // JSON [webhook, email, in_app, whatsapp]
            'fleet_window'     => $this->str(64, true),
            'fleet_stages'     => $this->text(),
            'fleet_stage_no'   => $this->int(),
            'fleet_status'     => $this->str(16, false, 'NONE'), // NONE | ACTIVE | PAUSED | DONE
            'status'           => $this->str(16, false, 'DRAFT'), // DRAFT | SCHEDULED | SENDING | SENT | CANCELLED
            'sent_count'       => $this->int(),
            'last_reminder_at' => $this->dt(),
            'reminders_sent'   => $this->int(),
            'created_by'       => $this->fk(true),
        ], ['release_id']);

        $this->table('campaign_deliveries', [
            'campaign_id'     => $this->fk(),
            'installation_id' => $this->fk(),
            'channel'         => $this->str(16),
            'status'          => $this->str(16, false, 'PENDING'),
            'sent_at'         => $this->dt(),
        ], [['campaign_id', 'installation_id']]);

        $this->table('server_update_events', [
            'event_id'        => $this->str(64),
            'installation_id' => $this->fk(),
            'release_id'      => $this->fk(true),
            'type'            => $this->str(32), // started | completed | failed | rolled_back
            'step'            => $this->str(32, true),
            'error_code'      => $this->str(64, true),
            'from_version'    => $this->str(64, true),
            'to_version'      => $this->str(64, true),
            'occurred_at'     => $this->dt(),
        ], ['installation_id', 'release_id'], ['event_id'], false);
    }

    public function down(): void
    {
        foreach (['server_update_events', 'campaign_deliveries', 'notification_campaigns', 'server_installations', 'release_server_meta', 'edition_modules', 'editions'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}

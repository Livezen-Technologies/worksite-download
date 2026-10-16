<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use App\Database\ServerChannels;
use CodeIgniter\Database\Migration;

/**
 * WorkSuite Core System Update Service — portal side (WS-CORE-SYSUPDATE-SRS-001 §4, §10, §11):
 *
 *  - server_auto_update_policies: mirror of each installation's automatic-update policy
 *    (versioned, If-Match), with portal locks and the organisation owner.
 *  - server_update_plans: per installation × release ring, earliest / latest time, the
 *    signed plan and its state (planned, postponed, skipped, held, done, cancelled).
 *
 * Also adds the installation key and heartbeat fields to server_installations, the
 * server release fields (severity, commit, upgrade notes, reversibility, selected
 * editions) to release_server_meta, ring state to release_rollouts, and the LTS
 * channel for lk.worksuite.server.
 */
class CreateServerAutoUpdateTables extends Migration
{
    use SchemaHelper;

    private const INSTALLATION_COLUMNS = ['key_hash', 'key_issued_at', 'registered_at', 'ring', 'policy_mode', 'health', 'node_version', 'disk_free_mb', 'offline_mode', 'admin_contacts'];

    private const META_COLUMNS = ['severity', 'commit_sha', 'upgrade_notes', 'migrations_reversible', 'editions'];

    public function up(): void
    {
        $this->table('server_auto_update_policies', [
            'installation_id'          => $this->fk(), // server_installations.id
            'version'                  => $this->int(false, 1), // If-Match / ETag
            'mode'                     => $this->str(16, false, 'NOTIFY'), // OFF | NOTIFY | AUTO_SECURITY | AUTO_MINOR | AUTO_ALL
            'windows'                  => $this->text(), // JSON [{days:[mon..sun], start:"HH:MM", length_minutes}]
            'blackouts'                => $this->text(), // JSON [{name, from:"YYYY-MM-DD", to:"YYYY-MM-DD"}]
            'timezone'                 => $this->str(64, false, 'UTC'),
            'ring'                     => $this->str(16, false, 'BROAD'), // EARLY | BROAD | CONSERVATIVE
            'backup_before'            => $this->bool(true),
            'auto_rollback'            => $this->bool(true),
            'max_active_users'         => $this->int(false, 25),
            'allow_one_way_migrations' => $this->bool(),
            'web_idle_minutes'         => $this->int(false, 10),
            'web_forced_hours'         => $this->int(false, 24),
            'web_toast_non_admin'      => $this->bool(true),
            'locks'                    => $this->text(), // JSON {field: {value, by, at}} — portal / organisation locks
            'org_owner'                => $this->str(191, true),
            'updated_by'               => $this->str(191, true), // tenant-side actor label (no user data)
            'synced_at'                => $this->dt(),
        ], ['mode'], ['installation_id']);

        $this->table('server_update_plans', [
            'public_id'          => $this->str(40),
            'installation_id'    => $this->fk(), // server_installations.id
            'release_id'         => $this->fk(),
            'ring'               => $this->int(false, 2),
            'earliest_at'        => $this->dt(),
            'latest_at'          => $this->dt(),
            'auto_install'       => $this->bool(),
            'state'              => $this->str(16, false, 'PLANNED'), // PLANNED | POSTPONED | SKIPPED | HELD | DONE | CANCELLED
            'state_reason'       => $this->str(255, true),
            'rollback_requested' => $this->bool(),
            'plan_json'          => $this->longtext(), // last signed plan document
            'plan_sha256'        => $this->str(64, true),
            'issued_at'          => $this->dt(),
            'expires_at'         => $this->dt(),
            'cancelled_at'       => $this->dt(),
        ], ['release_id', 'state'], ['public_id', ['installation_id', 'release_id']]);

        $this->forge->addColumn('server_installations', [
            'key_hash'       => $this->str(64, true), // SHA-256 of the installation key; the key itself is never stored
            'key_issued_at'  => $this->dt(),
            'registered_at'  => $this->dt(),
            'ring'           => $this->int(true, null), // admin override; NULL = from policy / hosting mode
            'policy_mode'    => $this->str(16, true),
            'health'         => $this->str(16, true), // OK | DEGRADED | DOWN (heartbeat)
            'node_version'   => $this->str(32, true),
            'disk_free_mb'   => $this->bigint(true, null),
            'offline_mode'   => $this->bool(),
            'admin_contacts' => $this->text(), // JSON list of e-mail addresses registered for update notices
        ]);
        $this->db->query('CREATE INDEX idx_srv_inst_key_hash ON ' . $this->db->prefixTable('server_installations') . ' (key_hash)');

        $this->forge->addColumn('release_server_meta', [
            'severity'              => $this->str(16, true), // PATCH | MINOR | MAJOR | SECURITY | CRITICAL
            'commit_sha'            => $this->str(64, true),
            'upgrade_notes'         => $this->text(),
            'migrations_reversible' => $this->int(true, null), // NULL = not stated, 0 = one-way migrations, 1 = all reversible
            'editions'              => $this->text(), // JSON list of selected editions
        ]);

        $this->forge->addColumn('release_rollouts', [
            'rings' => $this->text(), // JSON ring state for server releases (mode RINGS)
        ]);

        ServerChannels::ensureLts($this->db);
    }

    public function down(): void
    {
        $app = $this->db->table('applications')->where('slug', 'server')->get()->getRowArray();
        if ($app) {
            $lts = $this->db->table('release_channels')->where('code', 'LTS')->where('application_id', $app['id'])->get()->getRowArray();
            if ($lts) {
                $this->db->table('application_channels')->where('channel_id', $lts['id'])->delete();
                $this->db->table('release_channels')->where('id', $lts['id'])->delete();
            }
        }
        $this->db->query('DROP INDEX idx_srv_inst_key_hash' . ($this->db->DBDriver === 'SQLite3' ? '' : ' ON ' . $this->db->prefixTable('server_installations')));
        $this->forge->dropColumn('release_rollouts', 'rings');
        $this->forge->dropColumn('release_server_meta', self::META_COLUMNS);
        $this->forge->dropColumn('server_installations', self::INSTALLATION_COLUMNS);
        $this->forge->dropTable('server_update_plans', true);
        $this->forge->dropTable('server_auto_update_policies', true);
    }
}

<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Bug Trace additions (§18): counters for human ids/references, fingerprint and
 * symbolication state on events, source-map artifact names, alert delivery
 * state, reporter "fixed in" tracking and default alert rules.
 */
class BugTraceAdditions extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        // Named monotonic counters: "issue:<project_id>", "report".
        $this->table('bt_counters', [
            'name'  => $this->str(64),
            'value' => $this->bigint(),
        ], [], ['name'], false);

        $this->forge->addColumn('bt_events', [
            'fingerprint'   => $this->str(64, true),
            'symbolication' => $this->str(16, true), // ok | partial | missing | pending | n/a
        ]);
        $this->forge->addColumn('bt_issues', [
            'resolved_last_release' => $this->str(80, true), // newest release seen when resolved
            'resolved_by'           => $this->fk(true),
        ]);
        $this->forge->addColumn('bt_symbols', [
            'artifact' => $this->str(255, true), // minified file the map belongs to, e.g. assets/mail-8c1.js
            'sha256'   => $this->str(64, true),
        ]);
        $this->forge->addColumn('bt_alert_rules', [
            'public_id'          => $this->str(40, true),
            'webhook_secret_ref' => $this->str(255, true),
            'last_fired_at'      => $this->dt(),
        ]);
        $this->forge->addColumn('bt_alert_events', [
            'project_id'   => $this->fk(true),
            'delivered_at' => $this->dt(),
            'dedupe_key'   => $this->str(191, true),
        ]);
        $this->forge->addColumn('bt_reports', [
            'app'               => $this->str(80, true),
            'fixed_in'          => $this->str(64, true),
            'fixed_release_id'  => $this->fk(true),
            'notified_fixed_at' => $this->dt(),
        ]);
        $this->forge->addColumn('bt_session_users', [
            'release_id' => $this->fk(true),
        ]);

        $now = gmdate('Y-m-d H:i:s');
        $db  = $this->db;
        foreach ([
            ['alr_default_new_crash', 'New crash issue', 'new_issue', ['types' => ['crash', 'hang']], ['in_app']],
            ['alr_default_regression', 'Regression', 'regression', [], ['in_app', 'email']],
            ['alr_default_spike', 'Spike (5x baseline)', 'spike', ['multiplier' => 5, 'min_events' => 20], ['in_app']],
        ] as [$pid, $name, $cond, $threshold, $channels]) {
            $db->table('bt_alert_rules')->insert([
                'public_id' => $pid, 'name' => $name, 'project_id' => null, 'condition' => $cond,
                'threshold' => json_encode($threshold === [] ? new \stdClass() : $threshold), 'channels' => json_encode($channels),
                'webhook_url' => null, 'quiet_hours' => null, 'digest' => 0, 'enabled' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('bt_counters', true);
        $this->forge->dropColumn('bt_events', ['fingerprint', 'symbolication']);
        $this->forge->dropColumn('bt_issues', ['resolved_last_release', 'resolved_by']);
        $this->forge->dropColumn('bt_symbols', ['artifact', 'sha256']);
        $this->forge->dropColumn('bt_alert_rules', ['public_id', 'webhook_secret_ref', 'last_fired_at']);
        $this->forge->dropColumn('bt_alert_events', ['project_id', 'delivered_at', 'dedupe_key']);
        $this->forge->dropColumn('bt_reports', ['app', 'fixed_in', 'fixed_release_id', 'notified_fixed_at']);
        $this->forge->dropColumn('bt_session_users', ['release_id']);
    }
}

<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * IMPL-LIC-01 P6: what a server says about its own code (integrity), and the risk engine's
 * findings per licence and installation, for staff to review. Customer-facing text stays generic.
 */
class LicenseRisk extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        foreach (['integrity_status' => $this->str(16, true), 'integrity_at' => $this->dt()] as $c => $def) {
            if (! $this->db->fieldExists($c, 'license_activations')) {
                $this->forge->addColumn('license_activations', [$c => $def]);
            }
        }
        $this->table('license_risk_flags', [
            'license_id'      => $this->fk(),
            'installation_id' => $this->str(64, true),
            'rule'            => $this->str(48),  // integrity_compromised | clone_suspected | activation_churn | deactivation_cycle
            'severity'        => $this->str(16),  // LOW | MEDIUM | HIGH | CRITICAL
            'detail'          => $this->text(),
            'status'          => $this->str(16, false, 'OPEN'), // OPEN | ACKNOWLEDGED | RESOLVED
            'occurrences'     => $this->int(false, 1),
            'first_seen_at'   => $this->dt(),
            'last_seen_at'    => $this->dt(),
            'resolved_by'     => $this->str(191, true),
            'resolution'      => $this->str(255, true),
        ], ['license_id', 'status', 'severity']);
    }

    public function down(): void
    {
        $this->forge->dropTable('license_risk_flags', true);
        foreach (['integrity_status', 'integrity_at'] as $c) {
            if ($this->db->fieldExists($c, 'license_activations')) {
                $this->forge->dropColumn('license_activations', $c);
            }
        }
    }
}

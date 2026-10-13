<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * IMPL-LIC-01 P5: installation types and offline activation.
 *
 *  - license_activations.installation_type: PRODUCTION (counts toward max_activations), STAGING or DR
 *    (each limited by its own entitlement instead), and offline: activated from a request file.
 *  - Entitlements license.staging.max and license.dr.max (placeholders, like the rest of the catalog).
 */
class LicenseInstallationTypes extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $cols = ['installation_type' => $this->str(16, false, 'PRODUCTION'), 'offline' => $this->bool(), 'offline_request_nonce' => $this->str(64, true), 'offline_valid_until' => $this->dt()];
        foreach ($cols as $c => $def) {
            if (! $this->db->fieldExists($c, 'license_activations')) {
                $this->forge->addColumn('license_activations', [$c => $def]);
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        $keys = [
            ['license.staging.max', 'Staging servers', 'Staging installations, besides the production servers. Shown with a Staging banner.', [0, 1, 2]],
            ['license.dr.max', 'Disaster-recovery servers', 'Standby installations for disaster recovery, besides the production servers.', [0, 0, 1]],
        ];
        $sort = (int) ($this->db->table('entitlements')->selectMax('sort_order')->get()->getRow('sort_order') ?? 0);
        foreach ($keys as [$k, $l, $d, $vals]) {
            if ($this->db->table('entitlements')->where('key', $k)->countAllResults() === 0) {
                $this->db->table('entitlements')->insert(['key' => $k, 'label' => $l, 'kind' => 'limit', 'category' => 'limits', 'module_slug' => null, 'description' => $d, 'sort_order' => ++$sort, 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach (['FREE', 'BUSINESS', 'ENTERPRISE'] as $i => $e) {
                if ($this->db->table('edition_entitlements')->where('edition_code', $e)->where('entitlement_key', $k)->countAllResults() === 0) {
                    $this->db->table('edition_entitlements')->insert(['edition_code' => $e, 'entitlement_key' => $k, 'value' => json_encode($vals[$i]), 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['installation_type', 'offline', 'offline_request_nonce', 'offline_valid_until'] as $c) {
            if ($this->db->fieldExists($c, 'license_activations')) {
                $this->forge->dropColumn('license_activations', $c);
            }
        }
        $this->db->table('edition_entitlements')->whereIn('entitlement_key', ['license.staging.max', 'license.dr.max'])->delete();
        $this->db->table('entitlements')->whereIn('key', ['license.staging.max', 'license.dr.max'])->delete();
    }
}

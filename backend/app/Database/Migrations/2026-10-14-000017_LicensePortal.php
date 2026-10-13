<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * IMPL-LIC-01 P3, the Customer Portal (worksuitecloud.com) side of licences:
 *  - licenses.customer_ref: the portal's customer id; the portal sees only its customer's licences.
 *  - licenses.key_sealed: the key, encrypted with the app key, so a customer can reveal it again (keys made
 *    before this have none: they can be replaced instead).
 *  - licenses.is_trial: a trial licence started from the portal.
 */
class LicensePortal extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $cols = ['customer_ref' => $this->str(64, true), 'key_sealed' => $this->text(), 'is_trial' => $this->bool()];
        foreach ($cols as $c => $def) {
            if (! $this->db->fieldExists($c, 'licenses')) {
                $this->forge->addColumn('licenses', [$c => $def]);
            }
        }
        $this->db->query('CREATE INDEX licenses_customer_ref ON ' . $this->db->prefixTable('licenses') . ' (customer_ref)');
    }

    public function down(): void
    {
        foreach (['customer_ref', 'key_sealed', 'is_trial'] as $c) {
            if ($this->db->fieldExists($c, 'licenses')) {
                $this->forge->dropColumn('licenses', $c);
            }
        }
    }
}

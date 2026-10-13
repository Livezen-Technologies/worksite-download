<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * WorkSuite Server licences. The Download Center is the authority: it generates
 * the keys (only a hash and the last four characters are stored), sets edition,
 * expiry, update eligibility and how many servers may activate, and suspends or
 * revokes them. Servers activate a key (license_activations) and revalidate it;
 * every answer is a document signed with the update-response key.
 */
class CreateLicenses extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('licenses', [
            'public_id'       => $this->str(40),
            'key_hash'        => $this->str(64),
            'key_last4'       => $this->str(4),
            'organization'    => $this->str(191),
            'contact_email'   => $this->str(191, true),
            'edition'         => $this->str(16), // FREE | BUSINESS | ENTERPRISE
            'status'          => $this->str(16, false, 'ACTIVE'), // ACTIVE | SUSPENDED | REVOKED
            'max_activations' => $this->int(false, 1),
            'starts_at'       => $this->dt(),
            'expires_at'      => $this->dt(),
            'updates_until'   => $this->dt(), // support / update eligibility; null = same as expires_at
            'server_bound'    => $this->bool(true),
            'replaces_id'     => $this->fk(true),
            'notes'           => $this->text(),
            'status_reason'   => $this->str(255, true),
            'created_by'      => $this->str(191, true),
        ], ['status', 'edition', 'organization'], ['public_id', 'key_hash']);

        $this->table('license_activations', [
            'license_id'        => $this->fk(),
            'installation_id'   => $this->str(64),
            'fingerprint'       => $this->str(128, true),
            'hostname'          => $this->str(191, true),
            'server_url'        => $this->str(512, true),
            'activated_at'      => $this->dt(),
            'last_validated_at' => $this->dt(),
            'deactivated_at'    => $this->dt(),
            'deactivate_reason' => $this->str(255, true),
        ], ['license_id', 'installation_id']);

        if (! $this->db->fieldExists('license_id', 'server_installations')) {
            $this->forge->addColumn('server_installations', ['license_id' => $this->fk(true)]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('license_id', 'server_installations')) {
            $this->forge->dropColumn('server_installations', 'license_id');
        }
        $this->forge->dropTable('license_activations', true);
        $this->forge->dropTable('licenses', true);
    }
}

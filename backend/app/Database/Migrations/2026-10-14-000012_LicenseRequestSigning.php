<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * IMPL-LIC-01 P1 hardening.
 *
 *  - server_installations.request_secret_ref: the secret an installation signs its requests with
 *    (`WS-HMAC-SHA256`), sealed with the app encryption key; issued at licence activation.
 *  - license_nonces: each signed request's nonce, once per installation (replay), kept 7 days.
 *  - license_activations: the fingerprint's per-component hashes, the last confidence score, and a
 *    transfer state when the server no longer looks like the one activated.
 */
class LicenseRequestSigning extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        if (! $this->db->fieldExists('request_secret_ref', 'server_installations')) {
            $this->forge->addColumn('server_installations', ['request_secret_ref' => $this->text()]);
        }
        $this->table('license_nonces', [
            'installation_id' => $this->str(64),
            'nonce'           => $this->str(64),
        ], ['created_at'], [['installation_id', 'nonce']], true);

        $cols = [
            'fingerprint_components' => $this->text(),
            'fingerprint_confidence' => $this->int(true, null),
            'transfer_state'         => $this->str(24, true), // null | TRANSFER_REQUIRED
        ];
        foreach ($cols as $name => $def) {
            if (! $this->db->fieldExists($name, 'license_activations')) {
                $this->forge->addColumn('license_activations', [$name => $def]);
            }
        }
    }

    public function down(): void
    {
        foreach (['fingerprint_components', 'fingerprint_confidence', 'transfer_state'] as $c) {
            if ($this->db->fieldExists($c, 'license_activations')) {
                $this->forge->dropColumn('license_activations', $c);
            }
        }
        $this->forge->dropTable('license_nonces', true);
        if ($this->db->fieldExists('request_secret_ref', 'server_installations')) {
            $this->forge->dropColumn('server_installations', 'request_secret_ref');
        }
    }
}

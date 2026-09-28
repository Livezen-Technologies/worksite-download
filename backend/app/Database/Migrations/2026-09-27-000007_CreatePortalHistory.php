<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/** Per-account download history for signed-in portal users (§3.3). */
class CreatePortalHistory extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('portal_download_history', [
            'user_id'       => $this->fk(),
            'app_name'      => $this->str(150),
            'version'       => $this->str(64),
            'package_label' => $this->str(150),
            'created_at'    => $this->dt(),
        ], ['user_id'], [], false);
    }

    public function down(): void
    {
        $this->forge->dropTable('portal_download_history', true);
    }
}

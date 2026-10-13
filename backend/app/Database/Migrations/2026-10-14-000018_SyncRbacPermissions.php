<?php

namespace App\Database\Migrations;

use App\Libraries\Rbac;
use CodeIgniter\Database\Migration;

/**
 * Permissions added to Rbac::MATRIX after the first seed (license.*, and any
 * later ones) never reached the database, so no role held them and their pages
 * stayed hidden. Adds every missing permission and grants it to the roles the
 * matrix names. Additive: existing permissions and grants are left as they are.
 */
class SyncRbacPermissions extends Migration
{
    public function up(): void
    {
        $now   = gmdate('Y-m-d H:i:s');
        $roles = array_column($this->db->table('roles')->get()->getResultArray(), 'id', 'slug');
        foreach (Rbac::MATRIX as $slug => [$label, $grantees]) {
            if ($this->db->table('permissions')->where('slug', $slug)->countAllResults() > 0) {
                continue;
            }
            $this->db->table('permissions')->insert(['slug' => $slug, 'name' => $label, 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $this->db->insertID();
            foreach ($grantees as $role) {
                if (isset($roles[$role])) {
                    $this->db->table('role_permissions')->insert(['role_id' => $roles[$role], 'permission_id' => $id]);
                }
            }
        }
    }

    public function down(): void
    {
    }
}

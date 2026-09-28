<?php

namespace App\Database\Seeds;

use App\Libraries\Rbac;
use CodeIgniter\Database\Seeder;

/** Roles and permissions per §7.5 (idempotent). */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        foreach (Rbac::ROLES as $slug => $name) {
            if ($this->db->table('roles')->where('slug', $slug)->countAllResults() === 0) {
                $this->db->table('roles')->insert(['slug' => $slug, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach (Rbac::MATRIX as $slug => [$label]) {
            if ($this->db->table('permissions')->where('slug', $slug)->countAllResults() === 0) {
                $this->db->table('permissions')->insert(['slug' => $slug, 'name' => $label, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $this->db->table('role_permissions')->truncate();
        $roles = array_column($this->db->table('roles')->get()->getResultArray(), 'id', 'slug');
        $perms = array_column($this->db->table('permissions')->get()->getResultArray(), 'id', 'slug');
        foreach (array_keys(Rbac::ROLES) as $role) {
            foreach (Rbac::permissionsFor($role) as $perm) {
                $this->db->table('role_permissions')->insert(['role_id' => $roles[$role], 'permission_id' => $perms[$perm]]);
            }
        }
    }
}

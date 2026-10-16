<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * WorkSuite OS (§18): one row per (version, ring, arch) with the signed manifest the
 * OS package service verifies, plus the ISO/SBOM artifacts behind the public download page.
 */
class CreateOsReleaseTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('os_releases', [
            'public_id'        => $this->str(40),
            'release_id'       => $this->str(80), // wsos-<version>
            'version'          => $this->str(64),
            'min_version'      => $this->str(64),
            'ring'             => $this->str(16), // development | pilot | stable
            'arch'             => $this->str(8),  // amd64 | arm64
            'release_type'     => $this->str(16, false, 'FEATURE'), // SECURITY | FEATURE | EMERGENCY
            'state'            => $this->str(8, false, 'active'), // active | paused | pulled
            'rollout_percent'  => $this->int(false, 100),
            'deadline'         => $this->str(40, true),
            'entitlement'      => $this->str(40, true),
            'rollback_from'    => $this->str(64, true),
            'rollback_reason'  => $this->text(),
            'notes'            => $this->text(),
            'manifest'         => $this->text(), // signed JSON as served
            'iso_key'          => $this->str(512, true),
            'iso_sha256'       => $this->str(64, true),
            'iso_size'         => $this->bigint(),
            'sbom_key'         => $this->str(512, true),
            'published_by'     => $this->str(150, true),
            'published_at'     => $this->dt(),
        ], [['ring', 'arch', 'published_at']], [['version', 'ring', 'arch'], 'public_id']);
    }

    public function down(): void
    {
        $this->forge->dropTable('os_releases', true);
    }
}

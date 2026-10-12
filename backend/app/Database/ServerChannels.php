<?php

namespace App\Database;

use CodeIgniter\Database\BaseConnection;

/**
 * Channels specific to WorkSuite Server (WS-CORE-SYSUPDATE-SRS-001 §4: stable, beta, lts).
 * Shared by the migration (existing deployments) and the catalog seeder (new ones).
 */
final class ServerChannels
{
    /** App-specific LTS channel for lk.worksuite.server (Enterprise only). Idempotent. */
    public static function ensureLts(BaseConnection $db): void
    {
        $app = $db->table('applications')->where('slug', 'server')->get()->getRowArray();
        if (! $app) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $row = $db->table('release_channels')->where('code', 'LTS')->where('application_id', $app['id'])->get()->getRowArray();
        if (! $row) {
            $db->table('release_channels')->insert([
                'application_id' => $app['id'], 'code' => 'LTS', 'name' => 'LTS', 'description' => 'Long-term support releases of WorkSuite Server (Enterprise only).',
                'is_public' => 0, 'is_default' => 0, 'allow_direct_download' => 0, 'allow_auto_update' => 1,
                'access_rule' => json_encode(['audience' => 'license', 'editions' => ['ENTERPRISE']]), 'retention' => json_encode(['binaries' => 10]),
                'sort_order' => 6, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $row = $db->table('release_channels')->where('id', $db->insertID())->get()->getRowArray();
        }
        if ($db->table('application_channels')->where('application_id', $app['id'])->where('channel_id', $row['id'])->countAllResults() === 0) {
            $db->table('application_channels')->insert(['application_id' => $app['id'], 'channel_id' => $row['id'], 'enabled' => 1]);
        }
    }
}

<?php

namespace App\Services;

use App\Libraries\SecretStore;
use App\Libraries\Storage\StorageFactory;

/**
 * Storage & CDN administration (A5, FR-UC-STO-03/05, WF-C20).
 */
class StorageAdminService
{
    public function overview(): array
    {
        $db  = db_connect();
        $cfg = config('UpdateCenter');
        $providers = [];
        foreach ($db->table('storage_providers')->orderBy('is_active', 'DESC')->orderBy('id')->get()->getResultArray() as $p) {
            $releases = $db->table('release_package_revisions v')->select('p.release_id')->distinct()->join('release_packages p', 'p.id = v.release_package_id')->where('v.storage_provider_id', $p['id'])->whereIn('v.status', ['READY', 'REPLACED'])->countAllResults();
            $creds    = $p['kind'] === 'LOCAL' ? 'set' : (SecretStore::get($p['credentials_ref']) ? 'set' : 'missing');
            $providers[] = [
                'id' => (int) $p['id'], 'name' => $p['name'], 'kind' => $p['kind'], 'bucket' => $p['bucket'], 'region' => $p['region'], 'endpoint' => $p['endpoint'],
                'cdn_host' => $p['cdn_host'] ?: $cfg->cdnHost, 'is_active' => (bool) $p['is_active'], 'versioning' => (bool) $p['versioning'],
                'replication' => json_decode((string) $p['replication'], true) ?: [], 'status' => $p['status'], 'status_detail' => $p['status_detail'],
                'last_check_at' => Catalog::iso($p['last_check_at']), 'usage_bytes' => (int) $p['usage_bytes'], 'object_count' => (int) $p['object_count'],
                'credentials' => $creds, 'credentials_rotated_at' => Catalog::iso($p['credentials_rotated_at']), 'release_count' => $releases,
            ];
        }
        $purge    = json_decode((string) $db->table('settings')->select('value')->where('key', 'cdn.last_purge')->get()->getRow('value'), true);
        $degraded = array_map(static fn ($r) => ['release_id' => $r['public_id'], 'version' => $r['version'], 'package' => $r['file_name'], 'detail' => 'HEAD probe failed at ' . Catalog::iso($r['health_checked_at'])],
            $db->table('release_packages p')->select('r.public_id, r.version, v.file_name, p.health_checked_at')->join('releases r', 'r.id = p.release_id')->join('release_package_revisions v', 'v.id = p.active_revision_id')->where('p.health', 'DEGRADED')->get()->getResultArray());
        $downloads30 = $db->table('download_events e')->select('SUM(v.size_bytes) AS b', false)->join('release_package_revisions v', 'v.id = e.revision_id')->where('e.redirect_ok', 1)->where('e.created_at >=', gmdate('Y-m-d H:i:s', time() - 30 * 86400))->get()->getRow('b');

        return [
            'providers'    => $providers,
            'cdn'          => ['host' => $cfg->cdnHost, 'url_lifetime_s' => $cfg->downloadUrlTtl, 'signing' => $cfg->cdnSigning, 'purge' => $cfg->cdnPurge, 'status' => $degraded === [] ? 'OK' : 'DEGRADED', 'last_purge_at' => $purge['at'] ?? null, 'last_purge_status' => $purge['status'] ?? null, 'hit_ratio' => null, 'egress_30d_bytes' => $downloads30 === null ? null : (int) $downloads30],
            'distribution' => ['degraded_packages' => $degraded],
            'migrations'   => array_map(static fn ($m) => ['id' => (int) $m['id'], 'from' => (int) $m['from_provider_id'], 'to' => (int) $m['to_provider_id'], 'status' => $m['status'], 'total' => (int) $m['total'], 'copied' => (int) $m['copied'], 'mismatches' => json_decode((string) $m['mismatches'], true) ?: [], 'created_at' => Catalog::iso($m['created_at'])],
                $db->table('storage_migrations')->orderBy('id', 'DESC')->limit(10)->get()->getResultArray()),
        ];
    }

    /** HEAD every active revision of distributed releases; mark DEGRADED (WF-C20). */
    public function probe(): array
    {
        $db   = db_connect();
        $rows = $db->table('release_packages p')->select('p.id, p.health, r.version, r.public_id, v.storage_provider_id, v.storage_object_key, v.size_bytes, v.file_name, a.name AS app_name')
            ->join('releases r', 'r.id = p.release_id')->join('applications a', 'a.id = r.application_id')->join('release_package_revisions v', 'v.id = p.active_revision_id')
            ->whereIn('r.status', ['PUBLISHED', 'PAUSED', 'SUPERSEDED', 'APPROVED', 'TESTING'])->get()->getResultArray();
        $ok = $bad = 0;
        $providers = [];
        foreach ($rows as $r) {
            try {
                $providers[$r['storage_provider_id']] ??= StorageFactory::fromRow(Catalog::storageById((int) $r['storage_provider_id']));
                $head = $providers[$r['storage_provider_id']]->head((string) $r['storage_object_key']);
                $good = $head !== null && (int) $head['size'] === (int) $r['size_bytes'];
            } catch (\Throwable) {
                $good = false;
            }
            $db->table('release_packages')->where('id', $r['id'])->update(['health' => $good ? 'OK' : 'DEGRADED', 'health_checked_at' => Catalog::now()]);
            if ($good) {
                $ok++;
            } else {
                $bad++;
                if ($r['health'] !== 'DEGRADED') {
                    service('notifier')->notify('distribution.degraded', ['title' => 'Distribution DEGRADED', 'body' => "{$r['app_name']} {$r['version']} · {$r['file_name']} failed the storage probe", 'link' => '/admin/storage']);
                }
            }
        }
        foreach ($db->table('storage_providers')->where('is_active', 1)->get()->getResultArray() as $p) {
            try {
                $u = StorageFactory::fromRow($p)->usage();
                $db->table('storage_providers')->where('id', $p['id'])->update(['usage_bytes' => $u['bytes'], 'object_count' => $u['objects']]);
            } catch (\Throwable) {
            }
        }

        return ['checked' => $ok + $bad, 'ok' => $ok, 'degraded' => $bad];
    }
}

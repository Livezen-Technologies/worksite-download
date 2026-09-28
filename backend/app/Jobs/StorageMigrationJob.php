<?php

namespace App\Jobs;

use App\Libraries\Storage\StorageFactory;
use App\Services\Catalog;

/**
 * Copy every stored revision to the new provider, verify SHA-256 per object,
 * and switch only when everything matches (FR-UC-STO-04, WF-C18).
 */
class StorageMigrationJob implements JobInterface
{
    public function handle(array $payload): void
    {
        $db = db_connect();
        $m  = $db->table('storage_migrations')->where('id', $payload['migration_id'])->get()->getRowArray();
        if (! $m || $m['status'] !== 'PENDING') {
            return;
        }
        $from = StorageFactory::fromRow(Catalog::storageById((int) $m['from_provider_id']));
        $to   = StorageFactory::fromRow(Catalog::storageById((int) $m['to_provider_id']));
        $revs = $db->table('release_package_revisions')->where('storage_provider_id', $m['from_provider_id'])->whereIn('status', ['READY', 'REPLACED'])->where('storage_object_key IS NOT NULL')->get()->getResultArray();
        $db->table('storage_migrations')->where('id', $m['id'])->update(['status' => 'RUNNING', 'total' => count($revs)]);
        $mismatch = [];
        $tmp      = rtrim(config('UpdateCenter')->uploadTempDir, '/') . '/migrate-' . $m['id'] . '.bin';
        foreach ($revs as $i => $r) {
            try {
                $in  = $from->stream($r['storage_object_key']);
                $out = fopen($tmp, 'wb');
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                $to->putFile($r['storage_object_key'], $tmp);
                $check = $to->stream($r['storage_object_key']);
                $ctx   = hash_init('sha256');
                hash_update_stream($ctx, $check);
                fclose($check);
                if (hash_final($ctx) !== $r['sha256']) {
                    $mismatch[] = $r['storage_object_key'];
                }
            } catch (\Throwable $e) {
                $mismatch[] = $r['storage_object_key'] . ': ' . $e->getMessage();
            } finally {
                @unlink($tmp);
            }
            $db->table('storage_migrations')->where('id', $m['id'])->update(['copied' => $i + 1]);
        }
        if ($mismatch !== []) {
            $db->table('storage_migrations')->where('id', $m['id'])->update(['status' => 'FAILED', 'mismatches' => json_encode(array_slice($mismatch, 0, 100))]);
            service('notifier')->notify('storage.problem', ['title' => 'Storage migration aborted', 'body' => count($mismatch) . ' object(s) failed verification — provider not switched.', 'link' => '/admin/storage']);
            service('audit')->record('storage.migration_failed', 'storage', (string) $m['to_provider_id'], null, ['mismatches' => count($mismatch)], null, ['type' => 'SYSTEM', 'id' => 'worker', 'label' => 'storage migration']);

            return;
        }
        $db->transStart();
        $db->table('release_package_revisions')->where('storage_provider_id', $m['from_provider_id'])->update(['storage_provider_id' => $m['to_provider_id']]);
        $db->table('storage_providers')->update(['is_active' => 0]);
        $db->table('storage_providers')->where('id', $m['to_provider_id'])->update(['is_active' => 1]);
        $db->table('storage_migrations')->where('id', $m['id'])->update(['status' => 'SWITCHED']);
        $db->transComplete();
        service('audit')->record('storage.switched', 'storage', (string) $m['to_provider_id'], ['provider' => (int) $m['from_provider_id']], ['provider' => (int) $m['to_provider_id'], 'objects' => count($revs)], null, ['type' => 'SYSTEM', 'id' => 'worker', 'label' => 'storage migration']);
    }
}

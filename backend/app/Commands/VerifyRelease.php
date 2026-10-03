<?php

namespace App\Commands;

use App\Libraries\Metrics;
use App\Libraries\Signing\Ed25519;
use App\Libraries\Storage\StorageFactory;
use App\Services\KeyRegistry;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Verifies a published release end to end (§13.3 monthly restore drill,
 * §12 integrity): the stored release manifest signature, and every active
 * package object in storage hashed against the revision record and the
 * signed manifest. Read-only. Exit 1 on any mismatch.
 */
class VerifyRelease extends BaseCommand
{
    protected $group       = 'Update Center';
    protected $name        = 'uc:verify-release';
    protected $description = 'Verify a release manifest signature and re-hash its package objects from storage.';
    protected $usage       = 'uc:verify-release <release public_id>';

    public function run(array $params)
    {
        $id = $params[0] ?? null;
        $db = db_connect();
        $release = $id ? $db->table('releases')->where('public_id', $id)->get()->getRowArray() : null;
        if (! $release) {
            CLI::error('Release not found');

            return EXIT_ERROR;
        }
        $ok = true;

        $m = $db->table('release_manifests')->where('release_id', $release['id'])->where('kind', 'RELEASE')->where('signature IS NOT NULL')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $manifest = $m ? (json_decode((string) $m['manifest_json'], true) ?: []) : [];
        $sigOk = $m && Ed25519::verifyDocument($manifest, KeyRegistry::releaseKeys());
        CLI::write('Manifest: ' . ($m ? "v{$m['manifest_version']} key {$m['key_id']} — " . ($sigOk ? 'signature OK' : 'SIGNATURE INVALID') : 'MISSING'), $sigOk ? 'green' : 'red');
        if (! $sigOk) {
            Metrics::count('signature_verification_failed');
            $ok = false;
        }
        $bySha = [];
        foreach ((array) ($manifest['packages'] ?? []) as $p) {
            $bySha[$p['file_name'] ?? ''] = $p['sha256'] ?? null;
        }

        $rows = [];
        $pkgs = $db->table('release_packages p')->select('r.file_name, r.sha256, r.size_bytes, r.storage_provider_id, r.storage_object_key')
            ->join('release_package_revisions r', 'r.id = p.active_revision_id')->where('p.release_id', $release['id'])->where('r.status', 'READY')->get()->getResultArray();
        foreach ($pkgs as $p) {
            $status = 'OK';
            $actual = null;
            try {
                $prov   = StorageFactory::fromRow($db->table('storage_providers')->where('id', $p['storage_provider_id'])->get()->getRowArray() ?? []);
                $stream = $prov->stream((string) $p['storage_object_key']);
                $ctx    = hash_init('sha256');
                hash_update_stream($ctx, $stream);
                is_resource($stream) && fclose($stream);
                $actual = hash_final($ctx);
                if (! hash_equals((string) $p['sha256'], $actual)) {
                    $status = 'HASH MISMATCH (record)';
                } elseif (($bySha[$p['file_name']] ?? null) !== $p['sha256']) {
                    $status = 'NOT IN MANIFEST / MISMATCH';
                }
            } catch (\Throwable $e) {
                $status = 'UNREADABLE: ' . $e->getMessage();
            }
            if ($status !== 'OK') {
                $ok = false;
            }
            $rows[] = [$p['file_name'], substr((string) $actual, 0, 16), $status];
        }
        if ($rows === []) {
            CLI::write('No active packages.', 'yellow');
        } else {
            CLI::table($rows, ['File', 'SHA-256 (storage)', 'Result']);
        }
        CLI::write($ok ? "Release {$release['version']} verified." : "Release {$release['version']} FAILED verification.", $ok ? 'green' : 'red');

        return $ok ? EXIT_SUCCESS : EXIT_ERROR;
    }
}

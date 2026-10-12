<?php

namespace App\Jobs;

use App\Exceptions\ApiException;
use App\Libraries\Scanner\BinaryInspector;
use App\Libraries\Scanner\ClamAvScanner;
use App\Libraries\Scanner\NullScanner;
use App\Libraries\Scanner\ServerPackageValidator;
use App\Libraries\Scanner\SignatureVerifier;
use App\Libraries\Storage\StorageFactory;
use App\Services\Catalog;
use App\Services\ManifestService;
use App\Services\PackageService;
use App\Services\ReleaseService;
use App\Services\TenantEventsService;
use App\Services\UpdateResolverService;

/**
 * Worker pipeline (FR-UC-UPL-04..07, WF-C04): scan → SHA-256 → size →
 * metadata → signature → attestation → move → READY. Each step is recorded
 * for the live UI. Files are only read — never executed.
 */
class ProcessPackageRevisionJob implements JobInterface
{
    private array $revision;
    private array $pipeline;

    public function handle(array $payload): void
    {
        $db = db_connect();
        $this->revision = $db->table('release_package_revisions')->where('id', $payload['revision_id'])->get()->getRowArray() ?? throw new \RuntimeException('Revision not found');
        if ($this->revision['status'] !== 'PROCESSING') {
            return;
        }
        $this->pipeline = json_decode((string) $this->revision['pipeline'], true) ?: [];
        $package        = $db->table('release_packages')->where('id', $this->revision['release_package_id'])->get()->getRowArray();
        $release        = Catalog::releaseById((int) $package['release_id']);
        $app            = Catalog::appById((int) $release['application_id']);
        $provider       = StorageFactory::fromRow(Catalog::storageById((int) $this->revision['storage_provider_id']));
        $cfg            = config('UpdateCenter');
        $tmpDir         = rtrim($cfg->uploadTempDir, '/') . '/processing';
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0700, true);
        }
        $tmp = $tmpDir . '/' . $this->revision['public_id'] . '.bin';

        try {
            // Copy from quarantine to a private, non-executable temp file while hashing.
            $in  = $provider->stream($this->revision['quarantine_key']);
            $out = fopen($tmp, 'wb');
            chmod($tmp, 0600);
            $ctx  = hash_init('sha256');
            $size = 0;
            while (! feof($in)) {
                $buf = fread($in, 1 << 20);
                if ($buf === false || $buf === '') {
                    break;
                }
                hash_update($ctx, $buf);
                fwrite($out, $buf);
                $size += strlen($buf);
            }
            fclose($in);
            fclose($out);
            $sha = hash_final($ctx);

            // 1. Malware scan.
            $this->step('scan', 'running');
            $scanner = $cfg->scanner === 'clamav' ? new ClamAvScanner($cfg->clamavSocket) : new NullScanner();
            $fh      = fopen($tmp, 'rb');
            $scan    = $scanner->scan($fh);
            fclose($fh);
            $this->save(['scan_status' => $scan['status'], 'scan_detail' => $scan['detail']]);
            if ($scan['status'] === 'INFECTED') {
                $provider->delete($this->revision['quarantine_key']);
                $this->reject('scan', 'SCAN_INFECTED', $scan['detail'], $release, $package, true);

                return;
            }
            if ($scan['status'] === 'ERROR' && $cfg->isProduction()) {
                throw new \RuntimeException('Malware scanner error: ' . $scan['detail']);
            }
            $this->step('scan', $scan['status'] === 'SKIPPED' ? 'skipped' : 'done', $scan['detail']);

            // 2. SHA-256 + size.
            $this->step('sha256', 'done', $sha);
            if ($size !== (int) $this->revision['size_bytes']) {
                $this->reject('sha256', 'SIZE_MISMATCH', "Declared {$this->revision['size_bytes']} bytes, received {$size}", $release, $package);

                return;
            }
            $this->save(['sha256' => $sha, 'size_bytes' => $size]);

            // 3. Metadata.
            $this->step('metadata', 'running');
            $fh   = fopen($tmp, 'rb');
            $head = (string) fread($fh, 1 << 20);
            fseek($fh, max(0, $size - 512));
            $tail = (string) fread($fh, 512);
            fclose($fh);
            $metadata = ['inspection' => BinaryInspector::inspect($head, $tail)];
            if ($app['kind'] === 'SERVER') {
                $modules = [];
                foreach (db_connect()->table('edition_modules')->get()->getResultArray() as $m) {
                    $modules[$m['edition_code']][] = $m['module_slug'];
                }
                $val = (new ServerPackageValidator())->validate($tmp, [
                    'version' => $release['version'], 'build' => (int) $release['build_number'],
                    'channel' => Catalog::channelById((int) $release['channel_id'])['code'], 'edition' => $package['edition'],
                ], $modules);
                $metadata['validation']   = $val['checks'];
                $metadata['package_json'] = $val['package_json'];
                $metadata['files']        = $val['files'];
                $this->save(['metadata' => json_encode($metadata)]);
                if (! $val['ok']) {
                    $this->reject('metadata', $val['reject_code'] ?? 'VALIDATION_FAILED', end($val['checks'])['detail'] ?? 'Validation failed', $release, $package);

                    return;
                }
                $this->syncServerMeta($release, $val['package_json']);
            } else {
                $match = BinaryInspector::matches($metadata['inspection'], $package['package_type'], $package['architecture']);
                $this->save(['metadata' => json_encode($metadata)]);
                if (! $match['ok']) {
                    $this->reject('metadata', $match['code'], $match['message'], $release, $package);

                    return;
                }
            }
            $this->step('metadata', 'done', $metadata['inspection']['format'] . ($metadata['inspection']['architectures'] ? ' · ' . implode('/', $metadata['inspection']['architectures']) : ''));

            // 4. Platform signature.
            $this->step('signature', 'running');
            $channelCode = (string) (Catalog::channelById((int) $release['channel_id'])['code'] ?? '');
            $sig         = $cfg->allowUnsignedTestChannels && $channelCode !== 'STABLE'
                ? ['status' => 'SKIPPED', 'kind' => 'none', 'detail' => "Not checked: unsigned builds are allowed on the {$channelCode} channel", 'signer' => null]
                : (new SignatureVerifier($cfg->signatureVerifier, $cfg->expectedPublisher, $cfg->gpgKeyring))->verify($tmp, $package['platform'], $package['package_type']);
            $this->save(['signature_status' => $sig['status'], 'signature_detail' => json_encode($sig)]);
            if ($sig['status'] === 'INVALID') {
                $this->reject('signature', 'SIGNATURE_INVALID', $sig['detail'], $release, $package);

                return;
            }
            $this->step('signature', $sig['status'] === 'SKIPPED' ? 'skipped' : 'done', $sig['signer'] ?? $sig['detail']);

            // 5. CI attestation.
            $att = db_connect()->table('build_attestations')->where('release_id', $release['id'])->where('verified', 1)->orderBy('id', 'DESC')->get()->getRowArray();
            if ($att) {
                $hashes = array_map(static fn ($f) => strtolower((string) ($f['sha256'] ?? '')), (array) (json_decode($att['payload'], true)['files'] ?? []));
                if (! in_array($sha, $hashes, true)) {
                    $this->save(['attestation_match' => 'MISMATCH']);
                    $this->reject('attestation', 'ATTESTATION_MISMATCH', 'SHA-256 not present in the CI build attestation', $release, $package);

                    return;
                }
                $this->save(['attestation_match' => 'MATCH']);
                $this->step('attestation', 'done', 'Matches CI attestation');
            } else {
                $this->save(['attestation_match' => 'NO_ATTESTATION']);
                $this->step('attestation', 'skipped', 'No CI attestation recorded yet');
            }

            // 6. Move to release storage.
            $key = PackageService::objectKey($release, $package, $this->revision);
            $provider->move($this->revision['quarantine_key'], $key);
            $this->save(['storage_object_key' => $key]);
            $this->step('move', 'done', $key);

            // 7. READY.
            $this->activate($release, $package);
        } catch (\Throwable $e) {
            $this->step($this->currentStep(), 'failed', $e->getMessage());
            $this->save(['status' => 'REJECTED', 'reject_code' => 'PROCESSING_ERROR']);
            $this->finishPackage($release, $package);
            service('notifier')->notify('upload.failed', ['title' => 'Package processing failed', 'body' => $this->revision['file_name'] . ': ' . $e->getMessage(), 'link' => '/admin/releases/' . $release['public_id'] . '?tab=files'], $this->revision['uploaded_by'] ? [(int) $this->revision['uploaded_by']] : null);
            log_message('error', 'Package processing failed: ' . $e->getMessage());
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private function activate(array $release, array $package): void
    {
        $db       = db_connect();
        $now      = Catalog::now();
        $previous = $package['active_revision_id'] ? $db->table('release_package_revisions')->where('id', $package['active_revision_id'])->get()->getRowArray() : null;
        $this->save(['status' => 'READY']);
        $db->table('release_packages')->where('id', $package['id'])->update(['active_revision_id' => $this->revision['id'], 'status' => 'READY', 'health' => 'OK', 'health_checked_at' => $now, 'updated_at' => $now]);
        if ($previous) {
            $db->table('release_package_revisions')->where('id', $previous['id'])->update(['status' => 'REPLACED', 'updated_at' => $now]);
        }

        $published = in_array($release['status'], ['PUBLISHED', 'PAUSED'], true);
        if ($published) {
            // Emergency replacement: build revision, re-signed manifests, purge, webhook (WF-C16).
            $db->table('releases')->where('id', $release['id'])->set('build_revision', 'build_revision + 1', false)->update(['updated_at' => $now]);
            $release = Catalog::releaseById((int) $release['id']);
            try {
                (new ManifestService())->signRelease($release, 'Emergency replacement: ' . ($this->revision['replace_reason'] ?? ''));
                (new ManifestService())->signChannel((int) $release['application_id'], (int) $release['channel_id'], 'Emergency replacement');
            } catch (ApiException $e) {
                // Roll back activation: the old revision stays live.
                $db->table('release_packages')->where('id', $package['id'])->update(['active_revision_id' => $previous['id'] ?? null, 'status' => $previous ? 'READY' : 'EMPTY']);
                if ($previous) {
                    $db->table('release_package_revisions')->where('id', $previous['id'])->update(['status' => 'READY']);
                }
                $db->table('releases')->where('id', $release['id'])->set('build_revision', 'build_revision - 1', false)->update();
                \App\Libraries\Metrics::count('signing_refused');
                $this->save(['status' => 'REJECTED', 'reject_code' => 'SIGNING_REFUSED']);
                $this->step('ready', 'failed', $e->getMessage());

                return;
            }
            service('audit')->record('release.file_replaced', 'release', $release['public_id'], ['revision' => $previous['public_id'] ?? null, 'sha256' => $previous['sha256'] ?? null], ['revision' => $this->revision['public_id'], 'sha256' => $this->revision['sha256'] ?? null, 'build_revision' => (int) $release['build_revision']], $this->revision['replace_reason']);
            (new TenantEventsService())->emit('release.package_replaced', (int) $release['application_id'], (int) $release['id'], (int) $release['channel_id'], ['package_id' => $package['public_id'], 'sha256' => db_connect()->table('release_package_revisions')->where('id', $this->revision['id'])->get()->getRow('sha256')]);
            \App\Libraries\Queue::push(CdnPurgeJob::class, ['paths' => (new ReleaseService())->purgePaths($release)]);
            UpdateResolverService::bumpCacheEpoch();
        } else {
            service('audit')->record('release.file_uploaded', 'release', $release['public_id'], $previous ? ['revision' => $previous['public_id'], 'sha256' => $previous['sha256']] : null, ['package' => $package['public_id'], 'revision' => $this->revision['public_id'], 'file' => $this->revision['file_name']], $this->revision['replace_reason'], $this->revision['uploaded_by'] ? null : ['type' => 'API_CLIENT', 'id' => (string) $this->revision['uploaded_by_client'], 'label' => 'CI']);
        }
        $this->step('ready', 'done', 'Revision ' . $this->revision['revision_number'] . ' is active');
        $this->finishPackage($release, $package);
    }

    /** Release leaves UPLOADING once nothing is processing. */
    private function finishPackage(array $release, array $package): void
    {
        $db  = db_connect();
        $pkg = $db->table('release_packages')->where('id', $package['id'])->get()->getRowArray();
        if ($pkg && $pkg['status'] === 'PROCESSING') {
            $still = $db->table('release_package_revisions')->where('release_package_id', $pkg['id'])->where('status', 'PROCESSING')->countAllResults();
            if ($still === 0) {
                $db->table('release_packages')->where('id', $pkg['id'])->update(['status' => $pkg['active_revision_id'] ? 'READY' : 'REJECTED']);
            }
        }
        $release = Catalog::releaseById((int) $release['id']);
        if ($release['status'] === 'UPLOADING' && $db->table('release_packages')->where('release_id', $release['id'])->where('status', 'PROCESSING')->countAllResults() === 0) {
            $open = $db->table('uploads')->where('release_id', $release['id'])->where('status', 'OPEN')->countAllResults();
            if ($open === 0) {
                (new ReleaseService())->transition($release, 'DRAFT');
            }
        }
    }

    private function reject(string $step, string $code, ?string $detail, array $release, array $package, bool $security = false): void
    {
        $this->step($step, 'failed', $detail);
        $this->save(['status' => 'REJECTED', 'reject_code' => $code]);
        $this->finishPackage($release, $package);
        service('audit')->record('release.file_rejected', 'release', $release['public_id'], null, ['revision' => $this->revision['public_id'], 'file' => $this->revision['file_name'], 'code' => $code, 'detail' => $detail], null, ['type' => 'SYSTEM', 'id' => 'worker', 'label' => 'package pipeline']);
        service('notifier')->notify('package.verification_failed', ['title' => ($security ? 'SECURITY: ' : '') . 'Package rejected — ' . $code, 'body' => $this->revision['file_name'] . ': ' . $detail, 'link' => '/admin/releases/' . $release['public_id'] . '?tab=files'], $security ? null : ($this->revision['uploaded_by'] ? [(int) $this->revision['uploaded_by']] : null));
        if ($security) {
            service('notifier')->notify('storage.problem', ['title' => 'Security alert: malware detected in upload', 'body' => $this->revision['file_name'] . ' — ' . $detail]);
        }
    }

    private function step(string $name, string $status, ?string $detail = null): void
    {
        foreach ($this->pipeline as &$s) {
            if ($s['step'] === $name) {
                $s['status'] = $status;
                $s['detail'] = $detail !== null ? mb_substr($detail, 0, 500) : $s['detail'];
                $s['at']     = gmdate('Y-m-d\TH:i:s\Z');
            }
        }
        unset($s);
        $this->save(['pipeline' => json_encode($this->pipeline)]);
    }

    private function currentStep(): string
    {
        foreach ($this->pipeline as $s) {
            if (in_array($s['status'], ['running', 'pending'], true)) {
                return $s['step'];
            }
        }

        return 'ready';
    }

    private function save(array $set): void
    {
        db_connect()->table('release_package_revisions')->where('id', $this->revision['id'])->update($set + ['updated_at' => Catalog::now()]);
        $this->revision = array_merge($this->revision, $set);
    }

    /** Copy manifest facts into release_server_meta when not set manually. */
    private function syncServerMeta(array $release, array $pkg): void
    {
        $db   = db_connect();
        $meta = $db->table('release_server_meta')->where('release_id', $release['id'])->get()->getRowArray();
        $set  = [];
        if (empty($meta['min_from_version']) && ! empty($pkg['min_from_version'])) {
            $set['min_from_version'] = $pkg['min_from_version'];
        }
        if (empty($meta['requires']) && ! empty($pkg['requires'])) {
            $set['requires'] = json_encode($pkg['requires']);
        }
        if (empty($meta['migrations']) && ! empty($pkg['migrations'])) {
            $set['migrations'] = json_encode($pkg['migrations']);
        }
        if (empty($meta['desktop_compat']) && ! empty($pkg['desktop_compat'])) {
            $set['desktop_compat'] = $pkg['desktop_compat'];
        }
        if ($set === []) {
            return;
        }
        if ($meta) {
            $db->table('release_server_meta')->where('id', $meta['id'])->update($set + ['updated_at' => Catalog::now()]);
        } else {
            $db->table('release_server_meta')->insert($set + ['release_id' => $release['id'], 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        }
    }
}

<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\ProcessPackageRevisionJob;
use App\Libraries\Queue;
use App\Libraries\Storage\StorageFactory;
use App\Libraries\Ulid;

/**
 * Package slots (platform × arch × type [× edition]), revisions and uploads
 * (§4.4, FR-UC-PKG-01..07, FR-UC-UPL-01..06, WF-C04, WF-C16).
 */
class PackageService
{
    public const PIPELINE = ['upload', 'quarantine', 'scan', 'sha256', 'metadata', 'signature', 'attestation', 'move', 'ready'];

    public const EDITIONS = ['FREE', 'BUSINESS', 'ENTERPRISE', 'ALL'];

    /**
     * Register an upload for a slot; returns the slot and upload session.
     */
    public function createUpload(array $release, array $in, AuthContext $ctx): array
    {
        $app      = Catalog::appById((int) $release['application_id']);
        $platform = strtolower((string) ($in['platform'] ?? ''));
        $arch     = strtolower((string) ($in['architecture'] ?? $in['arch'] ?? ''));
        $type     = strtolower((string) ($in['package_type'] ?? $in['type'] ?? ''));
        $edition  = ServerReleaseConsoleService::normalizeEdition((string) ($in['edition'] ?? '')); // "professional" = FREE
        $fileName = basename(str_replace('\\', '/', (string) ($in['file_name'] ?? '')));
        $size     = (int) ($in['size'] ?? 0);
        $protocol = (string) ($in['protocol'] ?? 'tus');
        $cfg      = config('UpdateCenter');

        if ($app['kind'] === 'SERVER') {
            $platform = 'server';
            $arch     = 'any';
            $type     = 'zip';
            if (! in_array($edition, self::EDITIONS, true)) {
                throw ApiException::validation(['edition' => 'Select Professional (Free), Business, Enterprise or All editions before uploading']);
            }
            if ($size > $cfg->serverZipMaxBytes) {
                throw ApiException::validation(['size' => 'Server packages are limited to 2 GB']);
            }
        } else {
            $edition = '';
            $slot    = null;
            foreach (Catalog::platforms((int) $app['id']) as $pl) {
                if ($pl['platform'] === $platform && $pl['architecture'] === $arch) {
                    $slot = $pl;
                }
            }
            if ($slot === null) {
                throw ApiException::validation(['platform' => "{$platform} {$arch} is not configured for {$app['name']}"]);
            }
            if (! in_array($type, $slot['package_types'], true)) {
                throw ApiException::validation(['package_type' => "Allowed types for {$platform} {$arch}: " . implode(', ', $slot['package_types'])]);
            }
        }
        if ($fileName === '' || strlen($fileName) > 200 || ! preg_match('/^[\w.+\- ()]+$/u', $fileName)) {
            throw ApiException::validation(['file_name' => 'Invalid file name']);
        }
        if ($size <= 0 || $size > $cfg->uploadMaxBytes) {
            throw ApiException::validation(['size' => 'File size must be between 1 byte and 4 GB']);
        }
        if (! in_array($protocol, ['tus', 'multipart'], true)) {
            throw ApiException::validation(['protocol' => 'tus or multipart']);
        }

        $db      = db_connect();
        $package = $db->table('release_packages')->where(['release_id' => $release['id'], 'platform' => $platform, 'architecture' => $arch, 'package_type' => $type, 'edition' => $edition])->get()->getRowArray();
        $published = in_array($release['status'], ['PUBLISHED', 'PAUSED'], true);
        $reason    = trim((string) ($in['reason'] ?? ''));
        if ($published) {
            // Emergency replacement (§4.3) — permission + MFA checked by the controller.
            if ($package === null || ! $package['active_revision_id']) {
                throw new ApiException('RELEASE_IMMUTABLE', 'New package slots cannot be added after publication — ship a new build', 409);
            }
            if ($reason === '') {
                throw ApiException::validation(['reason' => 'Emergency replacement requires a reason']);
            }
        } elseif (! in_array($release['status'], ['DRAFT', 'UPLOADING', 'READY_FOR_TEST', 'TESTING'], true)) {
            throw new ApiException('INVALID_TRANSITION', "Uploads are not allowed while the release is {$release['status']}", 409);
        } elseif ($package !== null && $package['active_revision_id'] && $reason === '') {
            throw ApiException::validation(['reason' => 'Replacing a file needs a reason (kept with the revision history)']);
        }

        $now = Catalog::now();
        if ($package === null) {
            $minOs = null;
            foreach (Catalog::platforms((int) $app['id']) as $pl) {
                if ($pl['platform'] === $platform && $pl['architecture'] === $arch) {
                    $minOs = $pl['min_os_version'];
                }
            }
            $db->table('release_packages')->insert([
                'public_id' => Ulid::prefixed('pkg'), 'release_id' => $release['id'], 'platform' => $platform, 'architecture' => $arch,
                'package_type' => $type, 'edition' => $edition, 'scope' => $edition === '' ? null : ($edition === 'ALL' ? 'core-patch' : 'full'),
                'minimum_os_version' => $in['minimum_os_version'] ?? $minOs, 'status' => 'EMPTY', 'health' => 'UNKNOWN', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $package = $db->table('release_packages')->where('id', $db->insertID())->get()->getRowArray();
        } elseif (isset($in['minimum_os_version'])) {
            $db->table('release_packages')->where('id', $package['id'])->update(['minimum_os_version' => $in['minimum_os_version']]);
        }

        $storage   = Catalog::activeStorage();
        $uploadId  = Ulid::prefixed('upl');
        $quarantine = '_incoming/' . $uploadId . '/' . $fileName;
        $upload    = [
            'public_id' => $uploadId, 'release_id' => $release['id'], 'release_package_id' => $package['id'],
            'user_id' => $ctx->userId(), 'api_client_id' => $ctx->clientId(), 'protocol' => $protocol, 'file_name' => $fileName,
            'size_bytes' => $size, 'offset_bytes' => 0, 'storage_provider_id' => $storage['id'], 'quarantine_key' => $quarantine,
            'reason' => $reason ?: null, 'status' => 'OPEN', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400), 'created_at' => $now, 'updated_at' => $now,
        ];
        $response = ['id' => $uploadId, 'protocol' => $protocol, 'tus_endpoint' => '/api/v1/uploads/tus', 'chunk_size' => $cfg->uploadChunkBytes];
        if ($protocol === 'multipart') {
            $provider = StorageFactory::fromRow($storage);
            $mp       = $provider->initiateMultipart($quarantine);
            $upload['multipart_upload_id'] = $mp['upload_id'];
            $parts    = [];
            $n        = (int) ceil($size / $cfg->uploadChunkBytes);
            for ($i = 1; $i <= $n; $i++) {
                $parts[] = ['part_number' => $i, 'url' => $provider->presignUploadPart($quarantine, $mp['upload_id'], $i, 6 * 3600)];
            }
            $response['parts'] = $parts;
        }
        $db->table('uploads')->insert($upload);
        if ($release['status'] === 'DRAFT') {
            (new ReleaseService())->transition($release, 'UPLOADING');
        }
        service('audit')->record('release.file_upload_started', 'release', $release['public_id'], null, ['package' => $package['public_id'], 'file' => $fileName, 'size' => $size, 'edition' => $edition ?: null], $reason ?: null);

        return ['package' => $this->present($package), 'upload' => $response];
    }

    public function upload(string $publicId): array
    {
        return db_connect()->table('uploads')->where('public_id', $publicId)->get()->getRowArray() ?? throw ApiException::notFound('UPLOAD_NOT_FOUND', 'Upload not found');
    }

    public function tempPath(array $upload): string
    {
        $dir = config('UpdateCenter')->uploadTempDir;
        if (! is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        return rtrim($dir, '/') . '/' . $upload['public_id'] . '.part';
    }

    /** Append a tus PATCH chunk. Returns the new offset. */
    public function appendChunk(array $upload, int $offset, $input): int
    {
        if ($upload['status'] !== 'OPEN') {
            throw new ApiException('UPLOAD_CLOSED', 'Upload is no longer open', 409);
        }
        if ($offset !== (int) $upload['offset_bytes']) {
            throw new ApiException('OFFSET_MISMATCH', 'Upload-Offset does not match', 409, ['expected' => (int) $upload['offset_bytes']]);
        }
        $path = $this->tempPath($upload);
        $out  = fopen($path, $offset === 0 ? 'wb' : 'ab');
        if (! flock($out, LOCK_EX)) {
            throw new ApiException('UPLOAD_LOCKED', 'Upload is being written by another request', 409);
        }
        clearstatcache(true, $path);
        if ((int) @filesize($path) !== $offset && $offset !== 0) {
            ftruncate($out, $offset);
        }
        $max     = (int) $upload['size_bytes'] - $offset;
        $written = 0;
        while (! feof($input) && $written <= $max) {
            $buf = fread($input, 1 << 20);
            if ($buf === false || $buf === '') {
                break;
            }
            $written += fwrite($out, $buf);
        }
        flock($out, LOCK_UN);
        fclose($out);
        if ($written > $max) {
            throw new ApiException('UPLOAD_TOO_LARGE', 'More bytes than Upload-Length', 413);
        }
        $newOffset = $offset + $written;
        db_connect()->table('uploads')->where('id', $upload['id'])->update(['offset_bytes' => $newOffset, 'updated_at' => Catalog::now()]);
        if ($newOffset === (int) $upload['size_bytes']) {
            $this->finalizeTus(db_connect()->table('uploads')->where('id', $upload['id'])->get()->getRowArray());
        }

        return $newOffset;
    }

    private function finalizeTus(array $upload): void
    {
        $provider = StorageFactory::fromRow(Catalog::storageById((int) $upload['storage_provider_id']));
        $provider->putFile($upload['quarantine_key'], $this->tempPath($upload));
        @unlink($this->tempPath($upload));
        $this->createRevision($upload);
    }

    public function completeMultipart(array $release, string $packageId, array $in): array
    {
        $upload = $this->upload((string) ($in['upload_id'] ?? ''));
        $pkg    = $this->find($release, $packageId);
        if ((int) $upload['release_package_id'] !== (int) $pkg['id'] || $upload['protocol'] !== 'multipart' || $upload['status'] !== 'OPEN') {
            throw new ApiException('UPLOAD_MISMATCH', 'Upload does not belong to this package or is closed', 409);
        }
        $parts = array_map(static fn ($p) => ['PartNumber' => (int) ($p['part_number'] ?? 0), 'ETag' => (string) ($p['etag'] ?? '')], (array) ($in['parts'] ?? []));
        if ($parts === []) {
            throw ApiException::validation(['parts' => 'Parts with ETags are required']);
        }
        $provider = StorageFactory::fromRow(Catalog::storageById((int) $upload['storage_provider_id']));
        $provider->completeMultipart($upload['quarantine_key'], $upload['multipart_upload_id'], $parts);
        $head = $provider->head($upload['quarantine_key']);
        if ($head === null || (int) $head['size'] !== (int) $upload['size_bytes']) {
            throw new ApiException('UPLOAD_SIZE_MISMATCH', 'Uploaded object size does not match the declared size', 422);
        }
        $this->createRevision($upload);

        return $this->present(db_connect()->table('release_packages')->where('id', $pkg['id'])->get()->getRowArray());
    }

    private function createRevision(array $upload): void
    {
        $db   = db_connect();
        $now  = Catalog::now();
        $next = (int) $db->table('release_package_revisions')->selectMax('revision_number')->where('release_package_id', $upload['release_package_id'])->get()->getRow('revision_number') + 1;
        $pipe = [];
        foreach (self::PIPELINE as $step) {
            $pipe[] = ['step' => $step, 'status' => in_array($step, ['upload', 'quarantine'], true) ? 'done' : 'pending', 'detail' => $step === 'quarantine' ? 'Stored under _incoming/ — not publicly addressable' : null, 'at' => in_array($step, ['upload', 'quarantine'], true) ? gmdate('Y-m-d\TH:i:s\Z') : null];
        }
        $db->table('release_package_revisions')->insert([
            'public_id' => Ulid::prefixed('rev'), 'release_package_id' => $upload['release_package_id'], 'revision_number' => $next,
            'storage_provider_id' => $upload['storage_provider_id'], 'quarantine_key' => $upload['quarantine_key'], 'file_name' => $upload['file_name'],
            'size_bytes' => $upload['size_bytes'], 'pipeline' => json_encode($pipe), 'status' => 'PROCESSING', 'replace_reason' => $upload['reason'],
            'uploaded_by' => $upload['user_id'], 'uploaded_by_client' => $upload['api_client_id'], 'uploaded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $revId = (int) $db->insertID();
        $db->table('uploads')->where('id', $upload['id'])->update(['status' => 'COMPLETE', 'updated_at' => $now]);
        $db->table('release_packages')->where('id', $upload['release_package_id'])->update(['status' => 'PROCESSING', 'updated_at' => $now]);
        Queue::push(ProcessPackageRevisionJob::class, ['revision_id' => $revId], 'processing', 0, 3);
    }

    public function find(array $release, string $publicId): array
    {
        return db_connect()->table('release_packages')->where('release_id', $release['id'])->where('public_id', $publicId)->get()->getRowArray()
            ?? throw ApiException::notFound('PACKAGE_NOT_FOUND', 'Package not found');
    }

    /** FR-UC-PKG-05: delete only before publication, with MFA + reason (controller). */
    public function delete(array $release, array $package, string $reason, string $confirmName): void
    {
        if (! in_array($release['status'], ['DRAFT', 'UPLOADING', 'READY_FOR_TEST', 'TESTING'], true)) {
            throw new ApiException('RELEASE_IMMUTABLE', 'Files can only be deleted before publication', 409);
        }
        $db   = db_connect();
        $revs = $db->table('release_package_revisions')->where('release_package_id', $package['id'])->get()->getResultArray();
        $active = null;
        foreach ($revs as $r) {
            if ((int) $r['id'] === (int) $package['active_revision_id']) {
                $active = $r;
            }
        }
        $name = $active['file_name'] ?? ($revs ? end($revs)['file_name'] : '');
        if ($confirmName !== $name) {
            throw ApiException::validation(['confirm_file_name' => 'Type the file name exactly to confirm']);
        }
        foreach ($revs as $r) {
            try {
                $prov = StorageFactory::fromRow(Catalog::storageById((int) $r['storage_provider_id']));
                foreach ([$r['storage_object_key'], $r['quarantine_key']] as $k) {
                    if ($k) {
                        $prov->delete($k);
                    }
                }
            } catch (\Throwable) {
            }
        }
        $db->table('release_package_revisions')->where('release_package_id', $package['id'])->delete();
        $db->table('release_packages')->where('id', $package['id'])->delete();
        service('audit')->record('release.file_deleted', 'release', $release['public_id'], ['package' => $package['public_id'], 'file' => $name, 'sha256' => $active['sha256'] ?? null], null, $reason);
    }

    public function present(array $package): array
    {
        $db   = db_connect();
        $revs = $db->table('release_package_revisions')->where('release_package_id', $package['id'])->orderBy('revision_number', 'DESC')->get()->getResultArray();
        $fmt  = static fn (array $r) => [
            'id'               => $r['public_id'],
            'revision_number'  => (int) $r['revision_number'],
            'file_name'        => $r['file_name'],
            'size'             => (int) $r['size_bytes'],
            'sha256'           => $r['sha256'],
            'status'           => $r['status'],
            'reject_code'      => $r['reject_code'],
            'signature_status' => $r['signature_status'],
            'signature_detail' => json_decode((string) $r['signature_detail'], true),
            'scan_status'      => $r['scan_status'],
            'scan_detail'      => $r['scan_detail'],
            'attestation_match' => $r['attestation_match'],
            'metadata'         => json_decode((string) $r['metadata'], true) ?: new \stdClass(),
            'pipeline'         => json_decode((string) $r['pipeline'], true) ?: [],
            'replace_reason'   => $r['replace_reason'],
            'uploaded_by'      => $r['uploaded_by'] ? Catalog::userLabel((int) $r['uploaded_by']) : ($r['uploaded_by_client'] ? 'CI' : null),
            'uploaded_at'      => Catalog::iso($r['uploaded_at']),
        ];
        $active = null;
        foreach ($revs as $r) {
            if ((int) $r['id'] === (int) $package['active_revision_id']) {
                $active = $fmt($r);
            }
        }
        // While a new revision processes, surface it as "active" for the live pipeline.
        if ($revs && $revs[0]['status'] === 'PROCESSING') {
            $processing = $fmt($revs[0]);
        }

        return [
            'id'                 => $package['public_id'],
            'platform'           => $package['platform'],
            'architecture'       => $package['architecture'],
            'package_type'       => $package['package_type'],
            'edition'            => $package['edition'] === '' ? null : $package['edition'],
            'scope'              => $package['scope'],
            'status'             => $package['status'],
            'health'             => $package['health'],
            'minimum_os_version' => $package['minimum_os_version'],
            'active_revision'    => $active,
            'processing_revision' => $processing ?? null,
            'revisions'          => array_map($fmt, $revs),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listForRelease(array $release): array
    {
        $rows = db_connect()->table('release_packages')->where('release_id', $release['id'])->orderBy('platform')->orderBy('architecture')->orderBy('package_type')->orderBy('edition')->get()->getResultArray();

        return array_map([$this, 'present'], $rows);
    }

    /** Final object key: worksuite-releases/<app>/<channel>/<version>/<platform>/[revN/]<file> (§4.4). */
    public static function objectKey(array $release, array $package, array $revision): string
    {
        $app     = Catalog::appById((int) $release['application_id']);
        $channel = strtolower(Catalog::channelById((int) $release['channel_id'])['code']);
        $rev     = (int) $revision['revision_number'] > 1 ? 'rev' . $revision['revision_number'] . '/' : '';
        $edition = $package['edition'] !== '' ? strtolower($package['edition']) . '/' : '';
        $arch    = in_array($package['architecture'], ['any', 'universal'], true) ? '' : $package['architecture'] . '/';

        return "{$app['slug']}/{$channel}/{$release['version']}/{$package['platform']}/{$arch}{$edition}{$rev}{$revision['file_name']}";
    }
}

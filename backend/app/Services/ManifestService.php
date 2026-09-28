<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\CanonicalJson;
use App\Libraries\Semver;
use App\Libraries\Signing\Ed25519;
use App\Libraries\Signing\SigningRefusedException;

/**
 * Builds release and channel manifests (§10.4, FR-UC-MAN-01..05) and has them
 * signed by the Signing Service. Package URLs are never embedded; clients get
 * bytes through download grants and verify hashes from the signed manifest.
 */
class ManifestService
{
    /** Build the (unsigned) release manifest document. */
    public function buildRelease(array $release): array
    {
        $db       = db_connect();
        $app      = Catalog::appById((int) $release['application_id']);
        $channel  = Catalog::channelById((int) $release['channel_id']);
        $packages = [];
        $rows     = $db->table('release_packages p')->select('p.*, r.public_id AS rev_public_id, r.revision_number, r.file_name, r.size_bytes, r.sha256, r.signature_detail, r.metadata')
            ->join('release_package_revisions r', 'r.id = p.active_revision_id')
            ->where('p.release_id', $release['id'])->where('r.status', 'READY')->orderBy('p.platform')->orderBy('p.architecture')->orderBy('p.package_type')->orderBy('p.edition')
            ->get()->getResultArray();
        foreach ($rows as $r) {
            $sig  = json_decode((string) $r['signature_detail'], true) ?: [];
            $meta = json_decode((string) $r['metadata'], true) ?: [];
            $pkg  = [
                'package_id'  => $r['public_id'],
                'revision_id' => $r['rev_public_id'],
                'revision'    => (int) $r['revision_number'],
                'platform'    => $r['platform'],
                'arch'        => $r['architecture'],
                'type'        => $r['package_type'],
                'file_name'   => $r['file_name'],
                'size'        => (int) $r['size_bytes'],
                'sha256'      => $r['sha256'],
                'min_os'      => $r['minimum_os_version'],
                'signature'   => ['kind' => $sig['kind'] ?? null, 'signer' => $sig['signer'] ?? null],
            ];
            if ($r['edition'] !== '') {
                $pkg['edition']             = strtolower($r['edition']);
                $pkg['scope']               = $r['scope'];
                $pkg['package_json_sha256'] = isset($meta['package_json']) ? hash('sha256', CanonicalJson::encode($meta['package_json'])) : null;
            }
            $packages[] = $pkg;
        }
        $attestation = $db->table('build_attestations')->where('release_id', $release['id'])->where('verified', 1)->orderBy('id', 'DESC')->get()->getRowArray();
        $doc         = [
            'schema'                    => 2,
            'type'                      => 'release',
            'app'                       => $app['app_identifier'],
            'release_id'                => $release['public_id'],
            'version'                   => $release['version'],
            'build'                     => (int) $release['build_number'],
            'build_revision'            => (int) $release['build_revision'],
            'channel'                   => strtolower($channel['code']),
            'release_type'              => $release['release_type'],
            'mandatory'                 => (bool) $release['mandatory'],
            'mandatory_after'           => Catalog::iso($release['mandatory_after']),
            'grace_minutes'             => $release['grace_minutes'] === null ? null : (int) $release['grace_minutes'],
            'minimum_supported_version' => $release['minimum_supported_version'],
            'rollback_supported'        => (bool) $release['rollback_supported'],
            'migration_compatibility'   => json_decode((string) $release['migration_compatibility'], true),
            'notes_url'                 => rtrim(config('UpdateCenter')->publicBaseUrl, '/') . '/apps/' . $app['slug'] . '/releases/' . $release['version'],
            'notes_digest'              => (new ChangelogService())->digest((int) $release['id']),
            'attestation_sha256'        => $attestation ? hash('sha256', $attestation['payload']) : null,
            'packages'                  => $packages,
            'generated_at'              => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        if ($app['kind'] === 'SERVER') {
            $meta = $db->table('release_server_meta')->where('release_id', $release['id'])->get()->getRowArray();
            if ($meta) {
                $doc['server'] = [
                    'min_from_version' => $meta['min_from_version'],
                    'desktop_compat'   => $meta['desktop_compat'],
                    'requires'         => json_decode((string) $meta['requires'], true),
                    'migrations'       => json_decode((string) $meta['migrations'], true),
                ];
            }
        }

        return $doc;
    }

    /** Evidence handed to the Signing Service: attestation + approvals. */
    public function evidence(array $release): array
    {
        $db  = db_connect();
        $att = $db->table('build_attestations')->where('release_id', $release['id'])->orderBy('id', 'DESC')->get()->getRowArray();
        $approvals = array_map(static fn ($a) => ['kind' => $a['kind'], 'decision' => $a['decision'], 'user_id' => (string) $a['user_id'], 'at' => Catalog::iso($a['created_at'])],
            $db->table('release_approvals')->where('release_id', $release['id'])->orderBy('id')->get()->getResultArray());

        return [
            'attestation' => $att ? ['payload' => json_decode($att['payload'], true), 'signature' => $att['signature'], 'key_id' => $att['key_id']] : null,
            'approvals'   => $approvals,
        ];
    }

    /**
     * Sign and store the release manifest. Throws SIGNING_REFUSED (409).
     */
    public function signRelease(array $release, ?string $reason = null): array
    {
        $doc = $this->buildRelease($release);
        try {
            $signed = service('signingClient')->sign('RELEASE', $doc, $this->evidence($release));
        } catch (SigningRefusedException $e) {
            service('audit')->record('manifest.sign_refused', 'release', $release['public_id'], null, ['reasons' => $e->reasons]);

            throw new ApiException('SIGNING_REFUSED', 'Signing Service refused: ' . implode('; ', $e->reasons), 409, ['reasons' => $e->reasons]);
        }
        $valid = Ed25519::verifyDocument($signed, KeyRegistry::releaseKeys());
        if (! $valid) {
            throw new ApiException('SIGNING_REFUSED', 'Manifest signature failed verification after signing', 409);
        }

        return $this->store($release['id'], (int) $release['application_id'], (int) $release['channel_id'], 'RELEASE', $signed, $reason);
    }

    /** Offered current release on a channel for the channel manifest (§4.3 pause fallback). */
    public function currentRelease(int $appId, int $channelId): ?array
    {
        $rows = db_connect()->table('releases')->where('application_id', $appId)->where('channel_id', $channelId)
            ->whereIn('status', ['PUBLISHED', 'SUPERSEDED'])->where('support_state !=', 'BLOCKED')
            ->orderBy('version_sort', 'DESC')->orderBy('build_number', 'DESC')->get()->getResultArray();
        foreach ($rows as $r) {
            if ($r['status'] === 'PUBLISHED') {
                return $r;
            }
        }

        return $rows[0] ?? null;
    }

    public function buildChannel(int $appId, int $channelId): array
    {
        $db      = db_connect();
        $app     = Catalog::appById($appId);
        $channel = Catalog::channelById($channelId);
        $current = $this->currentRelease($appId, $channelId);
        $min     = (new MinimumVersionService())->channelMinimum($appId, $channelId, null);
        $doc     = [
            'schema'                    => 2,
            'type'                      => 'channel',
            'app'                       => $app['app_identifier'],
            'channel'                   => strtolower($channel['code']),
            'generated_at'              => gmdate('Y-m-d\TH:i:s\Z'),
            'minimum_supported_version' => $min['minimum_version'] ?? null,
            'minimum_by_platform'       => (new MinimumVersionService())->platformMinimums($appId, $channelId),
            'current'                   => null,
            'withdrawn'                 => array_column($db->table('releases')->select('version')->where('application_id', $appId)->where('channel_id', $channelId)->where('status', 'WITHDRAWN')->orderBy('version_sort')->get()->getResultArray(), 'version'),
            'blocked_versions'          => array_column($db->table('releases')->select('version')->where('application_id', $appId)->where('support_state', 'BLOCKED')->orderBy('version_sort')->get()->getResultArray(), 'version'),
        ];
        if ($current !== null) {
            $rm = $this->latestSigned((int) $current['id']);
            if ($rm === null) {
                throw new ApiException('MANIFEST_MISSING', 'Current release has no signed manifest', 409);
            }
            $platforms = [];
            foreach (json_decode($rm['manifest_json'], true)['packages'] as $p) {
                $key                = $p['platform'] . '-' . $p['arch'];
                $platforms[$key][]  = $p['type'] . (isset($p['edition']) ? ':' . $p['edition'] : '');
            }
            $doc['current'] = [
                'version'                 => $current['version'],
                'build'                   => (int) $current['build_number'],
                'release_id'              => $current['public_id'],
                'release_type'            => $current['release_type'],
                'mandatory'               => (bool) $current['mandatory'],
                'mandatory_after'         => Catalog::iso($current['mandatory_after']),
                'published_at'            => Catalog::iso($current['published_at']),
                'release_manifest_sha256' => hash('sha256', CanonicalJson::encode(json_decode($rm['manifest_json'], true))),
                'platforms'               => $platforms,
            ];
        }

        return $doc;
    }

    public function signChannel(int $appId, int $channelId, ?string $reason = null): array
    {
        $doc      = $this->buildChannel($appId, $channelId);
        $evidence = [];
        if ($doc['current'] !== null) {
            $rm                           = $this->latestSigned(Catalog::release($doc['current']['release_id'])['id']);
            $evidence['release_manifest'] = json_decode($rm['manifest_json'], true);
        }
        try {
            $signed = service('signingClient')->sign('CHANNEL', $doc, $evidence);
        } catch (SigningRefusedException $e) {
            throw new ApiException('SIGNING_REFUSED', 'Signing Service refused channel manifest: ' . implode('; ', $e->reasons), 409, ['reasons' => $e->reasons]);
        }
        $row = $this->store(null, $appId, $channelId, 'CHANNEL', $signed, $reason);
        cache()->delete('uc_chman_' . $appId . '_' . $channelId);
        UpdateResolverService::bumpCacheEpoch();

        return $row;
    }

    private function store(?int $releaseId, int $appId, int $channelId, string $kind, array $signed, ?string $reason): array
    {
        $db    = db_connect();
        $now   = Catalog::now();
        $prevQ = $db->table('release_manifests')->where('kind', $kind)->where('application_id', $appId)->where('channel_id', $channelId)->where('superseded_at', null);
        if ($releaseId !== null) {
            $prevQ->where('release_id', $releaseId);
        }
        $prev    = $prevQ->orderBy('id', 'DESC')->get()->getRowArray();
        $version = $prev ? (int) $prev['manifest_version'] + 1 : 1;
        if ($prev) {
            $db->table('release_manifests')->where('id', $prev['id'])->update(['superseded_at' => $now]);
        }
        $json = CanonicalJson::encode($signed);
        $db->table('release_manifests')->insert([
            'release_id'       => $releaseId,
            'application_id'   => $appId,
            'channel_id'       => $channelId,
            'kind'             => $kind,
            'manifest_version' => $version,
            'manifest_json'    => $json,
            'sha256'           => hash('sha256', $json),
            'key_id'           => $signed['key_id'] ?? null,
            'signature'        => $signed['signature'] ?? null,
            'signature_valid'  => Ed25519::verifyDocument($signed, KeyRegistry::releaseKeys()) ? 1 : 0,
            'generated_at'     => Catalog::toDb($signed['generated_at'] ?? null) ?? $now,
            'published_at'     => $now,
            'reason'           => $reason,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return $db->table('release_manifests')->where('id', $db->insertID())->get()->getRowArray();
    }

    public function latestSigned(int $releaseId): ?array
    {
        return db_connect()->table('release_manifests')->where('release_id', $releaseId)->where('kind', 'RELEASE')->where('signature IS NOT NULL')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
    }

    public function latestChannel(int $appId, int $channelId): ?array
    {
        return db_connect()->table('release_manifests')->where('application_id', $appId)->where('channel_id', $channelId)->where('kind', 'CHANNEL')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
    }

    /** Admin view (FR-UC-MAN-04). */
    public function info(array $release): array
    {
        $db   = db_connect();
        $fmt  = static fn (?array $m) => $m === null ? null : [
            'id'               => (int) $m['id'],
            'manifest_version' => (int) $m['manifest_version'],
            'key_id'           => $m['key_id'],
            'signature_valid'  => (bool) $m['signature_valid'],
            'sha256'           => $m['sha256'],
            'generated_at'     => Catalog::iso($m['generated_at']),
            'published_at'     => Catalog::iso($m['published_at']),
            'json'             => json_decode($m['manifest_json'], true),
        ];
        $release_m = $this->latestSigned((int) $release['id']);
        $draft     = null;
        if ($release_m === null) {
            try {
                $draft = $this->buildRelease($release);
            } catch (\Throwable) {
            }
        }
        $history = $db->table('release_manifests')->groupStart()->where('release_id', $release['id'])->orGroupStart()->where('kind', 'CHANNEL')->where('application_id', $release['application_id'])->where('channel_id', $release['channel_id'])->groupEnd()->groupEnd()
            ->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();

        return [
            'release' => $fmt($release_m),
            'draft'   => $draft,
            'channel' => $fmt($this->latestChannel((int) $release['application_id'], (int) $release['channel_id'])),
            'history' => array_map(static fn ($h) => ['id' => (int) $h['id'], 'kind' => $h['kind'], 'manifest_version' => (int) $h['manifest_version'], 'key_id' => $h['key_id'], 'generated_at' => Catalog::iso($h['generated_at']), 'published_at' => Catalog::iso($h['published_at']), 'superseded_at' => Catalog::iso($h['superseded_at']), 'reason' => $h['reason']], $history),
        ];
    }

    /** Flat diff for the regenerate dialog (§7.6 "Diff view"). */
    public static function diff(array $before, array $after, string $prefix = ''): array
    {
        $out  = [];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        foreach ($keys as $k) {
            if (in_array($k, ['signature', 'generated_at'], true)) {
                continue;
            }
            $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            $b    = $before[$k] ?? null;
            $a    = $after[$k] ?? null;
            if (is_array($b) && is_array($a)) {
                $out = array_merge($out, self::diff($b, $a, $path));
            } elseif ($b !== $a) {
                $out[] = ['path' => $path, 'before' => $b, 'after' => $a];
            }
        }

        return $out;
    }
}

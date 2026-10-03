<?php

namespace App\Services;

use App\Exceptions\ApiException;

/**
 * Mirror feed for self-hosted / air-gapped tenants (§2.5 mirror mode,
 * Phase 5). The tenant pulls the signed channel manifest, the signed release
 * manifests (byte-for-byte as stored) and a download grant per package, copies
 * the packages into its own object storage and serves them. Desktops keep
 * verifying the same release-key signatures and SHA-256 hashes, so a mirror
 * cannot alter what is installed.
 *
 * Licensed server editions (BUSINESS / ENTERPRISE) are never mirrored this
 * way: they stay behind the installation license (AC-C47).
 */
class MirrorService
{
    private const GRANT_TTL = 7 * 86400;

    public function index(string $appRef, string $channelCode, int $keep = 3, ?string $tenantRef = null): array
    {
        $app     = Catalog::app($appRef);
        $channel = Catalog::channel($channelCode, (int) $app['id']);
        if (! $channel['is_public'] && ! in_array($channel['code'], ['STABLE', 'BETA'], true)) {
            throw ApiException::forbidden('This channel cannot be mirrored');
        }
        $ms         = new ManifestService();
        $channelMan = $ms->latestChannel((int) $app['id'], (int) $channel['id']);
        if ($channelMan === null) {
            throw ApiException::notFound('RELEASE_NOT_AVAILABLE', 'No manifest published for this channel yet');
        }
        $db       = db_connect();
        $resolver = new UpdateResolverService();
        $releases = $db->table('releases')->where('application_id', $app['id'])->where('channel_id', $channel['id'])
            ->whereIn('status', ['PUBLISHED', 'SUPERSEDED'])->whereNotIn('support_state', ['BLOCKED', 'UNSUPPORTED'])
            ->orderBy('version_sort', 'DESC')->limit(max(1, min(10, $keep)))->get()->getResultArray();

        $out = [];
        foreach ($releases as $r) {
            $rm = $ms->latestSigned((int) $r['id']);
            if ($rm === null) {
                continue;
            }
            $pkgs = $db->table('release_packages p')->select('p.*, r.public_id AS rev_public_id, r.file_name, r.size_bytes, r.sha256')
                ->join('release_package_revisions r', 'r.id = p.active_revision_id')->where('p.release_id', $r['id'])->where('r.status', 'READY')->get()->getResultArray();
            $files = [];
            foreach ($pkgs as $p) {
                if (! in_array(strtoupper((string) ($p['edition'] ?? '')), ['', 'FREE', 'ALL'], true)) {
                    continue; // licensed edition: download with the installation license instead
                }
                $files[] = [
                    'package_id'     => $p['public_id'],
                    'revision_id'    => $p['rev_public_id'],
                    'platform'       => $p['platform'],
                    'arch'           => $p['architecture'],
                    'type'           => $p['package_type'],
                    'edition'        => $p['edition'] !== '' && $p['edition'] !== null ? strtolower((string) $p['edition']) : null,
                    'file_name'      => $p['file_name'],
                    'size'           => (int) $p['size_bytes'],
                    'sha256'         => $p['sha256'],
                    'download_grant' => $resolver->issueGrant($p, $tenantRef, self::GRANT_TTL)['grant'],
                ];
            }
            $out[] = [
                'release_id'       => $r['public_id'],
                'version'          => $r['version'],
                'build'            => (int) $r['build_number'],
                'status'           => $r['status'],
                'support_state'    => $r['support_state'],
                'release_manifest' => json_decode((string) $rm['manifest_json'], true),
                'files'            => $files,
            ];
        }

        return [
            'app'              => $app['slug'],
            'channel'          => strtolower($channel['code']),
            'channel_manifest' => json_decode((string) $channelMan['manifest_json'], true),
            'releases'         => $out,
            'grant_expires_in' => self::GRANT_TTL,
            'redeem'           => '/api/v1/downloads/grant',
            'generated_at'     => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }
}

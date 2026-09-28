<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\Base64Url;
use App\Libraries\CanonicalJson;
use App\Libraries\Semver;
use App\Libraries\Signing\Ed25519;
use App\Libraries\Storage\StorageFactory;
use App\Libraries\Trust\TokenException;

/**
 * Desktop Update API decision (§10.3, FR-UC-API-01..06, WF-C09) and signed
 * download grants. The tenant policy token (TPT) adjusts channel, minimum and
 * holds; the Update Center never calls the tenant.
 */
class UpdateResolverService
{
    public const STATUSES = ['CURRENT', 'UPDATE_AVAILABLE', 'UPDATE_RECOMMENDED', 'UPDATE_REQUIRED', 'UNSUPPORTED', 'RELEASE_WITHDRAWN'];

    public static function bumpCacheEpoch(): void
    {
        cache()->save('uc_resolver_epoch', bin2hex(random_bytes(4)), 86400 * 30);
    }

    private static function epoch(): string
    {
        $e = cache()->get('uc_resolver_epoch');
        if (! is_string($e)) {
            $e = 'init';
            cache()->save('uc_resolver_epoch', $e, 86400 * 30);
        }

        return $e;
    }

    /**
     * Resolve the TPT header into a policy (or defaults). Invalid TPT → defaults
     * and tenant_policy "invalid" (the check is still answered).
     *
     * @return array{state: 'applied'|'absent'|'invalid', payload: ?array, error: ?string}
     */
    public function tenantPolicy(?string $header, string $appIdentifier): array
    {
        if ($header === null || trim($header) === '') {
            return ['state' => 'absent', 'payload' => null, 'error' => null];
        }
        try {
            $p = service('trustChain')->verifyTpt($header);
            if (isset($p['app_id']) && $p['app_id'] !== $appIdentifier) {
                return ['state' => 'invalid', 'payload' => null, 'error' => 'TPT is for a different application'];
            }

            return ['state' => 'applied', 'payload' => $p, 'error' => null];
        } catch (TokenException $e) {
            return ['state' => 'invalid', 'payload' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Channel the device may use: TPT channel (if allowed) → hint when the channel
     * is public → application default (Stable).
     */
    public function resolveChannel(array $app, ?string $hint, ?array $tpt, bool $staffUser = false): array
    {
        $default = Catalog::channelById((int) ($app['default_channel_id'] ?: Catalog::channel('STABLE')['id']));
        $wanted  = strtoupper((string) ($tpt['channel'] ?? $hint ?? $default['code']));
        $allowed = array_map('strtoupper', (array) ($tpt['allowed_channels'] ?? []));
        try {
            $ch = Catalog::channel($wanted, (int) $app['id']);
        } catch (ApiException) {
            return $default;
        }
        $enabled = db_connect()->table('application_channels')->where('application_id', $app['id'])->where('channel_id', $ch['id'])->where('enabled', 1)->countAllResults() > 0;
        if (! $enabled) {
            return $default;
        }
        if ($ch['is_public'] || $staffUser) {
            return $ch;
        }
        if ($tpt !== null && (strtoupper((string) ($tpt['channel'] ?? '')) === $ch['code'] || in_array($ch['code'], $allowed, true))) {
            if ($ch['code'] === 'CUSTOM') {
                $rule = json_decode((string) $ch['access_rule'], true) ?: [];
                if (! in_array($tpt['tenant_id'] ?? '', (array) ($rule['tenants'] ?? []), true)) {
                    return $default;
                }
            }

            return $ch;
        }

        return $default;
    }

    /**
     * Candidate releases newest first (PUBLISHED, then SUPERSEDED fallbacks).
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(int $appId, int $channelId): array
    {
        return db_connect()->table('releases')->where('application_id', $appId)->where('channel_id', $channelId)
            ->whereIn('status', ['PUBLISHED', 'SUPERSEDED'])->where('support_state !=', 'BLOCKED')
            ->orderBy('version_sort', 'DESC')->orderBy('build_number', 'DESC')->limit(20)->get()->getResultArray();
    }

    /** Active READY package for platform/arch/type (falls back to the slot's default type). */
    public function packageFor(array $release, string $platform, string $arch, ?string $type, ?string $edition = null): ?array
    {
        $q = db_connect()->table('release_packages p')->select('p.*, r.public_id AS rev_public_id, r.file_name, r.size_bytes, r.sha256, r.storage_provider_id, r.storage_object_key, r.signature_detail, r.revision_number')
            ->join('release_package_revisions r', 'r.id = p.active_revision_id')
            ->where('p.release_id', $release['id'])->where('p.platform', $platform)->where('r.status', 'READY');
        if ($edition !== null) {
            $q->where('p.edition', $edition);
        } else {
            $q->where('p.architecture', $arch);
        }
        $rows = $q->get()->getResultArray();
        if ($rows === []) {
            return null;
        }
        if ($type !== null) {
            foreach ($rows as $r) {
                if ($r['package_type'] === $type) {
                    return $r;
                }
            }
        }
        $default = null;
        foreach (Catalog::platforms((int) $release['application_id']) as $pl) {
            if ($pl['platform'] === $platform && ($edition !== null || $pl['architecture'] === $arch)) {
                $default = $pl['default_package_type'];
            }
        }
        foreach ($rows as $r) {
            if ($r['package_type'] === $default) {
                return $r;
            }
        }

        return $type === null ? $rows[0] : null;
    }

    /**
     * @param array{app_id:string, current_version:string, build?:int|string, platform:string, architecture:string, install_type?:?string, channel?:?string, device_id?:?string, os_version?:?string} $q
     */
    public function check(array $q, ?string $tptHeader, ?string $licenseHeader, bool $staffUser = false): array
    {
        $app = Catalog::app((string) ($q['app_id'] ?? ''));
        if ($app['status'] === 'RETIRED') {
            throw ApiException::notFound('APP_NOT_FOUND', 'Application retired');
        }
        foreach (['current_version', 'platform', 'architecture'] as $req) {
            if (empty($q[$req])) {
                throw ApiException::validation([$req => 'Required']);
            }
        }
        try {
            $current = Semver::normalize((string) $q['current_version']);
        } catch (\Throwable) {
            throw ApiException::validation(['current_version' => 'Invalid version']);
        }
        $platform = strtolower((string) $q['platform']);
        $arch     = strtolower((string) $q['architecture']);
        $type     = isset($q['install_type']) && $q['install_type'] !== '' ? strtolower((string) $q['install_type']) : null;
        $device   = isset($q['device_id']) ? substr((string) $q['device_id'], 0, 128) : null;

        if ($app['private'] || $app['requires_license_token']) {
            $this->requireLicense($licenseHeader, $app);
        }
        $policy = $this->tenantPolicy($tptHeader, $app['app_identifier']);
        $tpt    = $policy['payload'];
        $channel = $this->resolveChannel($app, $q['channel'] ?? null, $tpt, $staffUser);

        // Cache the decision (FR-UC-API-06); signature + grant are per request.
        $cohortKey = $device ? 'd' . substr(hash('sha256', $device), 0, 16) : 'anon';
        $cacheKey  = 'uc_upd_' . hash('sha256', implode('|', [self::epoch(), $app['id'], $channel['id'], $platform, $arch, $type, $current, $q['os_version'] ?? '', $cohortKey, $tpt ? hash('sha256', CanonicalJson::encode($tpt)) : '-', floor(time() / 60)]));
        $decision  = cache()->get($cacheKey);
        if (! is_array($decision)) {
            $decision = $this->decide($app, $channel, $current, $platform, $arch, $type, $device, $q['os_version'] ?? null, $tpt);
            cache()->save($cacheKey, $decision, config('UpdateCenter')->updateCacheTtl);
        }

        return $this->respond($app, $channel, $current, $decision, $policy, $device);
    }

    private function requireLicense(?string $header, array $app): array
    {
        $token = $header !== null && str_starts_with($header, 'License ') ? substr($header, 8) : null;
        if ($token === null) {
            throw new ApiException('LICENSE_TOKEN_INVALID', 'This application requires a license token', 401);
        }
        try {
            $lic = service('trustChain')->verifyLicenseToken($token);
        } catch (TokenException $e) {
            throw new ApiException('LICENSE_TOKEN_INVALID', 'License token invalid: ' . $e->getMessage(), 401);
        }
        $apps = array_merge((array) ($lic['apps'] ?? []), (array) ($lic['private_access'] ?? []));
        if ($apps !== [] && ! in_array($app['app_identifier'], $apps, true) && ! in_array($app['slug'], $apps, true)) {
            throw new ApiException('LICENSE_TOKEN_INVALID', 'License does not cover this application', 401);
        }

        return $lic;
    }

    /** Pure decision (cacheable). */
    public function decide(array $app, array $channel, string $current, string $platform, string $arch, ?string $type, ?string $device, ?string $osVersion, ?array $tpt): array
    {
        $db      = db_connect();
        $holding = (bool) ($tpt['rollout_hold'] ?? false);
        $offered = null;
        $cohort  = ['stage' => null, 'percentage' => 100, 'in_cohort' => true];
        foreach ($this->candidates((int) $app['id'], (int) $channel['id']) as $r) {
            if ($this->packageFor($r, $platform, $arch, $type) === null) {
                continue;
            }
            if ($r['status'] === 'PUBLISHED') {
                $ro = $db->table('release_rollouts')->where('release_id', $r['id'])->get()->getRowArray();
                $pct = $ro ? ((int) $ro['percentage'] ?: 0) : 100;
                if ($ro && $ro['status'] === 'DONE') {
                    $pct = 100;
                }
                $rules   = $ro ? (new RolloutService())->rules((int) $ro['id']) : [];
                $inRules = RolloutService::matchesRules($rules, ['tenant' => $tpt['tenant_id'] ?? null, 'org' => isset($tpt['org_id']) ? (string) $tpt['org_id'] : null, 'platform' => $platform, 'arch' => $arch, 'current_version' => $current]);
                // Devices already on this version stay "in" regardless of cohort.
                $in = Semver::compare($current, $r['version']) >= 0 || (! $holding && $inRules && RolloutService::inCohort($r['public_id'], $device, $pct));
                if (! $in) {
                    $cohort = ['stage' => $ro ? (int) $ro['stage_no'] : null, 'percentage' => $pct, 'in_cohort' => false];

                    continue; // fall back to the previous release for devices outside the cohort
                }
                $offered = $r;
                $cohort  = ['stage' => $ro ? (int) $ro['stage_no'] : null, 'percentage' => $pct, 'in_cohort' => true];
                break;
            }
            $offered = $r; // SUPERSEDED fallback (paused newest or out of cohort)
            break;
        }

        // Current version facts.
        $curRow = null;
        foreach ($db->table('releases')->where('application_id', $app['id'])->where('version', $current)->orderBy('id', 'DESC')->get()->getResultArray() as $cr) {
            if ($curRow === null || (int) $cr['channel_id'] === (int) $channel['id']) {
                $curRow = $cr;
            }
        }
        $curState = $curRow['support_state'] ?? 'SUPPORTED';

        // Effective minimum = max(release minimum, channel minimum, TPT org minimum).
        $chanMin  = (new MinimumVersionService())->channelMinimum((int) $app['id'], (int) $channel['id'], $platform);
        $orgMin   = $tpt['org_minimum'][$platform] ?? null;
        $effMin   = Semver::max($offered['minimum_supported_version'] ?? null, $chanMin['minimum_version'] ?? null, is_string($orgMin) ? $orgMin : null);

        $mandatoryAfter = $offered['mandatory_after'] ?? null;
        if ($offered && ! empty($tpt['deadline_override'])) {
            $ov = Catalog::toDb((string) $tpt['deadline_override']);
            if ($mandatoryAfter === null || strtotime($ov . ' UTC') < strtotime($mandatoryAfter . ' UTC')) {
                $mandatoryAfter = $ov; // tenant may only bring the deadline earlier
            }
        }
        $newer    = $offered !== null && Semver::compare($offered['version'], $current) > 0;
        $pkg      = $newer ? $this->packageFor($offered, $platform, $arch, $type) : null;
        $message  = null;
        $mandatory = false;

        if ($curRow !== null && $curRow['status'] === 'WITHDRAWN') {
            $status  = 'RELEASE_WITHDRAWN';
            $message = 'Version ' . $current . ' was withdrawn' . ($curRow['withdraw_reason'] ? ': ' . $curRow['withdraw_reason'] : '') . '.';
        } elseif (in_array($curState, ['BLOCKED', 'UNSUPPORTED'], true)) {
            $status  = 'UNSUPPORTED';
            $message = $newer ? "Version {$current} is no longer supported. Please install {$offered['version']}." : "Version {$current} is no longer supported.";
        } elseif ($pkg !== null && $pkg['minimum_os_version'] && $osVersion && $this->osBelow($osVersion, $pkg['minimum_os_version'])) {
            $status  = 'UNSUPPORTED';
            $message = "This update requires {$platform} {$pkg['minimum_os_version']} or later.";
            $pkg     = null;
        } elseif ($effMin !== null && Semver::lt($current, $effMin)) {
            $status  = 'UPDATE_REQUIRED';
            $message = $chanMin['message'] ?? "Version {$effMin} or later is required.";
            $mandatory = true;
        } elseif (! $newer) {
            $status = 'CURRENT';
        } else {
            $type_  = $offered['release_type'];
            $isMand = $type_ === 'MANDATORY' || (bool) $offered['mandatory'];
            if ($type_ === 'CRITICAL') {
                $status    = 'UPDATE_REQUIRED';
                $mandatory = true;
            } elseif ($isMand) {
                $mandatory = true;
                $status    = $mandatoryAfter !== null && strtotime($mandatoryAfter . ' UTC') <= time() ? 'UPDATE_REQUIRED' : 'UPDATE_RECOMMENDED';
            } elseif ($type_ === 'RECOMMENDED' || $curState === 'DEPRECATED') {
                $status = 'UPDATE_RECOMMENDED';
            } else {
                $status = 'UPDATE_AVAILABLE';
            }
        }

        $supersedes = [];
        $highlights = [];
        if ($offered) {
            $supersedes = array_column($db->table('releases')->select('version')->where('superseded_by_release_id', $offered['id'])->orderBy('version_sort', 'DESC')->get()->getResultArray(), 'version');
            $cl         = (new ChangelogService())->get((int) $offered['id'], (string) ($tpt['locale'] ?? 'en'));
            $highlights = $cl ? (json_decode((string) $cl['highlights'], true) ?: []) : [];
        }

        return [
            'status'          => $status,
            'message'         => $message,
            'offered_id'      => $offered['id'] ?? null,
            'mandatory'       => $mandatory,
            'mandatory_after' => $mandatoryAfter,
            'effective_min'   => $effMin,
            'channel_min'     => $chanMin['minimum_version'] ?? null,
            'cohort'          => $cohort,
            'package_id'      => $pkg['id'] ?? null,
            'supersedes'      => $supersedes,
            'highlights'      => $highlights,
        ];
    }

    private function osBelow(string $os, string $min): bool
    {
        return version_compare(preg_replace('/[^0-9.].*$/', '', $os), $min, '<');
    }

    private function respond(array $app, array $channel, string $current, array $d, array $policy, ?string $device): array
    {
        $cfg     = config('UpdateCenter');
        $offered = $d['offered_id'] ? Catalog::releaseById((int) $d['offered_id']) : null;
        $now     = time();
        $package = null;
        if ($d['package_id'] && $offered) {
            $p = db_connect()->table('release_packages p')->select('p.*, r.public_id AS rev_public_id, r.file_name, r.size_bytes, r.sha256, r.storage_provider_id, r.storage_object_key, r.signature_detail')
                ->join('release_package_revisions r', 'r.id = p.active_revision_id')->where('p.id', $d['package_id'])->get()->getRowArray();
            if ($p) {
                $grant   = $this->issueGrant($p, $device);
                $sig     = json_decode((string) $p['signature_detail'], true) ?: [];
                $package = [
                    'id' => $p['public_id'], 'revision_id' => $p['rev_public_id'], 'type' => $p['package_type'], 'size' => (int) $p['size_bytes'], 'sha256' => $p['sha256'],
                    'min_os' => $p['minimum_os_version'], 'signer' => $sig['signer'] ?? $cfg->expectedPublisher, 'file_name' => $p['file_name'],
                    'download_url' => $this->cdnUrl($p), 'download_grant' => $grant['grant'], 'expires_at' => $grant['expires_at'],
                ];
            }
        }
        $doc = [
            'status'                    => $d['status'],
            'app_id'                    => $app['app_identifier'],
            'channel'                   => strtolower($channel['code']),
            'current_version'           => $current,
            'latest_version'            => $offered['version'] ?? null,
            'build'                     => $offered ? (int) $offered['build_number'] : null,
            'release_id'                => $offered['public_id'] ?? null,
            'release_type'              => $offered['release_type'] ?? null,
            'mandatory'                 => $d['mandatory'],
            'mandatory_after'           => Catalog::iso($d['mandatory_after']),
            'grace_minutes'             => $offered && $offered['grace_minutes'] !== null ? (int) $offered['grace_minutes'] : null,
            'minimum_supported_version' => $d['channel_min'],
            'effective_minimum'         => $d['effective_min'],
            'rollout'                   => $d['cohort'],
            'supersedes'                => $d['supersedes'],
            'release_notes_url'         => $offered ? rtrim($cfg->publicBaseUrl, '/') . '/apps/' . $app['slug'] . '/releases/' . $offered['version'] : null,
            'highlights'                => $d['highlights'],
            'package'                   => $package,
            'release_manifest_url'      => $offered ? '/api/v1/releases/' . $offered['public_id'] . '/manifest' : null,
            'message'                   => $d['message'],
            'tenant_policy'             => $policy['state'],
            'issued_at'                 => gmdate('Y-m-d\TH:i:s\Z', $now),
            'expires_at'                => gmdate('Y-m-d\TH:i:s\Z', $now + $cfg->updateResponseTtl),
        ];

        return Ed25519::signDocument($doc, service('responseKeys'), $cfg->responseKeyId);
    }

    private function cdnUrl(array $p): string
    {
        $provider = StorageFactory::fromRow(Catalog::storageById((int) $p['storage_provider_id']));

        return $provider->signedDownloadUrl((string) $p['storage_object_key'], config('UpdateCenter')->downloadUrlTtl, (string) $p['file_name']);
    }

    /** Signed download grant (response key) — exchangeable for a fresh CDN URL. */
    public function issueGrant(array $package, ?string $device, int $ttl = 86400): array
    {
        $cfg     = config('UpdateCenter');
        $exp     = time() + $ttl;
        $payload = ['pkg' => $package['public_id'], 'rev' => $package['rev_public_id'], 'dev' => $device, 'exp' => $exp];
        $body    = Base64Url::encode(CanonicalJson::encode($payload));
        $sig     = Base64Url::encode(service('responseKeys')->sign($cfg->responseKeyId, 'grant.' . $body));

        return ['grant' => 'grant_' . $body . '.' . $sig, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $cfg->downloadUrlTtl)];
    }

    /** POST /downloads/grant — resume after the CDN URL expired. */
    public function redeemGrant(string $grant): array
    {
        $cfg = config('UpdateCenter');
        if (! preg_match('/^grant_([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $grant, $m)) {
            throw new ApiException('GRANT_INVALID', 'Malformed download grant', 401);
        }
        $pub = service('responseKeys')->publicKey($cfg->responseKeyId);
        if (! Ed25519::verify('grant.' . $m[1], Base64Url::decode($m[2]), $pub)) {
            throw new ApiException('GRANT_INVALID', 'Download grant signature invalid', 401);
        }
        $p = json_decode(Base64Url::decode($m[1]), true);
        if (! is_array($p) || ($p['exp'] ?? 0) < time()) {
            throw new ApiException('GRANT_EXPIRED', 'Download grant expired — check for updates again', 401);
        }
        $db  = db_connect();
        $rev = $db->table('release_package_revisions')->where('public_id', (string) ($p['rev'] ?? ''))->get()->getRowArray();
        $pkg = $rev ? $db->table('release_packages')->where('id', $rev['release_package_id'])->where('public_id', (string) ($p['pkg'] ?? ''))->get()->getRowArray() : null;
        $rel = $pkg ? Catalog::releaseById((int) $pkg['release_id']) : null;
        $row = $rel ? $pkg + ['rev_public_id' => $rev['public_id'], 'file_name' => $rev['file_name'], 'size_bytes' => $rev['size_bytes'], 'sha256' => $rev['sha256'], 'storage_provider_id' => $rev['storage_provider_id'], 'storage_object_key' => $rev['storage_object_key'], 'rev_status' => $rev['status'], 'release_status' => $rel['status'], 'support_state' => $rel['support_state']] : null;
        if (! $row || $row['release_status'] === 'WITHDRAWN' || $row['support_state'] === 'BLOCKED' || ! in_array($row['rev_status'], ['READY', 'REPLACED'], true)) {
            throw new ApiException('RELEASE_NOT_AVAILABLE', 'This package is no longer available', 410);
        }

        return ['download_url' => $this->cdnUrl($row), 'sha256' => $row['sha256'], 'size' => (int) $row['size_bytes'], 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $cfg->downloadUrlTtl)];
    }
}

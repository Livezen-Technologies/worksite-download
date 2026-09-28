<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\Semver;
use App\Libraries\Storage\StorageFactory;
use App\Libraries\Trust\TokenException;

/**
 * Stable public URL → package → short-lived CDN URL (§5.3, FR-UC-DLR-01..05,
 * WF-C07/C08/C20). PHP never streams package bytes; it only redirects.
 *
 *   /download/{slug}/{platform}[/{arch}][/{type}]                 latest stable
 *   /download/{slug}/{version}/{platform}/{arch}[/{type}]         version-specific
 *   /download/{slug}/{channel}/{platform}/{arch}[/{type}]         latest on channel
 *   /download/{slug}/{channel}/{version}/{platform}/{arch}[/{type}]
 *   /download/{slug}[/{version}]/server/{edition}                 server edition ZIP
 */
class DownloadResolverService
{
    private const PLATFORMS = ['windows', 'macos', 'linux', 'android', 'ios', 'server'];

    private const CHANNELS = ['stable', 'beta', 'dev', 'internal', 'custom'];

    public function __construct(private readonly AuthContext $viewer)
    {
    }

    /**
     * @param list<string> $segments path segments after /download/
     *
     * @return array{url: string, release: array, package: array}
     */
    public function resolve(array $segments, array $meta): array
    {
        $segments = array_values(array_filter(array_map('strtolower', $segments), 'strlen'));
        if (count($segments) < 2) {
            throw ApiException::notFound('APP_NOT_FOUND', 'Choose an application and platform');
        }
        $app = Catalog::app(array_shift($segments));
        $pub = new PublicCatalogService($this->viewer);
        if (! $app['public_download_enabled'] && ! $this->viewer->isStaff()) {
            throw ApiException::notFound('APP_NOT_FOUND', 'Downloads are not available for this application');
        }

        $channelCode = 'STABLE';
        $version     = null;
        if (in_array($segments[0], self::CHANNELS, true)) {
            $channelCode = strtoupper(array_shift($segments));
        }
        if (isset($segments[0]) && Semver::isValid($segments[0])) {
            $version = array_shift($segments);
        }
        $platform = $segments[0] ?? '';
        if (! in_array($platform, self::PLATFORMS, true)) {
            throw ApiException::notFound('RELEASE_NOT_AVAILABLE', 'Unknown platform');
        }

        // Channel access (§4.2) and private apps (FR-UC-APP-04).
        $license = null;
        if ($channelCode !== 'STABLE' && ! in_array($channelCode, $pub->visibleChannelCodes(), true)) {
            throw new ApiException('CHANNEL_FORBIDDEN', 'Sign in to download from the ' . ucfirst(strtolower($channelCode)) . ' channel', 403);
        }
        if ($app['private'] && ($this->viewer->user === null)) {
            throw new ApiException('CHANNEL_FORBIDDEN', 'Sign in to download this application', 403);
        }
        $channel = Catalog::channel($channelCode, (int) $app['id']);

        $edition = null;
        if ($platform === 'server') {
            $edition = strtoupper($segments[1] ?? 'FREE');
            $arch    = 'any';
            $type    = 'zip';
            $license = $this->checkEditionAccess($edition, $meta['license'] ?? null);
        } else {
            $arch = $segments[1] ?? null;
            $type = $segments[2] ?? null;
            if ($arch === null) {
                foreach (Catalog::platforms((int) $app['id']) as $pl) {
                    if ($pl['platform'] === $platform) {
                        $arch = $pl['architecture'];
                        break;
                    }
                }
            }
            if ($arch === null) {
                throw ApiException::notFound('RELEASE_NOT_AVAILABLE', ucfirst($platform) . ' is not available for ' . $app['name']);
            }
        }

        $resolver = new UpdateResolverService();
        $release  = null;
        $package  = null;
        if ($version !== null) {
            $release = db_connect()->table('releases')->where('application_id', $app['id'])->where('channel_id', $channel['id'])->where('version', $version)->get()->getRowArray();
            if ($release === null || ! in_array($release['status'], ['PUBLISHED', 'SUPERSEDED', 'WITHDRAWN', 'PAUSED'], true)) {
                throw ApiException::notFound('RELEASE_NOT_AVAILABLE', "{$app['name']} {$version} is not available");
            }
            if ($release['support_state'] === 'BLOCKED') {
                throw ApiException::notFound('RELEASE_NOT_AVAILABLE', "{$app['name']} {$version} is not available");
            }
            if ($release['status'] === 'WITHDRAWN' || $release['support_state'] === 'UNSUPPORTED') {
                $latest = (new ManifestService())->currentRelease((int) $app['id'], (int) $channel['id']);

                throw new ApiException('VERSION_UNSUPPORTED', 'Download unavailable.' . ($latest ? " Please install {$latest['version']}." : ''), 410, ['latest' => $latest['version'] ?? null, 'latest_url' => $latest ? '/apps/' . $app['slug'] . '/releases/' . $latest['version'] : null]);
            }
            if ($release['status'] === 'PAUSED') {
                throw ApiException::notFound('RELEASE_NOT_AVAILABLE', 'This release is paused');
            }
            if ($release['support_state'] === 'DEPRECATED' && $this->viewer->user === null) {
                throw new ApiException('CHANNEL_FORBIDDEN', 'Sign in to download deprecated versions', 403);
            }
            $package = $resolver->packageFor($release, $platform, $arch, $type, $edition);
        } else {
            $rows = db_connect()->table('releases')->where('application_id', $app['id'])->where('channel_id', $channel['id'])->whereIn('status', ['PUBLISHED', 'SUPERSEDED'])
                ->where('support_state !=', 'BLOCKED')->where('visibility', 'PUBLIC')->orderBy('version_sort', 'DESC')->limit(20)->get()->getResultArray();
            // Newest PUBLISHED first; when paused, the previous release (§4.3 Pause).
            usort($rows, static fn ($a, $b) => [$a['status'] === 'PUBLISHED' ? 0 : 1, $b['version_sort']] <=> [$b['status'] === 'PUBLISHED' ? 0 : 1, $a['version_sort']]);
            foreach ($rows as $r) {
                if (in_array($r['support_state'], ['UNSUPPORTED'], true)) {
                    continue;
                }
                $p = $resolver->packageFor($r, $platform, $arch, $type, $edition);
                if ($p !== null) {
                    $release = $r;
                    $package = $p;
                    break;
                }
            }
        }
        if ($release === null || $package === null) {
            throw ApiException::notFound('RELEASE_NOT_AVAILABLE', 'No published package for ' . PublicCatalogService::label($platform, $arch, $type ?? 'file', $edition));
        }

        $storage  = Catalog::storageById((int) $package['storage_provider_id']);
        $provider = StorageFactory::fromRow($storage);
        $this->checkHealth($provider, $package, $release, $app, $meta);
        $url = $provider->signedDownloadUrl((string) $package['storage_object_key'], config('UpdateCenter')->downloadUrlTtl, (string) $package['file_name']);
        $this->record($app, $release, $package, $meta, true, null, $license);
        if ($this->viewer->user !== null && $this->viewer->user['kind'] === 'PORTAL') {
            db_connect()->table('portal_download_history')->insert(['user_id' => $this->viewer->user['id'], 'app_name' => $app['name'], 'version' => $release['version'], 'package_label' => PublicCatalogService::label($package['platform'], $package['architecture'], $package['package_type'], $package['edition'] === '' ? null : $package['edition']), 'created_at' => Catalog::now()]);
        }

        return ['url' => $url, 'release' => $release, 'package' => $package];
    }

    /** FR-UC-SRV-12: Free is public; Business/Enterprise require a matching installation license. */
    private function checkEditionAccess(string $edition, ?string $licenseHeader): ?array
    {
        if (! in_array($edition, ['FREE', 'BUSINESS', 'ENTERPRISE', 'ALL'], true)) {
            throw ApiException::notFound('RELEASE_NOT_AVAILABLE', 'Unknown edition');
        }
        if ($edition === 'FREE' || $this->viewer->isStaff()) {
            return null;
        }
        $token = $licenseHeader !== null && str_starts_with($licenseHeader, 'License ') ? substr($licenseHeader, 8) : $licenseHeader;
        if ($token === null || $token === '') {
            throw new ApiException('LICENSE_TOKEN_INVALID', ucfirst(strtolower($edition)) . ' packages require an installation license', 401);
        }
        try {
            $lic = service('trustChain')->verifyLicenseToken($token);
        } catch (TokenException $e) {
            throw new ApiException('LICENSE_TOKEN_INVALID', 'Installation license invalid: ' . $e->getMessage(), 401);
        }
        if (! ServerUpdateService::editionAllows((string) $lic['edition'], $edition)) {
            throw new ApiException('LICENSE_TOKEN_INVALID', "License edition {$lic['edition']} does not include {$edition} packages", 403);
        }

        return $lic;
    }

    /** HEAD probe (cached 5 min) — storage failure never presented as success (WF-C20). */
    private function checkHealth($provider, array $package, array $release, array $app, array $meta): void
    {
        $key = 'uc_head_' . $package['rev_public_id'];
        if (cache()->get($key) === 'ok') {
            return;
        }
        $error = null;
        try {
            $head = $provider->head((string) $package['storage_object_key']);
            if ($head === null) {
                $error = 'Object missing in storage';
            } elseif ((int) $head['size'] !== (int) $package['size_bytes']) {
                $error = 'Stored object size differs from the manifest';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        if ($error === null) {
            cache()->save($key, 'ok', 300);
            if ($package['health'] !== 'OK') {
                db_connect()->table('release_packages')->where('id', $package['id'])->update(['health' => 'OK', 'health_checked_at' => Catalog::now()]);
            }

            return;
        }
        db_connect()->table('release_packages')->where('id', $package['id'])->update(['health' => 'DEGRADED', 'health_checked_at' => Catalog::now()]);
        $this->record($app, $release, $package, $meta, false, 'DISTRIBUTION_DEGRADED');
        if (cache()->get('uc_degraded_alert_' . $package['id']) === null) {
            cache()->save('uc_degraded_alert_' . $package['id'], 1, 3600);
            service('notifier')->notify('distribution.degraded', ['title' => 'Distribution DEGRADED', 'body' => "{$app['name']} {$release['version']} · {$package['file_name']}: {$error}", 'link' => '/admin/storage']);
        }

        throw new ApiException('DISTRIBUTION_DEGRADED', 'This download is temporarily unavailable. Please try again shortly or choose another platform.', 503, ['app' => $app['slug']], ['Retry-After' => '300']);
    }

    /** Coarse, privacy-safe download event (FR-UC-ANL-01/03, NFR-C12). */
    public function record(array $app, ?array $release, ?array $package, array $meta, bool $ok, ?string $error = null, ?array $license = null): void
    {
        $ua    = (string) ($meta['user_agent'] ?? '');
        $isBot = (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|curl\/|wget|python-requests|headless/i', $ua);
        db_connect()->table('download_events')->insert([
            'application_id'    => $app['id'],
            'release_id'        => $release['id'] ?? null,
            'package_id'        => $package['id'] ?? null,
            'revision_id'       => $package['active_revision_id'] ?? null,
            'channel'           => $release ? Catalog::channelById((int) $release['channel_id'])['code'] : null,
            'version'           => $release['version'] ?? null,
            'platform'          => $package['platform'] ?? null,
            'architecture'      => $package['architecture'] ?? null,
            'package_type'      => $package['package_type'] ?? null,
            'country_code'      => isset($meta['country']) && preg_match('/^[A-Z]{2}$/', (string) $meta['country']) ? $meta['country'] : null,
            'referrer_host'     => isset($meta['referrer']) ? substr((string) parse_url((string) $meta['referrer'], PHP_URL_HOST), 0, 191) ?: null : null,
            'user_agent_family' => self::uaFamily($ua),
            'source'            => $meta['source'] ?? 'web',
            'is_bot'            => $isBot ? 1 : 0,
            'redirect_ok'       => $ok ? 1 : 0,
            'error_code'        => $error,
            'created_at'        => Catalog::now(),
        ]);
    }

    public static function uaFamily(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'WorkSuite')                           => 'WorkSuite',
            str_contains($ua, 'Edg/')                                => 'Edge',
            str_contains($ua, 'OPR/')                                => 'Opera',
            str_contains($ua, 'Firefox/')                            => 'Firefox',
            str_contains($ua, 'Chrome/')                             => 'Chrome',
            str_contains($ua, 'Safari/')                             => 'Safari',
            (bool) preg_match('/bot|crawl|spider/i', $ua)            => 'Bot',
            $ua === ''                                               => 'Unknown',
            default                                                  => 'Other',
        };
    }
}

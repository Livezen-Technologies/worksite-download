<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\Markdown\SafeMarkdown;
use App\Libraries\Semver;

/**
 * Public site data (§5, FR-UC-PUB, FR-UC-VER). Shapes follow docs/api-contract.md.
 */
class PublicCatalogService
{
    private const PLATFORM_LABEL = ['windows' => 'Windows', 'macos' => 'macOS', 'linux' => 'Linux', 'android' => 'Android', 'ios' => 'iOS', 'server' => 'Server'];

    public function __construct(private readonly ?AuthContext $viewer = null)
    {
    }

    /** Channels this viewer may see (§4.2 access rules). */
    public function visibleChannelCodes(): array
    {
        // Stable, plus every channel an administrator has made public (Beta,
        // when the owner opens it to everyone).
        $codes = array_values(array_unique(array_merge(['STABLE'], array_column(
            db_connect()->table('release_channels')->select('code')->where('application_id', null)->where('is_public', 1)->get()->getResultArray(),
            'code'
        ))));
        $v     = $this->viewer;
        if ($v !== null && $v->user !== null) {
            if ($v->isStaff()) {
                return ['STABLE', 'BETA', 'DEV', 'INTERNAL', 'CUSTOM'];
            }
            if (($v->user['beta_opt_in'] || in_array('beta_tester', $v->roles, true)) && ! in_array('BETA', $codes, true)) {
                $codes[] = 'BETA';
            }
        }

        return $codes;
    }

    public function canSeeApp(array $app): bool
    {
        if ($app['status'] !== 'ACTIVE' || ! $app['public_download_enabled']) {
            return $this->viewer?->isStaff() ?? false;
        }
        if ($app['private']) {
            return $this->viewer !== null && $this->viewer->user !== null;
        }

        return true;
    }

    public function appSummary(array $app): array
    {
        $db     = db_connect();
        $latest = static function (string $code) use ($app, $db): ?array {
            $ch = $db->table('release_channels')->where('code', $code)->where('application_id', null)->get()->getRowArray();
            if (! $ch) {
                return null;
            }
            $r = (new ManifestService())->currentRelease((int) $app['id'], (int) $ch['id']);
            if ($r === null || $r['visibility'] !== 'PUBLIC') {
                return null;
            }

            return ['release_id' => $r['public_id'], 'version' => $r['version'], 'build' => (int) $r['build_number'], 'published_at' => Catalog::iso($r['published_at']), 'release_date' => Catalog::iso($r['release_date'] ?? $r['published_at'])];
        };

        return [
            'id'                      => $app['public_id'],
            'slug'                    => $app['slug'],
            'name'                    => $app['name'],
            'app_identifier'          => $app['app_identifier'],
            'kind'                    => $app['kind'],
            'description'             => (string) $app['description'],
            'icon'                    => $app['icon_asset'],
            'status'                  => $app['status'],
            'public_download_enabled' => (bool) $app['public_download_enabled'],
            'private'                 => (bool) $app['private'],
            'latest_stable'           => $latest('STABLE'),
            'latest_beta'             => $latest('BETA'),
            'platforms'               => array_map(static fn ($p) => ['platform' => $p['platform'], 'architecture' => $p['architecture'], 'package_types' => $p['package_types'], 'default_package_type' => $p['default_package_type'], 'required_for_publish' => (bool) $p['required_for_publish'], 'min_os_version' => $p['min_os_version']], Catalog::platforms((int) $app['id'])),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function apps(): array
    {
        $rows = db_connect()->table('applications')->where('status', 'ACTIVE')->orderBy('sort_order')->orderBy('name')->get()->getResultArray();

        return array_values(array_map([$this, 'appSummary'], array_filter($rows, fn ($a) => $this->canSeeApp($a) && ! $a['private'])));
    }

    public function app(string $slug): array
    {
        $app = Catalog::app($slug);
        if (! $this->canSeeApp($app)) {
            throw ApiException::notFound('APP_NOT_FOUND', 'Unknown application');
        }
        $stable = Catalog::channel('STABLE');
        $cur    = (new ManifestService())->currentRelease((int) $app['id'], (int) $stable['id']);
        $channels = array_map(static fn ($c) => ['code' => $c['code'], 'name' => $c['name'], 'description' => $c['description'], 'is_public' => (bool) $c['is_public']],
            db_connect()->table('release_channels c')->select('c.*')->join('application_channels ac', 'ac.channel_id = c.id')->where('ac.application_id', $app['id'])->where('ac.enabled', 1)->orderBy('c.sort_order')->get()->getResultArray());

        // The current Beta release, for viewers who may see Beta (everyone once
        // Beta is public): the page offers it beside — or instead of — Stable.
        $beta = null;
        if (in_array('BETA', $this->visibleChannelCodes(), true)) {
            $b = (new ManifestService())->currentRelease((int) $app['id'], (int) Catalog::channel('BETA')['id']);
            if ($b !== null && $b['visibility'] === 'PUBLIC' && ($cur === null || Semver::compare($b['version'], $cur['version']) > 0)) {
                $beta = $this->releaseDetail($app, $b);
            }
        }

        return $this->appSummary($app) + [
            'page'     => $this->downloadPage($app),
            'latest'   => $cur && $cur['visibility'] === 'PUBLIC' ? $this->releaseDetail($app, $cur) : null,
            'beta'     => $beta,
            'channels' => $channels,
        ];
    }

    public function downloadPage(array $app, bool $draft = false): array
    {
        $row  = db_connect()->table('download_pages')->where('application_id', $app['id'])->where('locale', 'en')->get()->getRowArray();
        $data = $row ? [
            'hero' => json_decode((string) $row['hero'], true) ?: [], 'description_md' => (string) $row['description_md'],
            'requirements' => json_decode((string) $row['requirements'], true) ?: [], 'install_help' => json_decode((string) $row['install_help_md'], true) ?: [],
            'faq' => json_decode((string) $row['faq'], true) ?: [], 'support_url' => $row['support_url'],
        ] : ['hero' => ['title' => $app['name'], 'subtitle' => $app['description']], 'description_md' => (string) $app['description'], 'requirements' => [], 'install_help' => [], 'faq' => [], 'support_url' => null];
        if ($draft && $row && $row['draft']) {
            $data = array_merge($data, json_decode($row['draft'], true) ?: []);
        }

        return self::renderPage($data) + ['version' => (int) ($row['version'] ?? 0), 'status' => $draft && $row && $row['draft'] ? 'DRAFT' : 'PUBLISHED'];
    }

    public static function renderPage(array $d): array
    {
        $help = [];
        foreach ((array) $d['install_help'] as $k => $md) {
            $help[$k] = ['markdown' => (string) $md, 'html' => SafeMarkdown::toHtml((string) $md)];
        }

        return [
            'hero'             => ['title' => (string) ($d['hero']['title'] ?? ''), 'subtitle' => (string) ($d['hero']['subtitle'] ?? ''), 'screenshot' => $d['hero']['screenshot'] ?? null],
            'description_md'   => (string) $d['description_md'],
            'description_html' => SafeMarkdown::toHtml((string) $d['description_md']),
            'requirements'     => (array) $d['requirements'],
            'install_help'     => $help ?: new \stdClass(),
            'faq'              => array_map(static fn ($f) => ['q' => (string) ($f['q'] ?? ''), 'a' => (string) ($f['a'] ?? ''), 'a_html' => SafeMarkdown::toHtml((string) ($f['a'] ?? ''))], (array) $d['faq']),
            'support_url'      => $d['support_url'] ?? null,
        ];
    }

    public function releaseSummary(array $app, array $r, ?string $latestVersion = null): array
    {
        $cl = (new ChangelogService())->get((int) $r['id']);

        return [
            'id'            => $r['public_id'],
            'version'       => $r['version'],
            'build'         => (int) $r['build_number'],
            'channel'       => Catalog::channelById((int) $r['channel_id'])['code'],
            'release_type'  => $r['release_type'],
            'support_state' => $r['support_state'],
            'status'        => $r['status'],
            'release_date'  => Catalog::iso($r['release_date'] ?? $r['published_at']),
            'published_at'  => Catalog::iso($r['published_at']),
            'is_latest'     => $latestVersion !== null && $latestVersion === $r['version'],
            'summary'       => $cl['summary'] ?? null,
            'notes_url'     => '/apps/' . $app['slug'] . '/releases/' . $r['version'],
        ];
    }

    /** Releases visible on public pages (published history, not BLOCKED, not HIDDEN). */
    private function publicReleasesQuery(array $app, array $channelCodes)
    {
        $ids = array_column(db_connect()->table('release_channels')->select('id')->whereIn('code', $channelCodes)->get()->getResultArray(), 'id');

        return db_connect()->table('releases')->where('application_id', $app['id'])->whereIn('channel_id', $ids ?: [0])
            ->whereIn('status', ['PUBLISHED', 'PAUSED', 'SUPERSEDED', 'WITHDRAWN'])->where('visibility', 'PUBLIC')->where('support_state !=', 'BLOCKED');
    }

    public function releases(string $slug, ?string $channel, int $limit, int $offset): array
    {
        $app   = Catalog::app($slug);
        $codes = $this->channelFilter($channel);
        $total = $this->publicReleasesQuery($app, $codes)->countAllResults();
        $rows  = $this->publicReleasesQuery($app, $codes)->orderBy('version_sort', 'DESC')->limit($limit, $offset)->get()->getResultArray();
        $latest = $this->latestVersion($app);

        return ['data' => array_map(fn ($r) => $this->releaseSummary($app, $r, $latest), $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    private function channelFilter(?string $channel): array
    {
        $visible = $this->visibleChannelCodes();
        if ($channel === null || $channel === '') {
            return $visible;
        }
        $c = strtoupper($channel);
        if (! in_array($c, $visible, true)) {
            throw new ApiException('CHANNEL_FORBIDDEN', 'This channel requires sign-in', 403);
        }

        return [$c];
    }

    private function latestVersion(array $app): ?string
    {
        $r = (new ManifestService())->currentRelease((int) $app['id'], (int) Catalog::channel('STABLE')['id']);

        return $r['version'] ?? null;
    }

    public function releaseByVersion(string $slug, string $version, ?string $channel): array
    {
        $app = Catalog::app($slug);
        if (! $this->canSeeApp($app)) {
            throw ApiException::notFound('APP_NOT_FOUND', 'Unknown application');
        }
        $rows = $this->publicReleasesQuery($app, $this->channelFilter($channel))->where('version', $version)->get()->getResultArray();
        if ($rows === []) {
            throw ApiException::notFound('RELEASE_NOT_AVAILABLE', "No public release {$version}");
        }
        usort($rows, static fn ($a, $b) => Catalog::channelById((int) $a['channel_id'])['sort_order'] <=> Catalog::channelById((int) $b['channel_id'])['sort_order']);

        return $this->releaseDetail($app, $rows[0]);
    }

    public function releaseDetail(array $app, array $r): array
    {
        $latest    = $this->latestVersion($app);
        $channel   = Catalog::channelById((int) $r['channel_id'])['code'];
        $available = $this->downloadAvailable($r);
        $packages  = [];
        foreach (db_connect()->table('release_packages p')->select('p.*, v.file_name, v.size_bytes, v.sha256')->join('release_package_revisions v', 'v.id = p.active_revision_id')
            ->where('p.release_id', $r['id'])->where('v.status', 'READY')->orderBy('p.platform')->orderBy('p.architecture')->orderBy('p.package_type')->orderBy('p.edition')->get()->getResultArray() as $p) {
            $default = null;
            foreach (Catalog::platforms((int) $app['id']) as $pl) {
                if ($pl['platform'] === $p['platform'] && $pl['architecture'] === $p['architecture']) {
                    $default = $pl['default_package_type'];
                }
            }
            $edition   = $p['edition'] === '' ? null : $p['edition'];
            $packages[] = [
                'id'           => $p['public_id'],
                'platform'     => $p['platform'],
                'architecture' => $p['architecture'],
                'package_type' => $p['package_type'],
                'edition'      => $edition,
                'label'        => self::label($p['platform'], $p['architecture'], $p['package_type'], $edition),
                'file_name'    => $p['file_name'],
                'size'         => (int) $p['size_bytes'],
                'sha256'       => $p['sha256'],
                'min_os'       => $p['minimum_os_version'],
                'download_url' => $edition !== null
                    ? "/download/{$app['slug']}/{$r['version']}/server/" . strtolower($edition)
                    : "/download/{$app['slug']}/" . ($channel === 'STABLE' ? '' : strtolower($channel) . '/') . "{$r['version']}/{$p['platform']}/{$p['architecture']}/{$p['package_type']}",
                'recommended'  => $p['package_type'] === $default,
                'hint'         => $p['package_type'] === 'msi' ? 'IT / Intune' : ($p['package_type'] === 'pkg' ? 'MDM deployment' : ($p['package_type'] === 'zip' && $p['platform'] === 'macos' ? 'Update payload' : null)),
            ];
        }
        $page = $this->downloadPage($app);
        $req  = $page['requirements'];
        $override = json_decode((string) $r['requirements_override'], true);
        if (is_array($override)) {
            $req = array_merge($req, $override);
        }

        return $this->releaseSummary($app, $r, $latest) + [
            'changelog'           => ChangelogService::present((new ChangelogService())->get((int) $r['id'])),
            'packages'            => $packages,
            'checksums_url'       => "/apps/{$app['slug']}/releases/{$r['version']}/checksums.txt",
            'manifest_url'        => "/api/v1/releases/{$r['public_id']}/manifest",
            'download_available'  => $available,
            'unavailable_message' => $available ? null : ($latest ? "Download unavailable. Please install {$latest}." : 'Download unavailable.'),
            'known_issues'        => $r['known_issues'],
            'requirements'        => $req,
            'previous_versions'   => $this->versions($app, 12),
            'app'                 => $this->appSummary($app),
        ];
    }

    public function downloadAvailable(array $r): bool
    {
        if ($r['status'] === 'WITHDRAWN' || in_array($r['support_state'], ['UNSUPPORTED', 'BLOCKED'], true)) {
            return false;
        }

        return in_array($r['status'], ['PUBLISHED', 'SUPERSEDED'], true);
    }

    public function versions(array|string $app, int $limit = 100): array
    {
        $app = is_string($app) ? Catalog::app($app) : $app;
        if (! $app['show_previous_versions']) {
            $limit = 1;
        }
        $latest = $this->latestVersion($app);
        $rows   = $this->publicReleasesQuery($app, $this->visibleChannelCodes())->where('status !=', 'WITHDRAWN')->orderBy('version_sort', 'DESC')->limit($limit)->get()->getResultArray();

        return array_map(fn ($r) => [
            'version'            => $r['version'],
            'build'              => (int) $r['build_number'],
            'channel'            => Catalog::channelById((int) $r['channel_id'])['code'],
            'release_date'       => Catalog::iso($r['release_date'] ?? $r['published_at']),
            'support_state'      => $r['support_state'],
            'download_available' => $this->downloadAvailable($r),
            'notes_url'          => '/apps/' . $app['slug'] . '/releases/' . $r['version'],
            'is_latest'          => $r['version'] === $latest,
        ], $rows);
    }

    public function changelog(?string $slug, int $limit, int $offset): array
    {
        $apps = $slug !== null ? [Catalog::app($slug)] : db_connect()->table('applications')->where('status', 'ACTIVE')->where('public_download_enabled', 1)->where('private', 0)->get()->getResultArray();
        $ids  = array_column($apps, 'id');
        $byId = array_column($apps, null, 'id');
        $chIds = array_column(db_connect()->table('release_channels')->select('id')->whereIn('code', $this->visibleChannelCodes())->get()->getResultArray(), 'id');
        $q     = static fn () => db_connect()->table('releases')->whereIn('application_id', $ids ?: [0])->whereIn('channel_id', $chIds ?: [0])->whereIn('status', ['PUBLISHED', 'PAUSED', 'SUPERSEDED'])->where('visibility', 'PUBLIC')->where('support_state !=', 'BLOCKED');
        $total = $q()->countAllResults();
        $rows  = $q()->orderBy('published_at', 'DESC')->limit($limit, $offset)->get()->getResultArray();
        $data  = [];
        foreach ($rows as $r) {
            $app    = $byId[$r['application_id']];
            $data[] = $this->releaseSummary($app, $r, $this->latestVersion($app)) + ['changelog' => ChangelogService::present((new ChangelogService())->get((int) $r['id'])), 'app' => ['slug' => $app['slug'], 'name' => $app['name']]];
        }

        return ['data' => $data, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    public static function label(string $platform, string $arch, string $type, ?string $edition = null): string
    {
        if ($edition !== null) {
            return 'WorkSuite Server · ' . ucfirst(strtolower($edition === 'ALL' ? 'All editions (core patch)' : $edition)) . ' · ZIP';
        }
        $archLabel = match ([$platform, $arch]) {
            ['macos', 'arm64'] => 'Apple Silicon',
            ['macos', 'x64']   => 'Intel',
            default            => $arch === 'x64' ? 'x64' : ($arch === 'arm64' ? 'ARM64' : $arch),
        };

        return (self::PLATFORM_LABEL[$platform] ?? ucfirst($platform)) . ' ' . $archLabel . ' · ' . ($type === 'appimage' ? 'AppImage' : strtoupper($type));
    }

    public function checksums(string $slug, string $version): string
    {
        $d   = $this->releaseByVersion($slug, $version, null);
        $out = '';
        foreach ($d['packages'] as $p) {
            $out .= $p['sha256'] . '  ' . $p['file_name'] . "\n";
        }

        return $out;
    }

    /** Sort helper used by feeds. */
    public static function semverDesc(array $a, array $b): int
    {
        return Semver::compare($b['version'], $a['version']);
    }
}

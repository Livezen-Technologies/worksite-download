<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Libraries\Markdown\SafeMarkdown;
use App\Libraries\Ulid;
use App\Services\Catalog;
use App\Services\MinimumVersionService;
use App\Services\PublicCatalogService;

/**
 * Applications, the 8-step wizard (§7.2), platforms, channels, minimum
 * versions and the light download-page CMS (§5.4).
 */
class ApplicationsController extends BaseApiController
{
    private const PLATFORMS = ['windows', 'macos', 'linux', 'android', 'ios', 'server'];
    private const ARCHES    = ['x64', 'arm64', 'universal', 'any'];
    private const TYPES     = ['exe', 'msi', 'dmg', 'pkg', 'zip', 'appimage', 'deb', 'rpm', 'apk', 'aab', 'ipa'];

    public function present(array $a): array
    {
        $db       = db_connect();
        $summary  = (new PublicCatalogService($this->auth))->appSummary($a);
        $channels = [];
        foreach ($db->table('release_channels c')->select('c.code, ac.enabled')->join('application_channels ac', 'ac.channel_id = c.id')->where('ac.application_id', $a['id'])->orderBy('c.sort_order')->get()->getResultArray() as $c) {
            $channels[] = ['code' => $c['code'], 'enabled' => (bool) $c['enabled']];
        }
        $manifest = json_decode((string) $a['manifest_settings'], true) ?: [];
        $security = json_decode((string) $a['security_settings'], true) ?: [];
        $cfg      = config('UpdateCenter');

        return $summary + [
            'default_channel'        => $a['default_channel_id'] ? Catalog::channelById((int) $a['default_channel_id'])['code'] : 'STABLE',
            'channels'               => $channels,
            'manifest_settings'      => ['schema' => (int) ($manifest['schema'] ?? 2), 'key_id' => $manifest['key_id'] ?? $cfg->releaseKeyId, 'response_key_id' => $manifest['response_key_id'] ?? $cfg->responseKeyId, 'update_api_enabled' => (bool) ($manifest['update_api_enabled'] ?? true)],
            'security_settings'      => ['private' => (bool) $a['private'], 'requires_license_token' => (bool) $a['requires_license_token'], 'malware_scan_required' => (bool) ($security['malware_scan_required'] ?? true), 'signatures' => $security['signatures'] ?? new \stdClass()],
            'show_previous_versions' => (bool) $a['show_previous_versions'],
            'releases_count'         => $db->table('releases')->where('application_id', $a['id'])->countAllResults(),
            'created_at'             => Catalog::iso($a['created_at']),
        ];
    }

    public function index()
    {
        $rows = db_connect()->table('applications')->orderBy('sort_order')->orderBy('name')->get()->getResultArray();

        return ['data' => array_map([$this, 'present'], $rows)];
    }

    public function show(string $id)
    {
        return $this->present(Catalog::app($id));
    }

    /** @return list<array<string,mixed>> */
    private function validatePlatforms(array $platforms): array
    {
        $out  = [];
        $seen = [];
        foreach ($platforms as $i => $p) {
            $pl   = strtolower((string) ($p['platform'] ?? ''));
            $ar   = strtolower((string) ($p['architecture'] ?? ''));
            $ty   = array_values(array_map('strtolower', (array) ($p['package_types'] ?? [])));
            $def  = ($p['default_package_type'] ?? null) ?: ($ty[0] ?? null);
            if (! in_array($pl, self::PLATFORMS, true) || ! in_array($ar, self::ARCHES, true) || $ty === [] || array_diff($ty, self::TYPES) !== [] || ! in_array($def, $ty, true)) {
                throw ApiException::validation(["platforms.{$i}" => 'Invalid platform, architecture or package types']);
            }
            if (isset($seen[$pl . $ar])) {
                throw ApiException::validation(["platforms.{$i}" => 'Duplicate platform/architecture']);
            }
            $seen[$pl . $ar] = true;
            $out[] = ['platform' => $pl, 'architecture' => $ar, 'package_types' => json_encode($ty), 'required_for_publish' => ! empty($p['required_for_publish']) ? 1 : 0, 'default_package_type' => $def, 'min_os_version' => ($p['min_os_version'] ?? null) ?: null];
        }

        return $out;
    }

    public function create()
    {
        $b      = $this->body();
        $errors = [];
        $name   = trim((string) ($b['name'] ?? ''));
        $slug   = strtolower(trim((string) ($b['slug'] ?? '')));
        $ident  = trim((string) ($b['app_identifier'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Name is required';
        }
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug)) {
            $errors['slug'] = 'Lowercase letters, digits and dashes';
        }
        if (! preg_match('/^[a-z][a-z0-9]*(\.[a-z0-9]+)+$/', $ident)) {
            $errors['app_identifier'] = 'Reverse-DNS identifier, e.g. lk.worksuite.desktop';
        }
        $kind = strtoupper((string) ($b['kind'] ?? 'DESKTOP'));
        if (! in_array($kind, ['DESKTOP', 'SERVER', 'MOBILE', 'OTHER'], true)) {
            $errors['kind'] = 'DESKTOP, SERVER, MOBILE or OTHER';
        }
        $status = strtoupper((string) ($b['status'] ?? 'ACTIVE'));
        if (! in_array($status, ['ACTIVE', 'HIDDEN', 'RETIRED'], true)) {
            $errors['status'] = 'ACTIVE, HIDDEN or RETIRED';
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
        $platforms = $this->validatePlatforms((array) ($b['platforms'] ?? []));
        if ($platforms === []) {
            throw ApiException::validation(['platforms' => 'Select at least one platform']);
        }
        $db = db_connect();
        if ($db->table('applications')->groupStart()->where('slug', $slug)->orWhere('app_identifier', $ident)->groupEnd()->countAllResults() > 0) {
            throw ApiException::conflict('APP_EXISTS', 'An application with this slug or identifier already exists');
        }
        $channels = array_values(array_unique(array_map('strtoupper', (array) ($b['channels'] ?? ['STABLE']))));
        $default  = strtoupper((string) ($b['default_channel'] ?? 'STABLE'));
        if (! in_array($default, $channels, true)) {
            $channels[] = $default;
        }
        $sec = (array) ($b['security_settings'] ?? []);
        $man = (array) ($b['manifest_settings'] ?? []);
        $now = Catalog::now();
        $db->transStart();
        $db->table('applications')->insert([
            'public_id' => Ulid::prefixed('app'), 'name' => $name, 'slug' => $slug, 'app_identifier' => $ident, 'kind' => $kind,
            'description' => (string) ($b['description'] ?? ''), 'icon_asset' => $b['icon'] ?? null, 'status' => $status,
            'public_download_enabled' => array_key_exists('public_download_enabled', $b) ? (int) (bool) $b['public_download_enabled'] : 1,
            'private' => ! empty($sec['private']) ? 1 : 0, 'requires_license_token' => ! empty($sec['requires_license_token']) ? 1 : 0,
            'default_channel_id' => Catalog::channel($default)['id'], 'show_previous_versions' => array_key_exists('show_previous_versions', $b) ? (int) (bool) $b['show_previous_versions'] : 1,
            'manifest_settings' => json_encode(['schema' => (int) ($man['schema'] ?? 2), 'key_id' => $man['key_id'] ?? null, 'response_key_id' => $man['response_key_id'] ?? null, 'update_api_enabled' => (bool) ($man['update_api_enabled'] ?? true)]),
            'security_settings' => json_encode(['malware_scan_required' => (bool) ($sec['malware_scan_required'] ?? true), 'signatures' => $sec['signatures'] ?? []]),
            'sort_order' => (int) $db->table('applications')->selectMax('sort_order')->get()->getRow('sort_order') + 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $appId = (int) $db->insertID();
        foreach ($platforms as $p) {
            $db->table('application_platforms')->insert($p + ['application_id' => $appId, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ($channels as $code) {
            $db->table('application_channels')->insert(['application_id' => $appId, 'channel_id' => Catalog::channel($code)['id'], 'enabled' => 1]);
        }
        $db->table('download_pages')->insert(['application_id' => $appId, 'locale' => 'en', 'hero' => json_encode(['title' => $name, 'subtitle' => (string) ($b['description'] ?? '')]), 'description_md' => (string) ($b['description'] ?? ''), 'requirements' => '{}', 'install_help_md' => '{}', 'faq' => '[]', 'status' => 'PUBLISHED', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $db->transComplete();
        $app = Catalog::appById($appId);
        service('audit')->record('application.created', 'application', $app['public_id'], null, ['name' => $name, 'slug' => $slug, 'app_identifier' => $ident, 'platforms' => count($platforms), 'channels' => $channels]);

        return $this->ok($this->present($app), 201);
    }

    public function update(string $id)
    {
        $a   = Catalog::app($id);
        $b   = $this->body();
        $set = [];
        foreach (['name', 'description', 'icon' => 'icon_asset'] as $k => $col) {
            $key = is_int($k) ? $col : $k;
            if (array_key_exists($key, $b)) {
                $set[$col] = $b[$key];
            }
        }
        if (isset($b['status'])) {
            $s = strtoupper((string) $b['status']);
            if (! in_array($s, ['ACTIVE', 'HIDDEN', 'RETIRED'], true)) {
                throw ApiException::validation(['status' => 'ACTIVE, HIDDEN or RETIRED']);
            }
            $set['status'] = $s;
        }
        foreach (['public_download_enabled', 'show_previous_versions'] as $bool) {
            if (array_key_exists($bool, $b)) {
                $set[$bool] = $b[$bool] ? 1 : 0;
            }
        }
        if (isset($b['security_settings'])) {
            $sec = (array) $b['security_settings'];
            if (array_key_exists('private', $sec)) {
                $set['private'] = $sec['private'] ? 1 : 0;
            }
            if (array_key_exists('requires_license_token', $sec)) {
                $set['requires_license_token'] = $sec['requires_license_token'] ? 1 : 0;
            }
            $cur                      = json_decode((string) $a['security_settings'], true) ?: [];
            $set['security_settings'] = json_encode(array_merge($cur, array_intersect_key($sec, ['malware_scan_required' => 1, 'signatures' => 1])));
        }
        if (isset($b['manifest_settings'])) {
            $set['manifest_settings'] = json_encode(array_merge(json_decode((string) $a['manifest_settings'], true) ?: [], array_intersect_key((array) $b['manifest_settings'], ['schema' => 1, 'key_id' => 1, 'response_key_id' => 1, 'update_api_enabled' => 1])));
        }
        if (isset($b['default_channel'])) {
            $set['default_channel_id'] = Catalog::channel((string) $b['default_channel'])['id'];
        }
        $db = db_connect();
        if ($set !== []) {
            $db->table('applications')->where('id', $a['id'])->update($set + ['updated_at' => Catalog::now()]);
        }
        if (isset($b['platforms'])) {
            $platforms = $this->validatePlatforms((array) $b['platforms']);
            $db->table('application_platforms')->where('application_id', $a['id'])->delete();
            foreach ($platforms as $p) {
                $db->table('application_platforms')->insert($p + ['application_id' => $a['id'], 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
            }
            $set['platforms'] = count($platforms);
        }
        if (isset($b['channels'])) {
            $want = array_map('strtoupper', (array) $b['channels']);
            foreach ($db->table('release_channels')->where('application_id', null)->get()->getResultArray() as $c) {
                $on = in_array($c['code'], $want, true) ? 1 : 0;
                if ($db->table('application_channels')->where(['application_id' => $a['id'], 'channel_id' => $c['id']])->countAllResults()) {
                    $db->table('application_channels')->where(['application_id' => $a['id'], 'channel_id' => $c['id']])->update(['enabled' => $on]);
                } elseif ($on) {
                    $db->table('application_channels')->insert(['application_id' => $a['id'], 'channel_id' => $c['id'], 'enabled' => 1]);
                }
            }
            $set['channels'] = $want;
        }
        service('audit')->record('application.updated', 'application', $a['public_id'], array_intersect_key($a, $set), $set);

        return $this->present(Catalog::appById((int) $a['id']));
    }

    public function minimumVersions(string $id)
    {
        return (new MinimumVersionService())->list((int) Catalog::app($id)['id']);
    }

    public function impact(string $id)
    {
        $g = $this->request->getGet();

        return (new MinimumVersionService())->impact((int) Catalog::app($id)['id'], (string) ($g['channel'] ?? 'STABLE'), ($g['platform'] ?? null) ?: null, (string) ($g['version'] ?? ''));
    }

    public function setMinimumVersion(string $id)
    {
        $b = $this->body();
        $this->reason($b);
        $this->requireStepUp();

        return (new MinimumVersionService())->set((int) Catalog::app($id)['id'], $b, $this->auth->userId());
    }

    public function downloadPage(string $id)
    {
        $a   = Catalog::app($id);
        $pub = new PublicCatalogService($this->auth);
        $row = db_connect()->table('download_pages')->where('application_id', $a['id'])->where('locale', 'en')->get()->getRowArray();

        return [
            'published' => $pub->downloadPage($a),
            'draft'     => $row && $row['draft'] ? $pub->downloadPage($a, true) : null,
            'history'   => array_map(static fn ($h) => ['version' => (int) $h['version'], 'updated_by' => Catalog::userLabel($h['updated_by'] ? (int) $h['updated_by'] : null), 'created_at' => Catalog::iso($h['created_at'])], db_connect()->table('download_page_versions')->where('application_id', $a['id'])->orderBy('version', 'DESC')->limit(20)->get()->getResultArray()),
        ];
    }

    public function saveDownloadPage(string $id)
    {
        $a     = Catalog::app($id);
        $b     = $this->body();
        $draft = [
            'hero'           => ['title' => mb_substr((string) ($b['hero']['title'] ?? ''), 0, 120), 'subtitle' => mb_substr((string) ($b['hero']['subtitle'] ?? ''), 0, 300), 'screenshot' => $b['hero']['screenshot'] ?? null],
            'description_md' => (string) ($b['description_md'] ?? ''),
            'requirements'   => array_map(static fn ($l) => array_values(array_map('strval', (array) $l)), (array) ($b['requirements'] ?? [])),
            'install_help'   => array_map('strval', (array) ($b['install_help'] ?? [])),
            'faq'            => array_values(array_map(static fn ($f) => ['q' => (string) ($f['q'] ?? ''), 'a' => (string) ($f['a'] ?? '')], (array) ($b['faq'] ?? []))),
            'support_url'    => isset($b['support_url']) && preg_match('#^https://#', (string) $b['support_url']) ? (string) $b['support_url'] : null,
        ];
        db_connect()->table('download_pages')->where('application_id', $a['id'])->where('locale', 'en')->update(['draft' => json_encode($draft), 'status' => 'DRAFT', 'updated_by' => $this->auth->userId(), 'updated_at' => Catalog::now()]);
        service('audit')->record('content.draft_saved', 'application', $a['public_id']);

        return $this->downloadPage($id);
    }

    public function publishDownloadPage(string $id)
    {
        $a   = Catalog::app($id);
        $db  = db_connect();
        $row = $db->table('download_pages')->where('application_id', $a['id'])->where('locale', 'en')->get()->getRowArray();
        if (! $row || ! $row['draft']) {
            throw new ApiException('NO_DRAFT', 'There is no draft to publish', 409);
        }
        $d = json_decode($row['draft'], true);
        $db->table('download_page_versions')->insert(['application_id' => $a['id'], 'locale' => 'en', 'version' => $row['version'], 'snapshot' => json_encode(array_intersect_key($row, array_flip(['hero', 'description_md', 'requirements', 'install_help_md', 'faq', 'support_url']))), 'updated_by' => $row['updated_by'], 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        $db->table('download_pages')->where('id', $row['id'])->update([
            'hero' => json_encode($d['hero']), 'description_md' => $d['description_md'], 'requirements' => json_encode($d['requirements']),
            'install_help_md' => json_encode($d['install_help']), 'faq' => json_encode($d['faq']), 'support_url' => $d['support_url'],
            'draft' => null, 'status' => 'PUBLISHED', 'version' => (int) $row['version'] + 1, 'updated_by' => $this->auth->userId(), 'updated_at' => Catalog::now(),
        ]);
        service('audit')->record('content.published', 'application', $a['public_id'], ['version' => (int) $row['version']], ['version' => (int) $row['version'] + 1]);
        \App\Libraries\Queue::push(\App\Jobs\PrerenderJob::class, ['app_id' => (int) $a['id']]);

        return $this->downloadPage($id);
    }

    public function preview()
    {
        return ['html' => SafeMarkdown::toHtml((string) ($this->body()['markdown'] ?? ''))];
    }

    public function channels()
    {
        return ['data' => array_map(static fn ($c) => [
            'id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'description' => $c['description'], 'is_public' => (bool) $c['is_public'],
            'is_default' => (bool) $c['is_default'], 'allow_direct_download' => (bool) $c['allow_direct_download'], 'allow_auto_update' => (bool) $c['allow_auto_update'],
            'access_rule' => json_decode((string) $c['access_rule'], true), 'retention' => json_decode((string) $c['retention'], true),
        ], db_connect()->table('release_channels')->where('application_id', null)->orderBy('sort_order')->get()->getResultArray())];
    }

    public function updateChannel(int $id)
    {
        $c   = Catalog::channelById($id);
        $b   = $this->body();
        $set = [];
        foreach (['name', 'description'] as $k) {
            if (isset($b[$k])) {
                $set[$k] = (string) $b[$k];
            }
        }
        foreach (['allow_direct_download', 'allow_auto_update'] as $k) {
            if (array_key_exists($k, $b)) {
                $set[$k] = $b[$k] ? 1 : 0;
            }
        }
        foreach (['access_rule', 'retention'] as $k) {
            if (isset($b[$k])) {
                $set[$k] = json_encode($b[$k]);
            }
        }
        if ($c['code'] === 'STABLE' && isset($b['is_public']) && ! $b['is_public']) {
            throw ApiException::validation(['is_public' => 'Stable must remain public']);
        }
        db_connect()->table('release_channels')->where('id', $id)->update($set + ['updated_at' => Catalog::now()]);
        Catalog::flush();
        service('audit')->record('channel.updated', 'channel', $c['code'], array_intersect_key($c, $set), $set);

        return $this->channels();
    }

    public function platforms()
    {
        $out = [];
        foreach (db_connect()->table('applications')->orderBy('sort_order')->get()->getResultArray() as $a) {
            foreach (Catalog::platforms((int) $a['id']) as $p) {
                $out[] = ['app_id' => $a['public_id'], 'app_name' => $a['name'], 'platform' => $p['platform'], 'architecture' => $p['architecture'], 'package_types' => $p['package_types'], 'default_package_type' => $p['default_package_type'], 'required_for_publish' => (bool) $p['required_for_publish'], 'min_os_version' => $p['min_os_version']];
            }
        }

        return ['data' => $out];
    }
}

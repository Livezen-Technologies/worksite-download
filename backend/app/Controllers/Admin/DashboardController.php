<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Libraries\Queue;
use App\Services\Catalog;
use App\Services\HealthService;
use App\Services\PublicCatalogService;
use App\Services\RolloutService;
use App\Services\TelemetryService;

/**
 * A1 dashboard (FR-UC-ADM-01), notifications and System › Health (FR-UC-OPS-01).
 */
class DashboardController extends BaseApiController
{
    public function index()
    {
        $db    = db_connect();
        $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $desktop = $db->table('applications')->where('slug', 'desktop')->get()->getRowArray() ?? $db->table('applications')->orderBy('sort_order')->get()->getRowArray();

        $latest = array_map(static function ($r) {
            $app = Catalog::appById((int) $r['application_id']);

            return ['id' => $r['public_id'], 'app_name' => $app['name'], 'app_slug' => $app['slug'], 'version' => $r['version'], 'channel' => Catalog::channelById((int) $r['channel_id'])['code'], 'status' => $r['status']];
        }, $db->table('releases')->orderBy('updated_at', 'DESC')->limit(6)->get()->getResultArray());

        $dist = $desktop ? (new TelemetryService())->distribution((int) $desktop['id'], 'STABLE') : null;

        $downloads = null;
        $lr        = $db->table('releases')->where('status', 'PUBLISHED')->orderBy('published_at', 'DESC')->get()->getRowArray();
        if ($lr) {
            $items = $db->table('download_events')->select('platform, architecture, package_type, COUNT(*) AS c')->where('release_id', $lr['id'])->where('is_bot', 0)->where('redirect_ok', 1)
                ->groupBy('platform')->groupBy('architecture')->groupBy('package_type')->orderBy('c', 'DESC')->limit(6)->get()->getResultArray();
            $downloads = ['release_id' => $lr['public_id'], 'app_name' => Catalog::appById((int) $lr['application_id'])['name'], 'version' => $lr['version'],
                'items' => array_map(static fn ($i) => ['label' => PublicCatalogService::label((string) $i['platform'], (string) $i['architecture'], (string) $i['package_type']), 'count' => (int) $i['c']], $items)];
        }

        $rollouts = [];
        foreach ($db->table('release_rollouts ro')->select('ro.release_id')->join('releases r', 'r.id = ro.release_id')->whereIn('ro.status', ['ACTIVE', 'PAUSED'])->whereIn('r.status', ['PUBLISHED', 'PAUSED'])->get()->getResultArray() as $ro) {
            $r   = Catalog::releaseById((int) $ro['release_id']);
            $p   = (new RolloutService())->present($r);
            $rollouts[] = ['release_id' => $r['public_id'], 'app_name' => Catalog::appById((int) $r['application_id'])['name'], 'version' => $r['version'], 'channel' => Catalog::channelById((int) $r['channel_id'])['code'],
                'stage_no' => $p['stage_no'], 'stages' => $p['stages'], 'percentage' => $p['percentage'], 'success_rate' => (new TelemetryService())->releaseStats((int) $r['id'])['success_rate'], 'status' => $p['status']];
        }

        $health = db_connect()->table('health_checks')->get()->getResultArray();
        if ($health === [] || strtotime(max(array_column($health, 'checked_at')) . ' UTC') < time() - 300) {
            $components = (new HealthService())->run();
        } else {
            $components = array_map(static fn ($h) => ['component' => $h['component'], 'status' => $h['status'], 'latency_ms' => $h['latency_ms'] === null ? null : (int) $h['latency_ms'], 'detail' => $h['detail'], 'checked_at' => Catalog::iso($h['checked_at'])], $health);
        }

        return [
            'environment' => config('UpdateCenter')->environmentName,
            'kpis'        => [
                'apps'                     => $db->table('applications')->where('status !=', 'RETIRED')->countAllResults(),
                'apps_public'              => $db->table('applications')->where('status', 'ACTIVE')->where('public_download_enabled', 1)->where('private', 0)->countAllResults(),
                'releases'                 => $db->table('releases')->countAllResults(),
                'releases_testing'         => $db->table('releases')->whereIn('status', ['READY_FOR_TEST', 'TESTING'])->countAllResults(),
                'releases_draft'           => $db->table('releases')->whereIn('status', ['DRAFT', 'UPLOADING'])->countAllResults(),
                'downloads_30d'            => $db->table('download_events')->where('created_at >=', $since)->where('is_bot', 0)->countAllResults(),
                'downloads_redirect_ok_30d' => $db->table('download_events')->where('created_at >=', $since)->where('is_bot', 0)->where('redirect_ok', 1)->countAllResults(),
                'active_devices'           => $db->table('device_versions')->where('last_seen >=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))->countAllResults(),
            ],
            'latest_releases'      => $latest,
            'version_distribution' => $dist ? ['app_name' => $desktop['name'], 'items' => array_slice(array_map(static fn ($v) => ['version' => $v['version'], 'count' => $v['count'], 'pct' => $v['pct']], $dist['by_version']), 0, 8), 'current_pct' => $dist['current_pct'], 'update_available_pct' => $dist['update_available_pct'], 'unsupported_pct' => $dist['unsupported_pct']] : null,
            'downloads'            => $downloads,
            'active_rollouts'      => $rollouts,
            'recent_activity'      => array_map([AdministrationController::class, 'presentAudit'], $db->table('audit_logs')->whereNotIn('action', ['auth.login', 'auth.logout', 'auth.login_failed'])->orderBy('id', 'DESC')->limit(8)->get()->getResultArray()),
            'health'               => ['ok' => ! in_array('DOWN', array_column($components, 'status'), true), 'components' => $components],
            'notifications_unread' => $this->auth->userId() ? $db->table('notifications')->where('user_id', $this->auth->userId())->where('read_at', null)->countAllResults() : 0,
        ];
    }

    public function notifications()
    {
        $uid = (int) $this->auth->userId();
        $db  = db_connect();

        return [
            'data'   => array_map(static fn ($n) => ['id' => (int) $n['id'], 'kind' => $n['kind'], 'payload' => json_decode((string) $n['payload'], true), 'read_at' => Catalog::iso($n['read_at']), 'created_at' => Catalog::iso($n['created_at'])],
                $db->table('notifications')->where('user_id', $uid)->orderBy('id', 'DESC')->limit(50)->get()->getResultArray()),
            'unread' => $db->table('notifications')->where('user_id', $uid)->where('read_at', null)->countAllResults(),
        ];
    }

    public function read(int $id)
    {
        db_connect()->table('notifications')->where('id', $id)->where('user_id', (int) $this->auth->userId())->update(['read_at' => Catalog::now()]);

        return ['ok' => true];
    }

    public function readAll()
    {
        db_connect()->table('notifications')->where('user_id', (int) $this->auth->userId())->where('read_at', null)->update(['read_at' => Catalog::now()]);

        return ['ok' => true];
    }

    public function health()
    {
        $c = (new HealthService())->run();

        return ['ok' => ! in_array('DOWN', array_column($c, 'status'), true), 'environment' => config('UpdateCenter')->environmentName, 'components' => $c, 'queue' => Queue::stats()];
    }
}

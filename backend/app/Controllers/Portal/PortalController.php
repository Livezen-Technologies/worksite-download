<?php

namespace App\Controllers\Portal;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Services\Catalog;
use App\Services\ManifestService;
use App\Services\PublicCatalogService;

/**
 * User portal for customers, beta testers and partners (§3.3, FR-UC-AUTH-04).
 */
class PortalController extends BaseApiController
{
    public function downloads()
    {
        $pub   = new PublicCatalogService($this->auth);
        $codes = $pub->visibleChannelCodes();
        $out   = [];
        foreach (db_connect()->table('applications')->where('status', 'ACTIVE')->orderBy('sort_order')->get()->getResultArray() as $app) {
            if (! $pub->canSeeApp($app)) {
                continue;
            }
            $latest = [];
            foreach ($codes as $code) {
                $ch = Catalog::channel($code);
                $r  = (new ManifestService())->currentRelease((int) $app['id'], (int) $ch['id']);
                if ($r) {
                    $latest[$code] = $pub->releaseDetail($app, $r);
                }
            }
            if ($latest === []) {
                continue;
            }
            $out[] = $pub->appSummary($app) + ['channels_available' => array_keys($latest), 'latest_by_channel' => $latest];
        }

        return ['apps' => $out];
    }

    public function history()
    {
        $rows = db_connect()->table('portal_download_history')->where('user_id', $this->auth->userId())->orderBy('id', 'DESC')->limit(100)->get()->getResultArray();

        return ['data' => array_map(static fn ($r) => ['app' => $r['app_name'], 'version' => $r['version'], 'package' => $r['package_label'], 'created_at' => Catalog::iso($r['created_at'])], $rows)];
    }

    public function profile()
    {
        $name = trim((string) ($this->body()['name'] ?? ''));
        if ($name === '') {
            throw ApiException::validation(['name' => 'Required']);
        }
        db_connect()->table('users')->where('id', $this->auth->userId())->update(['name' => mb_substr($name, 0, 191), 'updated_at' => Catalog::now()]);

        return ['ok' => true];
    }

    public function beta()
    {
        $on = ! empty($this->body()['opt_in']);
        db_connect()->table('users')->where('id', $this->auth->userId())->update(['beta_opt_in' => $on ? 1 : 0]);
        service('audit')->record('portal.beta_opt_in', 'user', $this->auth->actorId(), null, ['opt_in' => $on]);

        return ['beta_opt_in' => $on];
    }

    public function subscriptions()
    {
        $email = $this->auth->user['email'];
        $rows  = db_connect()->table('subscriptions s')->select('s.channel, s.confirmed_at, a.slug, a.name')->join('applications a', 'a.id = s.application_id')->where('s.email', $email)->get()->getResultArray();

        return ['data' => array_map(static fn ($r) => ['app' => $r['slug'], 'app_name' => $r['name'], 'channel' => $r['channel'], 'enabled' => $r['confirmed_at'] !== null], $rows)];
    }

    public function saveSubscriptions()
    {
        $email = $this->auth->user['email'];
        $db    = db_connect();
        foreach ((array) ($this->body()['items'] ?? []) as $it) {
            $app     = Catalog::app((string) ($it['app'] ?? ''));
            $channel = strtoupper((string) ($it['channel'] ?? 'STABLE'));
            $where   = ['email' => $email, 'application_id' => $app['id'], 'channel' => $channel];
            if (empty($it['enabled'])) {
                $db->table('subscriptions')->where($where)->delete();
            } elseif ($db->table('subscriptions')->where($where)->countAllResults() === 0) {
                // Signed-in, verified account → no second opt-in needed.
                $db->table('subscriptions')->insert($where + ['confirmed_at' => Catalog::now(), 'unsubscribe_token' => bin2hex(random_bytes(20)), 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
            }
        }

        return $this->subscriptions();
    }
}

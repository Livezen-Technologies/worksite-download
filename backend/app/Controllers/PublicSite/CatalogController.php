<?php

namespace App\Controllers\PublicSite;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Libraries\Base64Url;
use App\Services\Catalog;
use App\Services\KeyRegistry;
use App\Services\PublicCatalogService;

/**
 * Public catalog for the download site (§5, FR-UC-PUB, FR-UC-CHG-03).
 */
class CatalogController extends BaseApiController
{
    private function svc(): PublicCatalogService
    {
        return new PublicCatalogService($this->auth);
    }

    private function cached(mixed $data, int $maxAge = 60)
    {
        return $this->ok($data)->setHeader('Cache-Control', ($this->auth->user ? 'private' : 'public') . ', max-age=' . $maxAge);
    }

    public function site()
    {
        return $this->cached(['environment' => config('UpdateCenter')->environmentName, 'name' => 'WorkSuite Downloads', 'version' => \App\Libraries\AppVersion::current(), 'apps' => $this->svc()->apps()]);
    }

    /** Update Center build information (not the versions of distributed apps). */
    public function version()
    {
        return $this->cached(\App\Libraries\AppVersion::info() + ['environment' => config('UpdateCenter')->environmentName], 300);
    }

    public function apps()
    {
        return $this->cached(['data' => $this->svc()->apps()]);
    }

    public function app(string $slug)
    {
        return $this->cached($this->svc()->app($slug));
    }

    public function releases(string $slug)
    {
        [$limit, $offset] = $this->paging(20);

        return $this->cached($this->svc()->releases($slug, $this->request->getGet('channel'), $limit, $offset));
    }

    public function release(string $slug, string $version)
    {
        return $this->cached($this->svc()->releaseByVersion($slug, $version, $this->request->getGet('channel')));
    }

    public function versions(string $slug)
    {
        $app = Catalog::app($slug);
        if (! $this->svc()->canSeeApp($app)) {
            throw ApiException::notFound('APP_NOT_FOUND', 'Unknown application');
        }

        return $this->cached(['data' => $this->svc()->versions($app)]);
    }

    public function appChangelog(string $slug)
    {
        [$limit, $offset] = $this->paging(10);

        return $this->cached($this->svc()->changelog($slug, $limit, $offset));
    }

    public function changelog()
    {
        [$limit, $offset] = $this->paging(20);

        return $this->cached($this->svc()->changelog(null, $limit, $offset));
    }

    public function channels()
    {
        $rows = db_connect()->table('release_channels')->where('application_id', null)->orderBy('sort_order')->get()->getResultArray();

        return $this->cached(['data' => array_map(static fn ($c) => ['code' => $c['code'], 'name' => $c['name'], 'description' => $c['description'], 'is_public' => (bool) $c['is_public'], 'allow_direct_download' => (bool) $c['allow_direct_download']], $rows)], 300);
    }

    /** Public keys clients pin / refresh during rotation (FR-UC-MAN-06). */
    public function entitlements()
    {
        return $this->cached((new \App\Services\EntitlementCatalogService())->matrix(), 300);
    }

    public function keys()
    {
        $db   = db_connect();
        $rows = $db->table('signing_keys')->whereIn('purpose', ['RELEASE', 'RESPONSE'])->whereIn('status', ['ACTIVE', 'OVERLAP'])->get()->getResultArray();
        $keys = array_map(static fn ($k) => ['key_id' => $k['key_id'], 'purpose' => $k['purpose'], 'algorithm' => $k['algorithm'], 'public_key' => $k['public_key'], 'status' => $k['status']], $rows);
        $known = array_column($keys, 'key_id');
        foreach (KeyRegistry::releaseKeys() as $kid => $pub) {
            if (! in_array($kid, $known, true)) {
                $keys[] = ['key_id' => $kid, 'purpose' => 'RELEASE', 'algorithm' => 'EdDSA', 'public_key' => Base64Url::encode($pub), 'status' => 'ACTIVE'];
            }
        }
        $rot = $db->table('signing_keys')->where('rotation_doc IS NOT NULL')->orderBy('id', 'DESC')->limit(5)->get()->getResultArray();

        return $this->cached(['keys' => $keys, 'rotations' => array_map(static fn ($r) => json_decode((string) $r['rotation_doc'], true), $rot)], 300);
    }

    // ---- Subscriptions (§5.6): double opt-in --------------------------------

    public function subscribe()
    {
        $b     = $this->body();
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ApiException::validation(['email' => 'Enter a valid email address']);
        }
        service('rateLimiter')->hit('subscribe:' . $this->request->getIPAddress(), 10, 3600);
        $app     = Catalog::app((string) ($b['app'] ?? ''));
        $channel = strtoupper((string) ($b['channel'] ?? 'STABLE'));
        if (! in_array($channel, ['STABLE', 'BETA'], true)) {
            throw ApiException::validation(['channel' => 'STABLE or BETA']);
        }
        $db       = db_connect();
        $existing = $db->table('subscriptions')->where(['email' => $email, 'application_id' => $app['id'], 'channel' => $channel])->get()->getRowArray();
        $token    = bin2hex(random_bytes(20));
        if ($existing) {
            if ($existing['confirmed_at']) {
                return $this->ok(['status' => 'pending_confirmation'], 202); // do not reveal subscription state
            }
            $db->table('subscriptions')->where('id', $existing['id'])->update(['confirm_token' => $token, 'updated_at' => Catalog::now()]);
        } else {
            $db->table('subscriptions')->insert(['email' => $email, 'application_id' => $app['id'], 'channel' => $channel, 'confirm_token' => $token, 'unsubscribe_token' => bin2hex(random_bytes(20)), 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        }
        $base = rtrim(config('UpdateCenter')->publicBaseUrl, '/');
        service('notifier')->email($email, "Confirm: {$app['name']} release notifications", "Confirm that you want emails about new {$app['name']} releases ({$channel}):\n{$base}/api/v1/subscriptions/confirm?token={$token}\n\nIf you did not ask for this, ignore this email.");

        return $this->ok(['status' => 'pending_confirmation'], 202);
    }

    public function confirmSubscription()
    {
        $db  = db_connect();
        $row = $db->table('subscriptions')->where('confirm_token', (string) $this->request->getGet('token'))->get()->getRowArray();
        if (! $row) {
            throw new ApiException('TOKEN_INVALID', 'Confirmation link is invalid', 422);
        }
        $db->table('subscriptions')->where('id', $row['id'])->update(['confirmed_at' => Catalog::now(), 'confirm_token' => null]);

        return ['status' => 'confirmed'];
    }

    public function unsubscribe()
    {
        db_connect()->table('subscriptions')->where('unsubscribe_token', (string) $this->request->getGet('token'))->delete();

        return ['status' => 'unsubscribed'];
    }
}

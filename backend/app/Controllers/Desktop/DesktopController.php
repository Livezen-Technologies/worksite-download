<?php

namespace App\Controllers\Desktop;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Services\Catalog;
use App\Services\ManifestService;
use App\Services\TelemetryService;
use App\Services\TenantEventsService;
use App\Services\UpdateResolverService;

/**
 * Desktop Update API, manifests, grants, telemetry and the tenant catalog feed
 * (§10.1–10.5, FR-UC-API, FR-UC-TEL).
 */
class DesktopController extends BaseApiController
{
    private function tptPayload(string $appIdentifier = ''): ?array
    {
        $p = (new UpdateResolverService())->tenantPolicy($this->request->getHeaderLine('X-WorkSuite-Tenant-Policy') ?: null, $appIdentifier ?: 'lk.worksuite.desktop');

        return $p['payload'];
    }

    public function update()
    {
        $q = $this->request->getGet();
        if (! empty($q['device_id'])) {
            service('rateLimiter')->hit('update:dev:' . hash('sha256', (string) $q['device_id']), 30, 3600);
        }
        $res = (new UpdateResolverService())->check(
            $q,
            $this->request->getHeaderLine('X-WorkSuite-Tenant-Policy') ?: null,
            $this->request->getHeaderLine('Authorization') ?: null,
            $this->auth->isStaff(),
        );

        return $this->ok($res);
    }

    public function grant()
    {
        return (new UpdateResolverService())->redeemGrant((string) ($this->body()['grant'] ?? ''));
    }

    public function events()
    {
        $b      = $this->body();
        $events = $b['events'] ?? (isset($b['event_id']) ? [$b] : null);
        if (! is_array($events)) {
            throw ApiException::validation(['events' => 'Array of events required']);
        }

        return $this->ok((new TelemetryService())->ingest($events, $this->tptPayload(), $this->country()), 202);
    }

    public function heartbeat()
    {
        (new TelemetryService())->heartbeat($this->body(), $this->tptPayload(), $this->country());

        return $this->ok(['ok' => true], 202);
    }

    /** Coarse country from the edge (Cloudflare) — the IP itself is never stored. */
    private function country(): string
    {
        $c = strtoupper($this->request->getHeaderLine('CF-IPCountry'));

        return preg_match('/^[A-Z]{2}$/', $c) && $c !== 'XX' ? $c : '';
    }

    private function channelAccessible(array $app, array $channel): void
    {
        if ($channel['is_public'] && ! $app['private']) {
            return;
        }
        if ($this->auth->isStaff()) {
            return;
        }
        $tpt = $this->tptPayload($app['app_identifier']);
        if ($tpt !== null && (strtoupper((string) ($tpt['channel'] ?? '')) === $channel['code'] || in_array($channel['code'], array_map('strtoupper', (array) ($tpt['allowed_channels'] ?? [])), true))) {
            return;
        }

        throw new ApiException('CHANNEL_FORBIDDEN', 'Channel requires sign-in / TPT / token', 403);
    }

    public function channelManifest(string $appRef, string $channelCode)
    {
        $app     = Catalog::app($appRef);
        $channel = Catalog::channel($channelCode, (int) $app['id']);
        $this->channelAccessible($app, $channel);
        $ms  = new ManifestService();
        $row = $ms->latestChannel((int) $app['id'], (int) $channel['id']);
        if ($row === null) {
            throw ApiException::notFound('RELEASE_NOT_AVAILABLE', 'No manifest published for this channel yet');
        }

        return $this->response->setStatusCode(200)->setContentType('application/json')
            ->setHeader('Cache-Control', 'public, max-age=60')->setHeader('ETag', '"' . $row['sha256'] . '"')
            ->setBody($row['manifest_json']);
    }

    public function releaseManifest(string $releaseId)
    {
        $release = Catalog::release($releaseId);
        if (! in_array($release['status'], ['PUBLISHED', 'PAUSED', 'SUPERSEDED', 'WITHDRAWN'], true) && ! $this->auth->isStaff()) {
            throw ApiException::notFound('RELEASE_NOT_AVAILABLE', 'Release manifest not available');
        }
        $this->channelAccessible(Catalog::appById((int) $release['application_id']), Catalog::channelById((int) $release['channel_id']));
        $row = (new ManifestService())->latestSigned((int) $release['id']);
        if ($row === null) {
            throw ApiException::notFound('RELEASE_NOT_AVAILABLE', 'Release manifest not available');
        }

        return $this->response->setStatusCode(200)->setContentType('application/json')
            ->setHeader('Cache-Control', 'public, max-age=300')->setHeader('ETag', '"' . $row['sha256'] . '"')
            ->setBody($row['manifest_json']);
    }

    /** Desktop revalidation before install (ENH-02 WF-U15, WF-C14 step 3). */
    public function releaseState(string $releaseId)
    {
        $r = Catalog::release($releaseId);
        if (in_array($r['status'], ['DRAFT', 'UPLOADING', 'READY_FOR_TEST'], true)) {
            throw ApiException::notFound('RELEASE_NOT_FOUND', 'Release not found');
        }
        $replacement = null;
        if ($r['status'] === 'WITHDRAWN' || $r['status'] === 'SUPERSEDED') {
            $cur = (new ManifestService())->currentRelease((int) $r['application_id'], (int) $r['channel_id']);
            if ($cur && $cur['id'] !== $r['id']) {
                $replacement = ['release_id' => $cur['public_id'], 'version' => $cur['version']];
            }
        }

        return $this->ok([
            'release_id'    => $r['public_id'],
            'version'       => $r['version'],
            'status'        => in_array($r['status'], ['TESTING', 'APPROVED'], true) ? 'PUBLISHED' : $r['status'],
            'support_state' => $r['support_state'],
            'withdrawn_at'  => Catalog::iso($r['withdrawn_at']),
            'reason'        => $r['status'] === 'WITHDRAWN' ? $r['withdraw_reason'] : null,
            'replacement'   => $replacement,
            'checked_at'    => gmdate('Y-m-d\TH:i:s\Z'),
        ])->setHeader('Cache-Control', 'public, max-age=30');
    }

    /** Tenant catalog feed (§2.5, WF-C17). */
    public function catalogChanges()
    {
        if (($this->auth->apiClient['kind'] ?? '') !== 'TENANT' && ! $this->auth->can('release.view')) {
            throw ApiException::forbidden('Tenant API client required');
        }
        if ($this->auth->apiClient && $this->auth->apiClient['tenant_id']) {
            db_connect()->table('tenants')->where('id', $this->auth->apiClient['tenant_id'])->update(['last_catalog_sync_at' => Catalog::now()]);
        }

        return (new TenantEventsService())->changes(max(0, (int) $this->request->getGet('since')), min(500, max(1, (int) ($this->request->getGet('limit') ?? 200))));
    }

    /** GET /catalog/mirror/{app}/{channel} — mirror feed for self-hosted / air-gapped tenants (§2.5). */
    public function mirror(string $app, string $channel)
    {
        if (($this->auth->apiClient['kind'] ?? '') !== 'TENANT' && ! $this->auth->can('release.view')) {
            throw ApiException::forbidden('Tenant API client required');
        }
        $tenant = isset($this->auth->apiClient['tenant_id']) ? 'tenant:' . $this->auth->apiClient['tenant_id'] : null;

        return (new \App\Services\MirrorService())->index($app, $channel, (int) ($this->request->getGet('keep') ?? 3), $tenant);
    }
}

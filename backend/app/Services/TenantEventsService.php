<?php

namespace App\Services;

use App\Jobs\SendWebhookJob;
use App\Libraries\Queue;

/**
 * Catalog feed for tenants (§2.5, WF-C17) and signed webhooks
 * release.published|paused|withdrawn|superseded, minimum.changed (FR-UC-NTF-03).
 * Outbound calls are fire-and-forget through the queue — never synchronous.
 */
class TenantEventsService
{
    public function emit(string $event, int $appId, ?int $releaseId, ?int $channelId, array $data = []): void
    {
        $db  = db_connect();
        $app = Catalog::appById($appId);
        $payload = ['app' => $app['app_identifier'], 'app_slug' => $app['slug']] + $data;
        if ($releaseId) {
            $r = Catalog::releaseById($releaseId);
            $payload += ['release_id' => $r['public_id'], 'version' => $r['version'], 'build' => (int) $r['build_number'], 'status' => $r['status'], 'channel' => strtolower(Catalog::channelById((int) $r['channel_id'])['code']), 'notes_url' => config('UpdateCenter')->publicBaseUrl . '/apps/' . $app['slug'] . '/releases/' . $r['version']];
        }
        $db->table('catalog_changes')->insert(['seq_kind' => $event, 'application_id' => $appId, 'release_id' => $releaseId, 'channel_id' => $channelId, 'payload' => json_encode($payload), 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        $seq = (int) $db->insertID();

        foreach ($db->table('tenant_webhooks w')->select('w.*')->join('tenants t', 't.id = w.tenant_id')->where('w.active', 1)->where('t.status', 'ACTIVE')->get()->getResultArray() as $hook) {
            $events = json_decode((string) $hook['events'], true) ?: [];
            if ($events !== [] && ! in_array($event, $events, true) && ! in_array('*', $events, true)) {
                continue;
            }
            $db->table('webhook_deliveries')->insert(['webhook_id' => $hook['id'], 'target_url' => $hook['url'], 'event' => $event, 'payload' => json_encode($payload), 'status' => 'PENDING', 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
            Queue::push(SendWebhookJob::class, ['delivery_id' => (int) $db->insertID(), 'webhook_id' => (int) $hook['id'], 'url' => $hook['url'], 'event' => $event, 'id' => 'evt_' . $seq, 'payload' => $payload, 'secret_ref' => $hook['secret_ref']], 'webhooks', 0, 8);
        }
    }

    /** GET /catalog/changes?since= — signed manifests travel with the change. */
    public function changes(int $since, int $limit = 200): array
    {
        $db   = db_connect();
        $rows = $db->table('catalog_changes')->where('id >', $since)->orderBy('id')->limit($limit)->get()->getResultArray();
        $ms   = new ManifestService();
        $out  = [];
        foreach ($rows as $r) {
            $item = ['seq' => (int) $r['id'], 'event' => $r['seq_kind'], 'at' => Catalog::iso($r['created_at']), 'data' => json_decode((string) $r['payload'], true)];
            if ($r['release_id']) {
                $m = $ms->latestSigned((int) $r['release_id']);
                $item['release_manifest'] = $m ? json_decode($m['manifest_json'], true) : null;
            }
            if ($r['channel_id']) {
                $c = $ms->latestChannel((int) $r['application_id'], (int) $r['channel_id']);
                $item['channel_manifest'] = $c ? json_decode($c['manifest_json'], true) : null;
            }
            $out[] = $item;
        }

        return ['changes' => $out, 'next' => $rows ? (int) end($rows)['id'] : $since, 'more' => count($rows) === $limit];
    }
}

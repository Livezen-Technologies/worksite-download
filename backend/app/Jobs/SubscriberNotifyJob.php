<?php

namespace App\Jobs;

use App\Services\Catalog;

/**
 * "Notify me about … releases" (§5.6): confirmed subscribers of the app/channel.
 */
class SubscriberNotifyJob implements JobInterface
{
    public function handle(array $payload): void
    {
        $release = Catalog::releaseById((int) $payload['release_id']);
        if ($release['visibility'] !== 'PUBLIC') {
            return;
        }
        $app     = Catalog::appById((int) $release['application_id']);
        $channel = Catalog::channelById((int) $release['channel_id'])['code'];
        $base    = rtrim(config('UpdateCenter')->publicBaseUrl, '/');
        $subs    = db_connect()->table('subscriptions')->where('application_id', $app['id'])->where('channel', $channel)->where('confirmed_at IS NOT NULL')->get()->getResultArray();
        $summary = db_connect()->table('release_changelogs')->select('summary')->where('release_id', $release['id'])->where('locale', 'en')->get()->getRow('summary');
        foreach ($subs as $s) {
            service('notifier')->email($s['email'], "{$app['name']} {$release['version']} is available",
                ($summary ? $summary . "\n\n" : '') . "Release notes and downloads: {$base}/apps/{$app['slug']}/releases/{$release['version']}\n\nUnsubscribe: {$base}/api/v1/subscriptions/unsubscribe?token={$s['unsubscribe_token']}");
        }
    }
}

<?php

namespace App\Jobs;

use App\Libraries\SecretStore;

/**
 * Purge CDN caches for manifest/page paths after publish/withdraw (FR-UC-STO-05).
 * Failures are retried by the queue; publish stays valid (manifests are served
 * with a short Cache-Control).
 */
class CdnPurgeJob implements JobInterface
{
    public function handle(array $payload): void
    {
        $cfg   = config('UpdateCenter');
        $paths = array_values(array_unique((array) ($payload['paths'] ?? [])));
        $db    = db_connect();
        $mark  = static function (string $status) use ($db): void {
            $row = ['value' => json_encode(['at' => gmdate('c'), 'status' => $status]), 'updated_at' => gmdate('Y-m-d H:i:s')];
            if ($db->table('settings')->where('key', 'cdn.last_purge')->countAllResults()) {
                $db->table('settings')->where('key', 'cdn.last_purge')->update($row);
            } else {
                $db->table('settings')->insert($row + ['key' => 'cdn.last_purge', 'created_at' => gmdate('Y-m-d H:i:s')]);
            }
        };
        if ($cfg->cdnPurge !== 'cloudflare' || $paths === []) {
            $mark('skipped');

            return;
        }
        $token = SecretStore::get($cfg->cloudflareTokenRef) ?? throw new \RuntimeException('Cloudflare token not configured');
        $base  = rtrim($cfg->publicBaseUrl, '/');
        $files = array_map(static fn ($p) => $base . $p, $paths);
        foreach (array_chunk($files, 30) as $chunk) {
            $ch = curl_init("https://api.cloudflare.com/client/v4/zones/{$cfg->cloudflareZoneId}/purge_cache");
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['files' => $chunk])]);
            $res  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code !== 200) {
                $mark('failed');

                throw new \RuntimeException("CDN purge failed ({$code}): " . substr((string) $res, 0, 200));
            }
        }
        $mark('ok');
    }
}

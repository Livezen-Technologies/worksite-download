<?php

namespace App\Jobs;

/**
 * Re-render public pages after publish so crawlers and slow clients get HTML
 * without JavaScript (§5.5). Runs the frontend prerender script configured in
 * `updatecenter.prerenderCommand` (e.g. `node /srv/frontend/scripts/prerender.mjs`).
 */
class PrerenderJob implements JobInterface
{
    public function handle(array $payload): void
    {
        $cmd = (string) env('updatecenter.prerenderCommand', '');
        if ($cmd === '') {
            return;
        }
        $app  = isset($payload['app_id']) ? db_connect()->table('applications')->select('slug')->where('id', $payload['app_id'])->get()->getRow('slug') : null;
        $full = $cmd . ' --api ' . escapeshellarg(rtrim(config('UpdateCenter')->publicBaseUrl, '/') . '/api/v1') . ($app ? ' --app ' . escapeshellarg((string) $app) : '');
        exec($full . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new \RuntimeException('Prerender failed: ' . implode("\n", array_slice($out, -5)));
        }
    }
}

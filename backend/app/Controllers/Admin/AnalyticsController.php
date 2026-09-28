<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Services\Catalog;
use App\Services\PublicCatalogService;
use App\Services\RolloutService;
use App\Services\TelemetryService;

/**
 * Download analytics (FR-UC-ANL-01..04) and device statistics (FR-UC-DEV).
 */
class AnalyticsController extends BaseApiController
{
    public function downloads()
    {
        $g    = $this->request->getGet();
        $db   = db_connect();
        $from = Catalog::toDb($g['from'] ?? null) ?? gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $to   = Catalog::toDb($g['to'] ?? null) ?? Catalog::now();
        $base = static function () use ($db, $g, $from, $to) {
            $q = $db->table('download_events e')->where('e.created_at >=', $from)->where('e.created_at <=', $to)->where('e.redirect_ok', 1);
            if (! empty($g['app'])) {
                $q->where('e.application_id', Catalog::app((string) $g['app'])['id']);
            }
            if (! empty($g['release'])) {
                $q->where('e.release_id', Catalog::release((string) $g['release'])['id']);
            }

            return $q;
        };
        $bots  = $base()->where('e.is_bot', 1)->countAllResults();
        $group = in_array($g['group'] ?? 'day', ['day', 'week', 'month'], true) ? ($g['group'] ?? 'day') : 'day';
        $len   = $group === 'month' ? 7 : 10;
        $rows  = $base()->select("SUBSTR(e.created_at, 1, {$len}) AS p, COUNT(*) AS c", false)->where('e.is_bot', 0)->groupBy('p')->orderBy('p')->get()->getResultArray();
        $series = [];
        foreach ($rows as $r) {
            $period = $group === 'week' ? gmdate('o-\WW', strtotime($r['p'])) : $r['p'];
            $series[$period] = ($series[$period] ?? 0) + (int) $r['c'];
        }
        $series = array_map(static fn ($p, $c) => ['period' => $p, 'count' => $c], array_keys($series), $series);

        if (($g['format'] ?? '') === 'csv') {
            $csv = "date,app,version,platform,architecture,package_type,country,downloads\n";
            foreach ($base()->select('SUBSTR(e.created_at, 1, 10) AS d, a.slug, e.version, e.platform, e.architecture, e.package_type, e.country_code, COUNT(*) AS c', false)->join('applications a', 'a.id = e.application_id')->where('e.is_bot', 0)
                ->groupBy(['d', 'a.slug', 'e.version', 'e.platform', 'e.architecture', 'e.package_type', 'e.country_code'])->orderBy('d')->get()->getResultArray() as $r) {
                $csv .= implode(',', array_map(static fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', [$r['d'], $r['slug'], $r['version'], $r['platform'], $r['architecture'], $r['package_type'], $r['country_code'], $r['c']])) . "\n";
            }

            return $this->response->setContentType('text/csv')->setHeader('Content-Disposition', 'attachment; filename="downloads.csv"')->setBody($csv);
        }

        $by = static fn (string $col) => $base()->select("e.{$col} AS k, COUNT(*) AS c")->where('e.is_bot', 0)->groupBy("e.{$col}")->orderBy('c', 'DESC')->limit(20)->get()->getResultArray();

        return [
            'total'         => array_sum(array_column($series, 'count')),
            'bots_excluded' => $bots,
            'series'        => $series,
            'by_platform'   => array_map(static fn ($r) => ['platform' => $r['k'], 'count' => (int) $r['c']], $by('platform')),
            'by_package'    => array_map(static fn ($r) => ['label' => PublicCatalogService::label((string) $r['platform'], (string) $r['architecture'], (string) $r['package_type']), 'count' => (int) $r['c']],
                $base()->select('e.platform, e.architecture, e.package_type, COUNT(*) AS c')->where('e.is_bot', 0)->groupBy(['e.platform', 'e.architecture', 'e.package_type'])->orderBy('c', 'DESC')->limit(20)->get()->getResultArray()),
            'by_country'    => array_map(static fn ($r) => ['country' => $r['k'] ?? '??', 'count' => (int) $r['c']], $by('country_code')),
            'top_releases'  => array_map(static fn ($r) => ['release_id' => $r['public_id'], 'version' => $r['version'], 'app_name' => $r['name'], 'count' => (int) $r['c']],
                $base()->select('r.public_id, r.version, a.name, COUNT(*) AS c')->join('releases r', 'r.id = e.release_id')->join('applications a', 'a.id = e.application_id')->where('e.is_bot', 0)->groupBy(['r.public_id', 'r.version', 'a.name'])->orderBy('c', 'DESC')->limit(10)->get()->getResultArray()),
        ];
    }

    public function devices()
    {
        $app = Catalog::app((string) ($this->request->getGet('app') ?: 'desktop'));

        return (new TelemetryService())->distribution((int) $app['id'], $this->request->getGet('channel') ?: null);
    }

    public function updateStatus()
    {
        $g   = $this->request->getGet();
        $app = Catalog::app((string) ($g['app'] ?? 'desktop'));

        return (new TelemetryService())->updateStatus((int) $app['id'], ! empty($g['release']) ? (int) Catalog::release((string) $g['release'])['id'] : null);
    }

    public function rollouts()
    {
        $out = [];
        foreach (db_connect()->table('releases')->whereIn('status', ['PUBLISHED', 'PAUSED'])->orderBy('published_at', 'DESC')->get()->getResultArray() as $r) {
            $out[] = ReleasesController::present($r) + ['rollout' => (new RolloutService())->present($r)];
        }

        return ['data' => $out];
    }
}

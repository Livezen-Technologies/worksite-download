<?php

namespace App\Services\BugTrace;

use App\Exceptions\ApiException;
use App\Libraries\BugTrace\Bt;

/**
 * Bug Trace projects (one per client platform: web, desktop, mobile, server).
 * Public ids are derived from the issue prefix: WS-WEB → btp_web.
 */
class ProjectService
{
    /** Resolve by public id (btp_web), platform (web), issue prefix (WS-WEB) or public key. */
    public static function resolve(string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }
        foreach (Bt::db()->table('bt_projects')->get()->getResultArray() as $p) {
            if ($ref === Bt::projectPublicId($p) || strcasecmp($ref, (string) $p['issue_prefix']) === 0 || $ref === $p['public_key'] || strcasecmp($ref, (string) $p['platform']) === 0) {
                return $p;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function format(array $p, bool $withStats = true): array
    {
        $db  = Bt::db();
        $app = $db->table('applications')->select('public_id, slug, name, app_identifier')->where('id', $p['application_id'])->get()->getRowArray();
        $out = [
            'id'           => Bt::projectPublicId($p),
            'name'         => $p['name'],
            'platform'     => $p['platform'],
            'issue_prefix' => $p['issue_prefix'],
            'environment'  => $p['environment'],
            'app'          => $app ? ['id' => $app['public_id'], 'slug' => $app['slug'], 'name' => $app['name'], 'app_identifier' => $app['app_identifier']] : null,
            'public_key'   => $p['public_key'],
            'rate_limit'   => (int) $p['rate_limit'],
            'quota_daily'  => (int) $p['quota_daily'],
            'sample_rates' => Bt::decode($p['sample_rates'], ['crash' => 1.0, 'error' => 0.25]),
            'status'       => $p['status'],
        ];
        if ($withStats) {
            $out['issues_unresolved'] = $db->table('bt_issues')->where('project_id', $p['id'])->whereIn('status', Bt::UNRESOLVED)->countAllResults();
            $out['quota_today']       = (new QuotaService())->today((int) $p['id']);
            $out['symbols']           = $db->table('bt_symbols')->where('project_id', $p['id'])->countAllResults();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function patch(string $ref, array $body): array
    {
        $p = self::resolve($ref) ?? throw ApiException::notFound('NOT_FOUND', 'Project not found');
        $row = [];
        if (isset($body['sample_rates'])) {
            if (! is_array($body['sample_rates'])) {
                throw ApiException::validation(['sample_rates' => 'Must be an object type → rate']);
            }
            $rates = [];
            foreach ($body['sample_rates'] as $type => $rate) {
                if (! in_array($type, ['crash', 'error', 'hang', 'message', 'unhandled'], true) || ! is_numeric($rate) || $rate < 0 || $rate > 1) {
                    throw ApiException::validation(['sample_rates' => "Invalid rate for {$type}"]);
                }
                $rates[$type] = (float) $rate;
            }
            $row['sample_rates'] = Bt::json($rates + Bt::decode($p['sample_rates'], []));
        }
        foreach (['rate_limit', 'quota_daily'] as $k) {
            if (isset($body[$k])) {
                if (! is_numeric($body[$k]) || (int) $body[$k] < 1) {
                    throw ApiException::validation([$k => 'Must be a positive integer']);
                }
                $row[$k] = (int) $body[$k];
            }
        }
        if (isset($body['status'])) {
            if (! in_array($body['status'], ['ACTIVE', 'DISABLED'], true)) {
                throw ApiException::validation(['status' => 'ACTIVE or DISABLED']);
            }
            $row['status'] = $body['status'];
        }
        if (isset($body['name'])) {
            $row['name'] = mb_substr(trim((string) $body['name']), 0, 150);
        }
        if ($row !== []) {
            Bt::db()->table('bt_projects')->where('id', $p['id'])->update($row + ['updated_at' => Bt::now()]);
            EventProcessor::flushCache();
        }

        return self::format(Bt::db()->table('bt_projects')->where('id', $p['id'])->get()->getRowArray());
    }
}

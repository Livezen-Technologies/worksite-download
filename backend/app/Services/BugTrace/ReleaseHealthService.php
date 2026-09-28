<?php

namespace App\Services\BugTrace;

use App\Libraries\BugTrace\Bt;

/**
 * Release Health (FR-BT-HLT-01/02/04, B5, WF-C36): adoption, crash-free users
 * and sessions, new issues, regressions, user reports and update failures per
 * release, plus the rollout gate used by the release pipeline:
 *
 *   (new ReleaseHealthService())->gateFor($releaseId)
 *     → ['state' => 'OK'|'WARN'|'BLOCK', 'reasons' => [...], 'crash_free_users' => 99.12|null]
 *
 * Gate rules (settings key bugtrace.gate_rules):
 *   crash-free users below threshold (default 99.0 %)          → BLOCK
 *   new P0/P1 issue first seen in the release affecting ≥ N users (100) → BLOCK
 *   crash-free users dropped > x pt vs previous release (0.5)   → WARN
 *   install failure + rollback rate above y % (5 %)             → WARN
 * Rates are only evaluated with enough data (min_sessions / min_installs).
 */
class ReleaseHealthService
{
    public const SETTINGS_KEY = 'bugtrace.gate_rules';

    public const DEFAULT_RULES = [
        'crash_free_users_below'   => 99.0,
        'drop_vs_previous_pt'      => 0.5,
        'new_p0p1_min_users'       => 100,
        'install_failure_rate_pct' => 5.0,
        'min_sessions'             => 50,
        'min_installs'             => 20,
        'window_days'              => 7,
        'action'                   => 'ALERT_SUGGEST_PAUSE',
        'auto_pause'               => 'PER_RELEASE_OPT_IN',
    ];

    private const HEALTH_STATUSES = ['PUBLISHED', 'PAUSED', 'SUPERSEDED', 'WITHDRAWN'];

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return Bt::setting(self::SETTINGS_KEY, self::DEFAULT_RULES);
    }

    /** @return array<string, mixed> */
    public static function saveRules(array $in): array
    {
        $rules  = self::rules();
        $errors = [];
        foreach (['crash_free_users_below' => [50, 100], 'drop_vs_previous_pt' => [0, 50], 'new_p0p1_min_users' => [1, 10000000], 'install_failure_rate_pct' => [0, 100], 'min_sessions' => [0, 10000000], 'min_installs' => [0, 10000000], 'window_days' => [1, 90]] as $k => [$lo, $hi]) {
            if (array_key_exists($k, $in)) {
                if (! is_numeric($in[$k]) || $in[$k] < $lo || $in[$k] > $hi) {
                    $errors[$k] = "Must be between {$lo} and {$hi}";
                } else {
                    $rules[$k] = str_contains($k, 'pct') || str_contains($k, '_pt') || str_contains($k, 'below') ? (float) $in[$k] : (int) $in[$k];
                }
            }
        }
        if (isset($in['action'])) {
            in_array($in['action'], ['ALERT', 'ALERT_SUGGEST_PAUSE'], true) ? $rules['action'] = $in['action'] : $errors['action'] = 'ALERT or ALERT_SUGGEST_PAUSE';
        }
        if ($errors !== []) {
            throw \App\Exceptions\ApiException::validation($errors);
        }
        Bt::saveSetting(self::SETTINGS_KEY, $rules);

        return $rules;
    }

    /**
     * Rollout gate for one release (called by the release/rollout module).
     *
     * @return array{state: 'OK'|'WARN'|'BLOCK', reasons: list<string>, crash_free_users: ?float}
     */
    public function gateFor(int $releaseId): array
    {
        $release = Bt::db()->table('releases')->where('id', $releaseId)->get()->getRowArray();
        if (! $release) {
            return ['state' => 'OK', 'reasons' => [], 'crash_free_users' => null];
        }
        $rules = self::rules();
        $h     = $this->metrics($release, (int) $rules['window_days']);

        return $this->evaluate($release, $h, $rules);
    }

    /**
     * @param array<string, mixed> $h metrics()
     *
     * @return array{state: 'OK'|'WARN'|'BLOCK', reasons: list<string>, crash_free_users: ?float}
     */
    private function evaluate(array $release, array $h, array $rules): array
    {
        $state   = 'OK';
        $reasons = [];
        $raise   = static function (string $to) use (&$state): void {
            if ($to === 'BLOCK' || ($to === 'WARN' && $state === 'OK')) {
                $state = $to;
            }
        };
        $enough = $h['sessions'] >= (int) $rules['min_sessions'] || $h['users'] >= (int) $rules['min_sessions'];
        $cfu    = $h['crash_free_users'];
        if ($enough && $cfu !== null && $cfu < (float) $rules['crash_free_users_below']) {
            $raise('BLOCK');
            $reasons[] = sprintf('Crash-free users %.2f%% is below %.1f%%', $cfu, $rules['crash_free_users_below']);
        }
        if ($enough && $cfu !== null) {
            $prev = $this->previousRelease($release);
            if ($prev !== null) {
                $ph = $this->metrics($prev, (int) $rules['window_days']);
                if (($ph['sessions'] >= (int) $rules['min_sessions'] || $ph['users'] >= (int) $rules['min_sessions']) && $ph['crash_free_users'] !== null && $ph['crash_free_users'] - $cfu > (float) $rules['drop_vs_previous_pt']) {
                    $raise('WARN');
                    $reasons[] = sprintf('Crash-free users dropped %.2f pt vs %s (%.2f%%)', $ph['crash_free_users'] - $cfu, $prev['version'], $ph['crash_free_users']);
                }
            }
        }
        foreach ($this->newIssues($release, ['P0', 'P1'], (int) $rules['new_p0p1_min_users']) as $i) {
            $raise('BLOCK');
            $reasons[] = sprintf('New %s issue %s affects %d users', $i['priority'], $i['public_id'], $i['users_count']);
        }
        if ($h['install_attempts'] >= (int) $rules['min_installs'] && $h['update_failure_rate'] !== null && $h['update_failure_rate'] > (float) $rules['install_failure_rate_pct']) {
            $raise('WARN');
            $reasons[] = sprintf('Update install failures %.1f%% above %.1f%%', $h['update_failure_rate'], $rules['install_failure_rate_pct']);
        }

        return ['state' => $state, 'reasons' => $reasons, 'crash_free_users' => $cfu];
    }

    /** @return list<int> Bug Trace projects of the release's application */
    private function projectIds(int $appId): array
    {
        return array_map('intval', array_column(Bt::db()->table('bt_projects')->select('id')->where('application_id', $appId)->get()->getResultArray(), 'id')) ?: [0];
    }

    /**
     * Raw health metrics for one release within the last $days days.
     *
     * @return array<string, mixed>
     */
    public function metrics(array $release, int $days = 7): array
    {
        $db       = Bt::db();
        $id       = (int) $release['id'];
        $since    = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $projects = $this->projectIds((int) $release['application_id']);

        $s = $db->table('bt_sessions_daily')->select('SUM(sessions) AS sessions, SUM(crashed) AS crashed, SUM(abnormal) AS abnormal')
            ->where('release_id', $id)->where('date >=', $since)->get()->getRowArray();
        $u = $db->table('bt_session_users')->select('COUNT(DISTINCT user_ref) AS users, COUNT(DISTINCT CASE WHEN crashed = 1 THEN user_ref END) AS crashed_users', false)
            ->where('release_id', $id)->where('date >=', $since)->get()->getRowArray();
        $sessions = (int) ($s['sessions'] ?? 0);
        $crashed  = (int) ($s['crashed'] ?? 0);
        $users    = (int) ($u['users'] ?? 0);
        $cusers   = (int) ($u['crashed_users'] ?? 0);

        $ue = $db->table('update_events')->select('type, COUNT(*) AS n')->where('release_id', $id)
            ->whereIn('type', ['install_succeeded', 'install_failed', 'rolled_back'])->groupBy('type')->get()->getResultArray();
        $ue       = array_column($ue, 'n', 'type');
        $failures = (int) ($ue['install_failed'] ?? 0) + (int) ($ue['rolled_back'] ?? 0);
        $attempts = (int) ($ue['install_succeeded'] ?? 0) + (int) ($ue['install_failed'] ?? 0);

        $versionLike = $release['version'];
        $regressions = $db->table('bt_issues')->whereIn('project_id', $projects)->where('regressed_at IS NOT NULL', null, false)
            ->where('EXISTS (SELECT 1 FROM bt_issue_releases ir WHERE ir.issue_id = bt_issues.id AND (ir.' . $db->protectIdentifiers('release') . ' = ' . $db->escape($versionLike) . ' OR ir.' . $db->protectIdentifiers('release') . ' LIKE ' . $db->escape($versionLike . '+%') . '))', null, false)
            ->countAllResults();

        return [
            'sessions'            => $sessions,
            'crashed_sessions'    => $crashed,
            'abnormal_sessions'   => (int) ($s['abnormal'] ?? 0),
            'users'               => $users,
            'crashed_users'       => $cusers,
            'crash_free_sessions' => $sessions > 0 ? round(100 * (1 - $crashed / $sessions), 2) : null,
            'crash_free_users'    => $users > 0 ? round(100 * (1 - $cusers / $users), 2) : null,
            'new_issues'          => $db->table('bt_issues')->whereIn('project_id', $projects)->where('first_release_id', $id)->where('status !=', 'DUPLICATE')->countAllResults(),
            'regressions'         => $regressions,
            'reports'             => $db->table('bt_reports')->where('release_id', $id)->countAllResults(),
            'update_failures'     => $failures,
            'install_attempts'    => $attempts,
            'update_failure_rate' => $attempts > 0 ? round(100 * $failures / $attempts, 2) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function newIssues(array $release, array $priorities, int $minUsers): array
    {
        return Bt::db()->table('bt_issues')->select('public_id, priority, users_count')
            ->whereIn('project_id', $this->projectIds((int) $release['application_id']))
            ->where('first_release_id', $release['id'])->whereIn('priority', $priorities)->where('users_count >=', $minUsers)
            ->whereIn('status', Bt::UNRESOLVED)->orderBy('users_count', 'DESC')->get()->getResultArray();
    }

    private function previousRelease(array $release): ?array
    {
        return Bt::db()->table('releases')->where('application_id', $release['application_id'])->where('channel_id', $release['channel_id'])
            ->where('version_sort <', $release['version_sort'])->whereIn('status', ['PUBLISHED', 'SUPERSEDED', 'PAUSED'])
            ->orderBy('version_sort', 'DESC')->limit(1)->get()->getRowArray() ?: null;
    }

    private function adoption(array $release, array $releasesOfApp, int $days): ?float
    {
        $db    = Bt::db();
        $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $total = $db->table('device_versions')->where('application_id', $release['application_id'])->where('last_seen >=', $since)->countAllResults();
        if ($total > 0) {
            $n = $db->table('device_versions')->where('application_id', $release['application_id'])->where('version', $release['version'])->where('last_seen >=', $since)->countAllResults();

            return round(100 * $n / $total, 1);
        }
        // No update telemetry (web/server): share of sessions among the app's releases.
        $sinceDay = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $all      = (int) ($db->table('bt_sessions_daily')->selectSum('sessions')->whereIn('release_id', array_map('intval', array_column($releasesOfApp, 'id')) ?: [0])->where('date >=', $sinceDay)->get()->getRow('sessions') ?? 0);
        if ($all === 0) {
            return null;
        }
        $mine = (int) ($db->table('bt_sessions_daily')->selectSum('sessions')->where('release_id', $release['id'])->where('date >=', $sinceDay)->get()->getRow('sessions') ?? 0);

        return round(100 * $mine / $all, 1);
    }

    /**
     * GET /admin/bugtrace/health
     *
     * @param array<string, mixed> $q app, release, release_id, days
     *
     * @return array<string, mixed>
     */
    public function health(array $q): array
    {
        $db    = Bt::db();
        $rules = self::rules();
        $days  = max(1, min(90, (int) ($q['days'] ?? $rules['window_days'])));
        $b     = $db->table('releases r')->select('r.*, a.slug AS app_slug, a.name AS app_name, a.public_id AS app_public_id, c.code AS channel_code')
            ->join('applications a', 'a.id = r.application_id')->join('release_channels c', 'c.id = r.channel_id', 'left')
            ->whereIn('a.id', array_map('intval', array_column($db->table('bt_projects')->select('application_id')->get()->getResultArray(), 'application_id')) ?: [0]);
        if (! empty($q['app'])) {
            $app = (string) $q['app'];
            $b->groupStart()->where('a.slug', $app)->orWhere('a.public_id', $app)->orWhere('a.app_identifier', $app)->groupEnd();
        }
        $rel = (string) ($q['release_id'] ?? $q['release'] ?? '');
        if ($rel !== '') {
            str_starts_with($rel, 'rel_') ? $b->where('r.public_id', $rel) : (ctype_digit($rel) ? $b->where('r.id', (int) $rel) : $b->where('r.version', $rel));
        } else {
            $b->groupStart()->whereIn('r.status', self::HEALTH_STATUSES)
                ->orWhere('EXISTS (SELECT 1 FROM bt_sessions_daily sd WHERE sd.release_id = r.id)', null, false)->groupEnd();
        }
        $releases = $b->orderBy('r.published_at', 'DESC')->orderBy('r.version_sort', 'DESC')->limit(30)->get()->getResultArray();

        $rows   = [];
        $series = [];
        $byApp  = [];
        foreach ($releases as $r) {
            $byApp[(int) $r['application_id']] ??= $db->table('releases')->select('id')->where('application_id', $r['application_id'])->get()->getResultArray();
            $m    = $this->metrics($r, $days);
            $gate = $this->evaluate($r, $m, $rules);
            $rows[] = [
                'release'             => $r['version'],
                'release_id'          => $r['public_id'],
                'build'               => (int) $r['build_number'],
                'app'                 => ['id' => $r['app_public_id'], 'slug' => $r['app_slug'], 'name' => $r['app_name']],
                'channel'             => $r['channel_code'],
                'status'              => $r['status'],
                'published_at'        => Bt::iso($r['published_at']),
                'adoption_pct'        => $this->adoption($r, $byApp[(int) $r['application_id']], $days),
                'sessions'            => $m['sessions'],
                'users'               => $m['users'],
                'crash_free_sessions' => $m['crash_free_sessions'],
                'crash_free_users'    => $m['crash_free_users'],
                'new_issues'          => $m['new_issues'],
                'regressions'         => $m['regressions'],
                'reports'             => $m['reports'],
                'update_failures'     => $m['update_failures'],
                'update_failure_rate' => $m['update_failure_rate'],
                'gate'                => $gate['state'],
                'gate_reasons'        => $gate['reasons'],
                'auto_pause_on_gate'  => (bool) ($r['auto_pause_on_gate'] ?? false),
            ];
            $since = gmdate('Y-m-d', time() - ($days - 1) * 86400);
            foreach ($db->table('bt_sessions_daily')->select('date, SUM(users) AS users, SUM(crashed_users) AS crashed_users')->where('release_id', $r['id'])->where('date >=', $since)->groupBy('date')->orderBy('date')->get()->getResultArray() as $d) {
                $series[] = ['date' => (string) $d['date'], 'release' => $r['version'], 'release_id' => $r['public_id'], 'crash_free_users' => (int) $d['users'] > 0 ? round(100 * (1 - (int) $d['crashed_users'] / (int) $d['users']), 2) : null];
            }
        }

        return ['releases' => $rows, 'series' => $series, 'gate_rules' => $rules, 'days' => $days];
    }

    public function csv(array $q): string
    {
        $h   = $this->health($q);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['app', 'release', 'release_id', 'channel', 'status', 'adoption_pct', 'sessions', 'crash_free_sessions', 'crash_free_users', 'new_issues', 'regressions', 'reports', 'update_failures', 'gate'], ',', '"', '');
        foreach ($h['releases'] as $r) {
            fputcsv($out, [$r['app']['name'], $r['release'], $r['release_id'], $r['channel'], $r['status'], $r['adoption_pct'], $r['sessions'], $r['crash_free_sessions'], $r['crash_free_users'], $r['new_issues'], $r['regressions'], $r['reports'], $r['update_failures'], $r['gate']], ',', '"', '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * Periodic gate evaluation (spark bugtrace:alerts): alerts the Release
     * Manager once per state change to WARN/BLOCK for recently published
     * releases and fires crash_free_below rules (webhooks). Pausing is applied by
     * the rollout module (auto_pause_on_gate) which calls gateFor() itself.
     *
     * @return list<array{release_id: string, state: string, reasons: list<string>}>
     */
    public function evaluateGates(): array
    {
        $db     = Bt::db();
        $out    = [];
        $alerts = new AlertService();
        $rows   = $db->table('releases')->whereIn('status', ['PUBLISHED', 'PAUSED'])->where('published_at >=', gmdate('Y-m-d H:i:s', time() - 30 * 86400))
            ->whereIn('application_id', array_map('intval', array_column($db->table('bt_projects')->select('application_id')->get()->getResultArray(), 'application_id')) ?: [0])->get()->getResultArray();
        foreach ($rows as $r) {
            $gate = $this->gateFor((int) $r['id']);
            $out[] = ['release_id' => $r['public_id'], 'state' => $gate['state'], 'reasons' => $gate['reasons']];
            if ($gate['state'] === 'OK') {
                continue;
            }
            $dedupe = 'gate:' . $r['id'] . ':' . $gate['state'];
            if ($db->table('bt_alert_events')->where('dedupe_key', $dedupe)->countAllResults() > 0) {
                continue;
            }
            $payload = [
                'title'   => sprintf('Release gate %s: %s', $gate['state'], $r['version']),
                'body'    => implode('; ', $gate['reasons']) . ($gate['state'] === 'BLOCK' ? ((int) $r['auto_pause_on_gate'] ? ' — auto-pause is on for this release.' : ' — consider pausing the rollout.') : ''),
                'link'    => '/admin/bugtrace/health?release=' . $r['public_id'],
                'release' => ['id' => $r['public_id'], 'version' => $r['version']],
                'gate'    => $gate,
            ];
            $db->table('bt_alert_events')->insert(['rule_id' => null, 'kind' => 'gate', 'message' => $payload['title'], 'payload' => Bt::json($payload), 'dedupe_key' => $dedupe, 'delivered_at' => Bt::now(), 'created_at' => Bt::now(), 'updated_at' => Bt::now()]);
            service('notifier')->notify('rollout.alert', $payload, null, $gate['state'] === 'BLOCK');
            foreach ($db->table('bt_alert_rules')->where('enabled', 1)->where('condition', 'crash_free_below')->get()->getResultArray() as $rule) {
                $t = Bt::decode($rule['threshold'], []);
                if (isset($t['value']) && ($gate['crash_free_users'] === null || $gate['crash_free_users'] >= (float) $t['value'])) {
                    continue;
                }
                $alerts->fire($rule, 'crash_free_below', [], [], $dedupe . ':' . $rule['id'], $payload);
            }
        }

        return $out;
    }
}

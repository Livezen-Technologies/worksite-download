<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\Semver;

/**
 * Ring rollout and fleet health gate for WorkSuite Server releases
 * (WS-CORE-SYSUPDATE-SRS-001 FR-DL-RING-01/02, WF-SU-10, AC-SU-15).
 *
 *   ring 0 canary (Livezen-hosted and internal, 24 h) → ring 1 early (48 h)
 *   → ring 2 broad (72 h) → ring 3 conservative (14 days)
 *
 * Security and critical releases compress the rings to 0 → 2: rings 1 and 2 open
 * together once ring 0 has held. Ring state lives in release_rollouts (mode RINGS,
 * column `rings`); the plan endpoint reads the open time of an installation's ring.
 *
 * The health gate pauses the next ring automatically when one signal fails:
 * rollback rate over 2 %, failed runs over 5 %, Bug Trace crash-free under 99.0 %
 * or down 0.5 points against the previous release, or a new P0/P1 issue on 3 or
 * more installations. The Release Manager resumes, extends or withdraws.
 */
class ServerRingService
{
    public const RINGS = [0 => 'canary', 1 => 'early', 2 => 'broad', 3 => 'conservative'];

    public const LABELS = [0 => 'Ring 0 · Canary', 1 => 'Ring 1 · Early', 2 => 'Ring 2 · Broad', 3 => 'Ring 3 · Conservative'];

    /** How long each ring holds before the next one opens (hours). */
    public const HOLD_HOURS = [0 => 24, 1 => 48, 2 => 72, 3 => 336];

    /** Policy `ring` → ring number (ring 0 is only for Livezen-hosted / internal installations). */
    public const POLICY_RING = ['EARLY' => 1, 'BROAD' => 2, 'CONSERVATIVE' => 3];

    public const GATE = [
        'max_rollback_rate'      => 2.0,
        'max_failure_rate'       => 5.0,
        'min_crash_free'         => 99.0,
        'max_crash_free_drop'    => 0.5,
        'new_p0p1_installations' => 3,
    ];

    public const SECURITY_SEVERITIES = ['SECURITY', 'CRITICAL'];

    // ---- Classification ---------------------------------------------------

    public static function isServer(array $release): bool
    {
        return Catalog::appById((int) $release['application_id'])['kind'] === 'SERVER';
    }

    public static function meta(array $release): array
    {
        return db_connect()->table('release_server_meta')->where('release_id', $release['id'])->get()->getRowArray() ?? [];
    }

    /** PATCH | MINOR | MAJOR | SECURITY | CRITICAL — stated on the release, else derived from the version and type. */
    public static function severity(array $release, ?array $meta = null): string
    {
        $meta ??= self::meta($release);
        if (! empty($meta['severity'])) {
            return strtoupper((string) $meta['severity']);
        }
        if ($release['release_type'] === 'CRITICAL') {
            return 'CRITICAL';
        }
        $p = Semver::parse($release['version']);

        return $p['patch'] === 0 ? ($p['minor'] === 0 ? 'MAJOR' : 'MINOR') : 'PATCH';
    }

    public static function compressed(array $release, ?array $meta = null): bool
    {
        return in_array(self::severity($release, $meta), self::SECURITY_SEVERITIES, true) || $release['release_type'] === 'CRITICAL';
    }

    /** The ring that holds before $ring opens (compressed: 1 and 2 both follow 0). */
    public static function previousRing(int $ring, bool $compressed): ?int
    {
        if ($ring === 0) {
            return null;
        }

        return $compressed && $ring === 2 ? 0 : $ring - 1;
    }

    /**
     * Ring of an installation: admin override, else Livezen-hosted (MANAGED) → 0,
     * else the policy's preferred ring (default broad).
     */
    public static function ringOf(array $inst, ?string $policyRing = null): int
    {
        if (isset($inst['ring']) && $inst['ring'] !== null && $inst['ring'] !== '') {
            return max(0, min(3, (int) $inst['ring']));
        }
        if (($inst['mode'] ?? '') === 'MANAGED') {
            return 0;
        }

        return self::POLICY_RING[strtoupper((string) $policyRing)] ?? 2;
    }

    /** @return array<int, string> server_installations.id => policy ring */
    public static function policyRings(): array
    {
        return array_column(db_connect()->table('server_auto_update_policies')->select('installation_id, ring')->get()->getResultArray(), 'ring', 'installation_id');
    }

    // ---- State --------------------------------------------------------------

    private function row(array $release): ?array
    {
        return db_connect()->table('release_rollouts')->where('release_id', $release['id'])->get()->getRowArray();
    }

    /** @return array{rings: array<int, array{opened_at: ?string, extended_hours: int}>, pause: ?array, gate: ?array, ack: list<string>} */
    public function state(array $release): array
    {
        $row   = $this->row($release);
        $state = json_decode((string) ($row['rings'] ?? ''), true);
        if (! is_array($state)) {
            $state = [];
        }
        $rings = [];
        // Releases published before ring rollouts existed (no RINGS state) count as open in every ring.
        $legacy = ($row === null || $row['mode'] !== 'RINGS') && in_array($release['status'], ['PUBLISHED', 'SUPERSEDED'], true) && $release['published_at'] !== null;
        foreach (array_keys(self::RINGS) as $n) {
            $r         = $state['rings'][$n] ?? $state['rings'][(string) $n] ?? [];
            $rings[$n] = ['opened_at' => $r['opened_at'] ?? ($legacy ? Catalog::iso($release['published_at']) : null), 'extended_hours' => (int) ($r['extended_hours'] ?? 0)];
        }

        return ['rings' => $rings, 'pause' => $state['pause'] ?? null, 'gate' => $state['gate'] ?? null, 'ack' => (array) ($state['ack'] ?? [])];
    }

    private function save(array $release, array $state, array $extra = []): void
    {
        db_connect()->table('release_rollouts')->where('release_id', $release['id'])->update(['rings' => json_encode($state), 'updated_at' => Catalog::now()] + $extra);
        UpdateResolverService::bumpCacheEpoch();
    }

    /** Publish: ring 0 opens now (WF-SU-01). */
    public function start(array $release): void
    {
        (new RolloutService())->ensure($release, Catalog::channelById((int) $release['channel_id'])['code']);
        $state = ['rings' => [], 'pause' => null, 'gate' => null, 'ack' => []];
        foreach (array_keys(self::RINGS) as $n) {
            $state['rings'][$n] = ['opened_at' => $n === 0 ? Catalog::iso(Catalog::now()) : null, 'extended_hours' => 0];
        }
        $this->save($release, $state, [
            'mode' => 'RINGS', 'status' => 'ACTIVE', 'stages' => json_encode(array_values(self::HOLD_HOURS)), 'stage_no' => 0, 'percentage' => 0,
            'auto_advance' => 1, 'auto_pause' => 1, 'thresholds' => json_encode(self::GATE),
            'started_at' => Catalog::now(), 'stage_started_at' => Catalog::now(), 'paused_at' => null, 'completed_at' => null,
        ]);
        service('audit')->record('server.ring_opened', 'release', $release['public_id'], null, ['ring' => 0, 'compressed' => self::compressed($release)], null, self::system());
    }

    public static function system(): array
    {
        return ['type' => 'SYSTEM', 'id' => 'ring-scheduler', 'label' => 'server ring scheduler'];
    }

    /** Unix time ring $ring opened, or the projected opening time; null when it cannot be projected (paused). */
    public function openAt(array $release, int $ring, ?array $state = null): ?int
    {
        $state ??= $this->state($release);
        $opened = $state['rings'][$ring]['opened_at'] ?? null;
        if ($opened !== null) {
            return strtotime($opened);
        }
        if ($release['status'] !== 'PUBLISHED' || $state['pause'] !== null) {
            return null;
        }
        $prev = self::previousRing($ring, self::compressed($release));
        if ($prev === null) {
            return null;
        }
        $prevOpen = $this->openAt($release, $prev, $state);

        return $prevOpen === null ? null : $prevOpen + $this->holdSeconds($prev, $state);
    }

    public function holdSeconds(int $ring, array $state): int
    {
        return (self::HOLD_HOURS[$ring] + (int) ($state['rings'][$ring]['extended_hours'] ?? 0)) * 3600;
    }

    public function isOpen(array $release, int $ring, ?array $state = null): bool
    {
        $state ??= $this->state($release);

        return ($state['rings'][$ring]['opened_at'] ?? null) !== null;
    }

    /** Highest ring that is open (-1 before publish). */
    public function currentRing(array $state): int
    {
        $cur = -1;
        foreach ($state['rings'] as $n => $r) {
            if ($r['opened_at'] !== null) {
                $cur = max($cur, (int) $n);
            }
        }

        return $cur;
    }

    /** Next ring still waiting to open, or null when every ring is open. */
    public function nextRing(array $release, array $state): ?int
    {
        foreach (array_keys(self::RINGS) as $n) {
            if ($state['rings'][$n]['opened_at'] === null) {
                return $n;
            }
        }

        return null;
    }

    // ---- Health gate ----------------------------------------------------------

    /**
     * Gate signals from run reports (server_update_events) and Bug Trace release health.
     *
     * @return array{state: string, signals: list<array<string, mixed>>, reasons: list<string>, runs: int, evaluated_at: string}
     */
    public function gate(array $release): array
    {
        $db    = db_connect();
        $byType = [];
        foreach ($db->table('server_update_events')->select('type, installation_id')->where('release_id', $release['id'])->whereIn('type', ['started', 'completed', 'failed', 'rolled_back'])->get()->getResultArray() as $e) {
            $byType[$e['type']][(int) $e['installation_id']] = true;
        }
        $all  = [];
        foreach ($byType as $ids) {
            $all += $ids;
        }
        $runs       = count($all);
        $rolledBack = count($byType['rolled_back'] ?? []);
        $failed     = count($byType['failed'] ?? []);
        $rbRate     = $runs > 0 ? round(100 * $rolledBack / $runs, 1) : 0.0;
        $failRate   = $runs > 0 ? round(100 * $failed / $runs, 1) : 0.0;

        $signals   = [];
        $reasons   = [];
        $watchOrFail = static function (float $value, float $limit, bool $above = true): string {
            $bad = $above ? $value > $limit : $value < $limit;
            if ($bad) {
                return 'FAIL';
            }
            $near = $above ? $value >= $limit / 2 && $value > 0 : $value < $limit + 0.5;

            return $near ? 'WATCH' : 'OK';
        };

        $s = $watchOrFail($rbRate, self::GATE['max_rollback_rate']);
        $signals[] = ['key' => 'rollback_rate', 'label' => 'Rollback rate', 'value' => $rbRate, 'display' => $runs > 0 ? "{$rolledBack} of {$runs} · " . self::pct($rbRate) : '0 %', 'threshold' => 'over ' . self::pct(self::GATE['max_rollback_rate']), 'state' => $s];
        if ($s === 'FAIL') {
            $reasons[] = sprintf('Rollback rate %s (%d of %d installations) is over %s', self::pct($rbRate), $rolledBack, $runs, self::pct(self::GATE['max_rollback_rate']));
        }

        $s = $watchOrFail($failRate, self::GATE['max_failure_rate']);
        $signals[] = ['key' => 'failed_runs', 'label' => 'Failed runs', 'value' => $failRate, 'display' => $runs > 0 ? "{$failed} of {$runs} · " . self::pct($failRate) : '0 %', 'threshold' => 'over ' . self::pct(self::GATE['max_failure_rate']), 'state' => $s];
        if ($s === 'FAIL') {
            $reasons[] = sprintf('Failed runs %s (%d of %d) are over %s', self::pct($failRate), $failed, $runs, self::pct(self::GATE['max_failure_rate']));
        }

        // Bug Trace release health (crash-free and new P0/P1 issues), when the module is present.
        [$cf, $drop, $cfEnough] = $this->crashFree($release);
        $cfState = 'OK';
        if ($cfEnough && $cf !== null) {
            if ($cf < self::GATE['min_crash_free'] || ($drop !== null && $drop > self::GATE['max_crash_free_drop'])) {
                $cfState   = 'FAIL';
                $reasons[] = $cf < self::GATE['min_crash_free'] ? sprintf('Crash-free sessions %.2f %% are under %.1f %%', $cf, self::GATE['min_crash_free']) : sprintf('Crash-free sessions dropped %.2f points against the previous release', $drop);
            } elseif ($cf < self::GATE['min_crash_free'] + 0.5 || ($drop !== null && $drop > self::GATE['max_crash_free_drop'] / 2)) {
                $cfState = 'WATCH';
            }
        }
        $signals[] = ['key' => 'crash_free', 'label' => 'Crash-free sessions', 'value' => $cf, 'display' => $cf === null ? 'No data yet' : sprintf('%.1f %%', $cf) . ($drop !== null && $drop > 0 ? sprintf(' (−%.2f pt)', $drop) : ''), 'threshold' => sprintf('under %.1f %%, or down %.1f pt', self::GATE['min_crash_free'], self::GATE['max_crash_free_drop']), 'state' => $cfState];

        $issues = $this->newSevereIssues($release);
        $signals[] = ['key' => 'new_p0p1', 'label' => 'New P0/P1 on ' . self::GATE['new_p0p1_installations'] . '+ installations', 'value' => count($issues), 'display' => (string) count($issues), 'threshold' => '1 or more', 'state' => $issues === [] ? 'OK' : 'FAIL'];
        foreach ($issues as $i) {
            $reasons[] = sprintf('New %s issue %s on %d installations', $i['priority'], $i['public_id'], $i['installations_count']);
        }

        $states = array_column($signals, 'state');
        $state  = in_array('FAIL', $states, true) ? 'FAILING' : (in_array('WATCH', $states, true) ? 'WATCH' : 'PASSING');

        return ['state' => $state, 'signals' => $signals, 'reasons' => $reasons, 'runs' => $runs, 'evaluated_at' => gmdate('Y-m-d\TH:i:s\Z')];
    }

    private static function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . ' %';
    }

    /** @return array{0: ?float, 1: ?float, 2: bool} crash-free sessions, drop vs previous release, enough data */
    private function crashFree(array $release): array
    {
        if (! class_exists(\App\Services\BugTrace\ReleaseHealthService::class)) {
            return [null, null, false];
        }
        try {
            $svc   = new \App\Services\BugTrace\ReleaseHealthService();
            $rules = \App\Services\BugTrace\ReleaseHealthService::rules();
            $h     = $svc->metrics($release, (int) $rules['window_days']);
            $cf    = $h['crash_free_sessions'] ?? $h['crash_free_users'];
            $enough = $h['sessions'] >= (int) $rules['min_sessions'] || $h['users'] >= (int) $rules['min_sessions'];
            $prev  = db_connect()->table('releases')->where('application_id', $release['application_id'])->where('channel_id', $release['channel_id'])
                ->where('version_sort <', $release['version_sort'])->whereIn('status', ['PUBLISHED', 'SUPERSEDED', 'PAUSED'])->orderBy('version_sort', 'DESC')->limit(1)->get()->getRowArray();
            $drop = null;
            if ($prev && $cf !== null) {
                $ph  = $svc->metrics($prev, (int) $rules['window_days']);
                $pcf = $ph['crash_free_sessions'] ?? $ph['crash_free_users'];
                if ($pcf !== null && ($ph['sessions'] >= (int) $rules['min_sessions'] || $ph['users'] >= (int) $rules['min_sessions'])) {
                    $drop = round($pcf - $cf, 2);
                }
            }

            return [$cf, $drop, $enough];
        } catch (\Throwable $e) {
            log_message('warning', 'Server ring gate: Bug Trace health unavailable: ' . $e->getMessage());

            return [null, null, false];
        }
    }

    /** @return list<array{public_id: string, priority: string, installations_count: int}> */
    private function newSevereIssues(array $release): array
    {
        $db = db_connect();
        if (! $db->tableExists('bt_issues')) {
            return [];
        }
        $projects = array_map('intval', array_column($db->table('bt_projects')->select('id')->where('application_id', $release['application_id'])->get()->getResultArray(), 'id'));
        if ($projects === []) {
            return [];
        }

        return array_map(static fn ($r) => ['public_id' => $r['public_id'], 'priority' => $r['priority'], 'installations_count' => (int) $r['installations_count']],
            $db->table('bt_issues')->select('public_id, priority, installations_count')->whereIn('project_id', $projects)->where('first_release_id', $release['id'])
                ->whereIn('priority', ['P0', 'P1'])->where('installations_count >=', self::GATE['new_p0p1_installations'])
                ->whereIn('status', \App\Libraries\BugTrace\Bt::UNRESOLVED)->get()->getResultArray());
    }

    // ---- Scheduler / actions ---------------------------------------------------

    /** Scheduler (every 5 min): gate every active ring rollout, then open rings whose hold has passed. */
    public function evaluateAll(): array
    {
        $out  = [];
        $rows = db_connect()->table('release_rollouts ro')->select('ro.release_id')->join('releases r', 'r.id = ro.release_id')
            ->where('ro.mode', 'RINGS')->whereIn('ro.status', ['ACTIVE', 'PAUSED'])->where('r.status', 'PUBLISHED')->get()->getResultArray();
        foreach ($rows as $row) {
            $out = array_merge($out, $this->evaluate(Catalog::releaseById((int) $row['release_id'])));
        }

        return $out;
    }

    /** @return list<string> actions taken */
    public function evaluate(array $release): array
    {
        $row = $this->row($release);
        if (! $row || $row['mode'] !== 'RINGS' || $release['status'] !== 'PUBLISHED') {
            return [];
        }
        $state         = $this->state($release);
        $gate          = $this->gate($release);
        $state['gate'] = ['state' => $gate['state'], 'reasons' => $gate['reasons'], 'evaluated_at' => $gate['evaluated_at']];
        $out           = [];
        $next          = $this->nextRing($release, $state);

        $failing       = array_values(array_map(static fn ($sg) => $sg['key'], array_filter($gate['signals'], static fn ($sg) => $sg['state'] === 'FAIL')));
        $newFailure    = array_diff($failing, $state['ack']) !== [];

        if ($gate['state'] === 'FAILING' && $newFailure && $state['pause'] === null && $next !== null) {
            $reason         = implode('; ', $gate['reasons']);
            $state['pause'] = ['kind' => 'GATE', 'reason' => $reason, 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'by' => 'health gate', 'ring' => $next];
            $this->save($release, $state, ['status' => 'PAUSED', 'paused_at' => Catalog::now()]);
            service('audit')->record('server.ring_auto_paused', 'release', $release['public_id'], null, ['next_ring' => $next, 'signals' => array_column($gate['signals'], 'state', 'key')], $reason, self::system());
            service('notifier')->notify('rollout.alert', ['title' => "Server {$release['version']}: ring {$next} paused", 'body' => $reason . ' — resume, extend or withdraw in the rollout console.', 'link' => '/admin/server/releases/' . $release['public_id'] . '/rollout']);
            (new ServerAutoUpdateService())->holdPlans($release, $next, 'Health gate: ' . $reason);

            return ["paused ring {$next} of {$release['public_id']}: {$reason}"];
        }
        if ($state['pause'] !== null) {
            $this->save($release, $state);

            return [];
        }

        $now        = time();
        $compressed = self::compressed($release);
        foreach (array_keys(self::RINGS) as $n) {
            if ($n === 0 || $state['rings'][$n]['opened_at'] !== null) {
                continue;
            }
            $prev     = self::previousRing($n, $compressed);
            $prevOpen = $state['rings'][$prev]['opened_at'] ?? null;
            if ($prevOpen !== null && strtotime($prevOpen) + $this->holdSeconds($prev, $state) <= $now) {
                $state['rings'][$n]['opened_at'] = gmdate('Y-m-d\TH:i:s\Z', $now);
                $state['ack']                    = [];
                service('audit')->record('server.ring_opened', 'release', $release['public_id'], null, ['ring' => $n], null, self::system());
                $out[] = "opened ring {$n} of {$release['public_id']}";
            }
        }
        $extra = ['stage_no' => max(0, $this->currentRing($state))];
        $last  = $state['rings'][3]['opened_at'];
        if ($last !== null && strtotime($last) + $this->holdSeconds(3, $state) <= $now && $row['status'] !== 'DONE') {
            $extra += ['status' => 'DONE', 'completed_at' => Catalog::now(), 'percentage' => 100];
            $out[]  = "completed rings of {$release['public_id']}";
        }
        $this->save($release, $state, $extra);

        return $out;
    }

    /**
     * Release Manager actions on the ring rollout: pause | resume | extend.
     *
     * @param array{reason?: string, hours?: int} $in
     */
    public function action(array $release, string $action, array $in): array
    {
        if ($release['status'] !== 'PUBLISHED') {
            throw new ApiException('INVALID_TRANSITION', 'Rings can only change while the release is published', 409);
        }
        $row = $this->row($release);
        if (! $row || $row['mode'] !== 'RINGS') {
            throw new ApiException('INVALID_TRANSITION', 'This release has no ring rollout', 409);
        }
        $state  = $this->state($release);
        $before = ['status' => $row['status'], 'pause' => $state['pause']];
        $reason = trim((string) ($in['reason'] ?? ''));
        $next   = $this->nextRing($release, $state);
        switch ($action) {
            case 'pause':
                if ($state['pause'] !== null) {
                    throw new ApiException('INVALID_TRANSITION', 'The rollout is already paused', 409);
                }
                $state['pause'] = ['kind' => 'MANUAL', 'reason' => $reason, 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'by' => service('authContext')->actorLabel(), 'ring' => $next];
                $this->save($release, $state, ['status' => 'PAUSED', 'paused_at' => Catalog::now()]);
                (new ServerAutoUpdateService())->holdPlans($release, null, 'Rollout paused: ' . $reason);
                break;

            case 'resume':
                if ($state['pause'] === null) {
                    throw new ApiException('INVALID_TRANSITION', 'The rollout is not paused', 409);
                }
                $state['pause'] = null;
                // Resuming acknowledges the signals failing now: the gate pauses again only on a new signal
                // (or once the next ring has opened).
                $state['ack'] = array_values(array_map(static fn ($sg) => $sg['key'], array_filter($this->gate($release)['signals'], static fn ($sg) => $sg['state'] === 'FAIL')));
                $this->save($release, $state, ['status' => 'ACTIVE', 'paused_at' => null]);
                (new ServerAutoUpdateService())->releaseHeldPlans($release);
                break;

            case 'extend':
                $hours = (int) ($in['hours'] ?? 24);
                if ($hours < 1 || $hours > 336) {
                    throw ApiException::validation(['hours' => 'Extend by 1 to 336 hours']);
                }
                $cur = $this->currentRing($state);
                if ($cur < 0 || $next === null) {
                    throw new ApiException('INVALID_TRANSITION', 'Every ring is already open', 409);
                }
                $state['rings'][$cur]['extended_hours'] = (int) $state['rings'][$cur]['extended_hours'] + $hours;
                $this->save($release, $state);
                break;

            default:
                throw ApiException::notFound('UNKNOWN_ACTION', 'Unknown ring action');
        }
        $after = $this->state($release);
        service('audit')->record('server.ring_' . $action, 'release', $release['public_id'], $before, ['pause' => $after['pause'], 'rings' => $after['rings']] + ($action === 'extend' ? ['hours' => (int) ($in['hours'] ?? 24)] : []), $reason === '' ? null : $reason);

        return $this->present(Catalog::releaseById((int) $release['id']));
    }

    // ---- DL-3 view -------------------------------------------------------------

    public function present(array $release): array
    {
        $db         = db_connect();
        $row        = $this->row($release);
        $state      = $this->state($release);
        $meta       = self::meta($release);
        $compressed = self::compressed($release, $meta);
        $policies   = self::policyRings();
        $installs   = $db->table('server_installations')->get()->getResultArray();
        $byRing     = [0 => [], 1 => [], 2 => [], 3 => []];
        foreach ($installs as $i) {
            $byRing[self::ringOf($i, $policies[$i['id']] ?? null)][] = $i;
        }
        $updatedIn = static function (array $list) use ($release): int {
            $n = 0;
            foreach ($list as $i) {
                try {
                    if ($i['version'] && Semver::gte($i['version'], $release['version'])) {
                        $n++;
                    }
                } catch (\Throwable) {
                }
            }

            return $n;
        };
        $rings = [];
        $next  = $this->nextRing($release, $state);
        foreach (self::RINGS as $n => $key) {
            $openAt  = $this->openAt($release, $n, $state);
            $opened  = $state['rings'][$n]['opened_at'];
            $holdEnd = $opened !== null ? strtotime($opened) + $this->holdSeconds($n, $state) : null;
            $ringState = match (true) {
                $opened !== null && $holdEnd !== null && $holdEnd <= time() => 'DONE',
                $opened !== null                                            => 'OPEN',
                $state['pause'] !== null && $next === $n                    => 'PAUSED',
                default                                                     => 'WAITING',
            };
            $prev   = self::previousRing($n, $compressed);
            $detail = match ($ringState) {
                'DONE'    => ($n === 0 ? 'Livezen-hosted and internal · ' : '') . 'held ' . (int) round($this->holdSeconds($n, $state) / 3600) . ' h',
                'OPEN'    => $n < 3 && $holdEnd !== null ? 'Next ring opens in ' . max(0, (int) ceil(($holdEnd - time()) / 3600)) . ' h' : 'Held ' . (int) round($this->holdSeconds($n, $state) / 3600 / 24) . ' days',
                'PAUSED'  => 'Paused: ' . ($state['pause']['reason'] ?? ''),
                default   => $prev === null ? 'Opens at publish' : 'Opens after ' . (int) round($this->holdSeconds($prev, $state) / 3600) . ' h of ring ' . $prev . ($compressed && $n === 2 ? ' (compressed with ring 1)' : ''),
            };
            $rings[] = [
                'ring' => $n, 'key' => $key, 'name' => self::LABELS[$n], 'state' => $ringState,
                'opened_at' => $opened, 'opens_at' => $openAt === null ? null : gmdate('Y-m-d\TH:i:s\Z', $openAt),
                'hold_hours' => self::HOLD_HOURS[$n], 'extended_hours' => (int) $state['rings'][$n]['extended_hours'],
                'hold_ends_at' => $holdEnd === null ? null : gmdate('Y-m-d\TH:i:s\Z', $holdEnd),
                'installations' => count($byRing[$n]), 'updated' => $updatedIn($byRing[$n]), 'detail' => $detail,
            ];
        }
        $gate = $this->gate($release);
        $campaign = (new ServerUpdateService())->campaign($release);
        $deliveries = $campaign ? array_column($db->table('campaign_deliveries')->select('channel, COUNT(*) AS c')->where('campaign_id', $campaign['id'])->groupBy('channel')->get()->getResultArray(), 'c', 'channel') : [];

        return [
            'release'    => ['id' => $release['public_id'], 'version' => $release['version'], 'build' => (int) $release['build_number'], 'status' => $release['status'], 'channel' => Catalog::channelById((int) $release['channel_id'])['code'], 'severity' => self::severity($release, $meta), 'published_at' => Catalog::iso($release['published_at']), 'withdrawn_at' => Catalog::iso($release['withdrawn_at'])],
            'status'     => $row['status'] ?? 'PENDING',
            'mode'       => $row['mode'] ?? null,
            'compressed' => $compressed,
            'pause'      => $state['pause'],
            'rings'      => $rings,
            'gate'       => $gate,
            'outcomes'   => (new ServerAutoUpdateService())->outcomes($release, $installs),
            'campaign'   => $campaign ? $campaign + ['delivered' => ['in_app' => (int) ($deliveries['webhook'] ?? 0), 'email' => (int) ($deliveries['email'] ?? 0)]] : null,
            'thresholds' => self::GATE,
        ];
    }
}

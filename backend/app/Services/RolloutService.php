<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\Semver;

/**
 * Staged rollouts with deterministic cohorts (§4.7, FR-UC-ROL-01..06).
 * cohort = SHA-256(release_id ∥ device_ref) mod 10000 < pct × 100.
 */
class RolloutService
{
    public const DEFAULT_STAGES = [5, 25, 50, 100];

    /** Rates are percentages (0–100), like every rate in the API. */
    public const DEFAULT_THRESHOLDS = ['dwell_hours' => 24, 'min_success_rate' => 97.0, 'max_failure_rate' => 5.0, 'max_rollback_rate' => 2.0];

    /** Merge stored thresholds with defaults; legacy fractional values (≤ 1) are read as fractions. */
    public static function thresholds(?string $json): array
    {
        $t = array_merge(self::DEFAULT_THRESHOLDS, json_decode((string) $json, true) ?: []);
        foreach (['min_success_rate', 'max_failure_rate', 'max_rollback_rate'] as $k) {
            $t[$k] = (float) $t[$k];
            if ($t[$k] > 0 && $t[$k] <= 1) {
                $t[$k] *= 100;
            }
        }
        $t['dwell_hours'] = (float) $t['dwell_hours'];

        return $t;
    }

    public static function cohortBucket(string $releasePublicId, string $deviceRef): int
    {
        // First 48 bits of the digest keep the arithmetic in native ints.
        return hexdec(substr(hash('sha256', $releasePublicId . $deviceRef), 0, 12)) % 10000;
    }

    public static function inCohort(string $releasePublicId, ?string $deviceRef, int $percentage): bool
    {
        if ($percentage >= 100) {
            return true;
        }
        if ($deviceRef === null || $deviceRef === '') {
            return false; // anonymous checks only see fully rolled-out releases
        }

        return self::cohortBucket($releasePublicId, $deviceRef) < $percentage * 100;
    }

    public function ensure(array $release, string $channelCode): array
    {
        $db  = db_connect();
        $row = $db->table('release_rollouts')->where('release_id', $release['id'])->get()->getRowArray();
        if ($row) {
            return $row;
        }
        $staged = $channelCode === 'STABLE';
        $db->table('release_rollouts')->insert([
            'release_id'   => $release['id'],
            'status'       => 'PENDING',
            'mode'         => $staged ? 'STAGED' : 'FULL',
            'stages'       => json_encode($staged ? self::DEFAULT_STAGES : [100]),
            'stage_no'     => 0,
            'percentage'   => 0,
            'auto_advance' => 0,
            'auto_pause'   => 1,
            'thresholds'   => json_encode(self::DEFAULT_THRESHOLDS),
            'created_at'   => Catalog::now(),
            'updated_at'   => Catalog::now(),
        ]);

        return $db->table('release_rollouts')->where('release_id', $release['id'])->get()->getRowArray();
    }

    public function configure(array $release, array $in): array
    {
        $db   = db_connect();
        $row  = $this->ensure($release, Catalog::channelById((int) $release['channel_id'])['code']);
        $mode = strtoupper((string) ($in['mode'] ?? $row['mode']));
        if (! in_array($mode, ['FULL', 'STAGED'], true)) {
            throw ApiException::validation(['mode' => 'FULL or STAGED']);
        }
        $stages = $mode === 'FULL' ? [100] : array_values(array_map('intval', (array) ($in['stages'] ?? json_decode($row['stages'], true))));
        sort($stages);
        if ($stages === [] || end($stages) !== 100 || min($stages) < 1 || count(array_unique($stages)) !== count($stages)) {
            throw ApiException::validation(['stages' => 'Stages must be increasing percentages ending at 100']);
        }
        $thresholds = array_merge(self::thresholds($row['thresholds']), array_intersect_key((array) ($in['thresholds'] ?? []), self::DEFAULT_THRESHOLDS));
        foreach (['min_success_rate', 'max_failure_rate', 'max_rollback_rate'] as $k) {
            if (! is_numeric($thresholds[$k]) || $thresholds[$k] < 0 || $thresholds[$k] > 100) {
                throw ApiException::validation(['thresholds' => "{$k} must be a percentage between 0 and 100"]);
            }
        }
        $before     = $this->present($release);
        $update     = [
            'mode'         => $mode,
            'stages'       => json_encode($stages),
            'auto_advance' => ! empty($in['auto_advance'] ?? $row['auto_advance']) ? 1 : 0,
            'auto_pause'   => ! empty($in['auto_pause'] ?? $row['auto_pause']) ? 1 : 0,
            'thresholds'   => json_encode($thresholds),
            'updated_at'   => Catalog::now(),
        ];
        if ($row['status'] === 'ACTIVE' && (int) $row['percentage'] > 0) {
            // Keep the current percentage; move stage pointer to the first stage ≥ current.
            foreach ($stages as $i => $s) {
                if ($s >= (int) $row['percentage']) {
                    $update['stage_no'] = $i + 1;
                    break;
                }
            }
        }
        $db->table('release_rollouts')->where('id', $row['id'])->update($update);
        if (array_key_exists('rules', $in)) {
            $db->table('release_rollout_rules')->where('rollout_id', $row['id'])->delete();
            foreach ((array) $in['rules'] as $rule) {
                $kind = (string) ($rule['kind'] ?? '');
                if (! in_array($kind, ['tenant', 'org', 'platform', 'arch', 'version_range'], true) || trim((string) ($rule['value'] ?? '')) === '') {
                    throw ApiException::validation(['rules' => 'Rule kinds: tenant, org, platform, arch, version_range']);
                }
                $db->table('release_rollout_rules')->insert(['rollout_id' => $row['id'], 'kind' => $kind, 'value' => trim((string) $rule['value']), 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
            }
        }
        $after = $this->present($release);
        service('audit')->record('rollout.configured', 'release', $release['public_id'], self::auditShape($before), self::auditShape($after));
        UpdateResolverService::bumpCacheEpoch();

        return $after;
    }

    /** Start stage 1 at publish time. */
    public function start(array $release): void
    {
        $row    = $this->ensure($release, Catalog::channelById((int) $release['channel_id'])['code']);
        $stages = json_decode($row['stages'], true) ?: [100];
        db_connect()->table('release_rollouts')->where('id', $row['id'])->update([
            'status' => $stages[0] >= 100 ? 'DONE' : 'ACTIVE', 'stage_no' => 1, 'percentage' => $stages[0],
            'started_at' => Catalog::now(), 'stage_started_at' => Catalog::now(), 'completed_at' => $stages[0] >= 100 ? Catalog::now() : null, 'updated_at' => Catalog::now(),
        ]);
    }

    public function action(array $release, string $action, array $in = [], bool $system = false): array
    {
        $db  = db_connect();
        $row = $this->ensure($release, Catalog::channelById((int) $release['channel_id'])['code']);
        if (! in_array($release['status'], ['PUBLISHED', 'PAUSED'], true)) {
            throw new ApiException('INVALID_TRANSITION', 'Rollout can only change for published releases', 409);
        }
        $stages = json_decode($row['stages'], true) ?: [100];
        $now    = Catalog::now();
        $before = self::auditShape($this->present($release));
        switch ($action) {
            case 'pause':
                if ($row['status'] !== 'ACTIVE') {
                    throw new ApiException('INVALID_TRANSITION', 'Rollout is not active', 409);
                }
                $db->table('release_rollouts')->where('id', $row['id'])->update(['status' => 'PAUSED', 'paused_at' => $now, 'updated_at' => $now]);
                break;

            case 'resume':
                if ($row['status'] !== 'PAUSED') {
                    throw new ApiException('INVALID_TRANSITION', 'Rollout is not paused', 409);
                }
                $db->table('release_rollouts')->where('id', $row['id'])->update(['status' => 'ACTIVE', 'paused_at' => null, 'updated_at' => $now]);
                break;

            case 'increase':
                if ($row['status'] === 'DONE') {
                    throw new ApiException('INVALID_TRANSITION', 'Rollout already complete', 409);
                }
                $target = isset($in['percentage']) ? (int) $in['percentage'] : null;
                $next   = null;
                foreach ($stages as $i => $s) {
                    if ($s > (int) $row['percentage'] && ($target === null || $s <= $target)) {
                        $next = [$i + 1, $s];
                        if ($target === null) {
                            break;
                        }
                    }
                }
                if ($target !== null && ($target <= (int) $row['percentage'] || $target > 100)) {
                    throw ApiException::validation(['percentage' => 'Must be greater than the current percentage and ≤ 100']);
                }
                [$stageNo, $pct] = $target !== null ? [$next[0] ?? (int) $row['stage_no'], $target] : ($next ?? [count($stages), 100]);
                $db->table('release_rollouts')->where('id', $row['id'])->update([
                    'stage_no' => $stageNo, 'percentage' => $pct, 'stage_started_at' => $now,
                    'status' => $pct >= 100 ? 'DONE' : ($row['status'] === 'PENDING' ? 'ACTIVE' : $row['status']),
                    'completed_at' => $pct >= 100 ? $now : null, 'updated_at' => $now,
                ]);
                break;

            case 'complete':
                $db->table('release_rollouts')->where('id', $row['id'])->update(['stage_no' => count($stages), 'percentage' => 100, 'status' => 'DONE', 'completed_at' => $now, 'stage_started_at' => $now, 'updated_at' => $now]);
                break;

            default:
                throw ApiException::notFound('UNKNOWN_ACTION', 'Unknown rollout action');
        }
        $after = $this->present($release);
        service('audit')->record('rollout.' . $action, 'release', $release['public_id'], $before, self::auditShape($after), $in['reason'] ?? null, $system ? ['type' => 'SYSTEM', 'id' => 'scheduler', 'label' => 'rollout scheduler'] : null);
        if ($action === 'pause') {
            service('notifier')->notify('rollout.paused', ['title' => 'Rollout paused', 'body' => ($in['reason'] ?? 'Paused') . ' — release ' . $release['version'], 'link' => '/admin/releases/' . $release['public_id'] . '?tab=rollout']);
        }
        UpdateResolverService::bumpCacheEpoch();

        return $after;
    }

    private static function auditShape(array $p): array
    {
        return ['status' => $p['status'], 'mode' => $p['mode'], 'stages' => $p['stages'], 'percentage' => $p['percentage'], 'auto_advance' => $p['auto_advance']];
    }

    /** Rules as kind => values. */
    public function rules(int $rolloutId): array
    {
        $out = [];
        foreach (db_connect()->table('release_rollout_rules')->where('rollout_id', $rolloutId)->get()->getResultArray() as $r) {
            $out[$r['kind']][] = $r['value'];
        }

        return $out;
    }

    /**
     * @param array{tenant?:?string, org?:?string, platform:string, arch:string, current_version:string} $ctx
     */
    public static function matchesRules(array $rules, array $ctx): bool
    {
        foreach ($rules as $kind => $values) {
            $ok = false;
            foreach ($values as $v) {
                $ok = match ($kind) {
                    'tenant'        => ($ctx['tenant'] ?? null) === $v,
                    'org'           => (string) ($ctx['org'] ?? '') === $v || ($ctx['tenant'] ?? '') . ':' . ($ctx['org'] ?? '') === $v,
                    'platform'      => $ctx['platform'] === $v,
                    'arch'          => $ctx['arch'] === $v,
                    'version_range' => self::safeSatisfies($ctx['current_version'], $v),
                    default         => true,
                };
                if ($ok) {
                    break;
                }
            }
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private static function safeSatisfies(string $v, string $range): bool
    {
        try {
            return Semver::satisfies($v, $range);
        } catch (\Throwable) {
            return false;
        }
    }

    public function present(array $release): array
    {
        $db  = db_connect();
        $row = $db->table('release_rollouts')->where('release_id', $release['id'])->get()->getRowArray();
        if (! $row) {
            return ['status' => 'PENDING', 'mode' => 'FULL', 'stages' => [100], 'stage_no' => 0, 'percentage' => 0, 'started_at' => null, 'paused_at' => null, 'completed_at' => null, 'auto_advance' => false, 'auto_pause' => true, 'thresholds' => self::DEFAULT_THRESHOLDS, 'rules' => [], 'progress' => []];
        }
        $stages = json_decode($row['stages'], true) ?: [100];
        $rules  = array_map(static fn ($r) => ['kind' => $r['kind'], 'value' => $r['value']], $db->table('release_rollout_rules')->where('rollout_id', $row['id'])->get()->getResultArray());

        return [
            'status'       => $row['status'],
            'mode'         => $row['mode'],
            'stages'       => $stages,
            'stage_no'     => (int) $row['stage_no'],
            'percentage'   => (int) $row['percentage'],
            'started_at'   => Catalog::iso($row['started_at']),
            'stage_started_at' => Catalog::iso($row['stage_started_at']),
            'paused_at'    => Catalog::iso($row['paused_at']),
            'completed_at' => Catalog::iso($row['completed_at']),
            'auto_advance' => (bool) $row['auto_advance'],
            'auto_pause'   => (bool) $row['auto_pause'],
            'thresholds'   => self::thresholds($row['thresholds']),
            'rules'        => $rules,
            'progress'     => $this->progress($release, $stages, (int) $row['stage_no']),
        ];
    }

    /** Per-stage eligible / updated / failed / rolled back (FR-UC-ROL-05) from telemetry. */
    public function progress(array $release, array $stages, int $currentStage): array
    {
        $stats = (new TelemetryService())->releaseStats((int) $release['id']);
        $out   = [];
        foreach ($stages as $i => $pct) {
            $reached = $i + 1 <= $currentStage;
            $out[]   = [
                'stage'        => $i + 1,
                'percentage'   => $pct,
                'reached'      => $reached,
                'eligible'     => $reached ? (int) round($stats['eligible_devices'] * $pct / 100) : 0,
                'updated'      => $i + 1 === $currentStage ? $stats['installed'] : ($reached ? null : 0),
                'failed'       => $i + 1 === $currentStage ? $stats['failed'] : ($reached ? null : 0),
                'rolled_back'  => $i + 1 === $currentStage ? $stats['rolled_back'] : ($reached ? null : 0),
                'success_rate' => $i + 1 === $currentStage ? $stats['success_rate'] : null,
            ];
        }

        return $out;
    }

    /**
     * Scheduler: auto-advance after dwell when success ≥ threshold; auto-pause on
     * failure / rollback spikes or a blocking Bug Trace gate (WF-C12, WF-C36).
     *
     * @return list<string> actions taken
     */
    public function evaluateAll(): array
    {
        $db      = db_connect();
        $actions = [];
        $rows    = $db->table('release_rollouts ro')->select('ro.*, r.public_id, r.version, r.auto_pause_on_gate')->join('releases r', 'r.id = ro.release_id')
            ->where('ro.status', 'ACTIVE')->where('r.status', 'PUBLISHED')->get()->getResultArray();
        foreach ($rows as $ro) {
            $release = Catalog::releaseById((int) $ro['release_id']);
            $t       = self::thresholds($ro['thresholds']);
            $stats   = (new TelemetryService())->releaseStats((int) $ro['release_id']);
            $attempts = $stats['installed'] + $stats['failed'];
            $failRate = $attempts > 0 ? $stats['failed'] * 100 / $attempts : 0.0;
            $rbRate   = $attempts > 0 ? $stats['rolled_back'] * 100 / $attempts : 0.0;

            $gate = null;
            if (class_exists(\App\Services\BugTrace\ReleaseHealthService::class)) {
                try {
                    $gate = (new \App\Services\BugTrace\ReleaseHealthService())->gateFor((int) $ro['release_id']);
                } catch (\Throwable) {
                    $gate = null;
                }
            }

            $problem = null;
            if ($attempts >= 20 && $failRate > $t['max_failure_rate']) {
                $problem = sprintf('Install failure rate %.1f%% above %.1f%%', $failRate, $t['max_failure_rate']);
            } elseif ($attempts >= 20 && $rbRate > $t['max_rollback_rate']) {
                $problem = sprintf('Rollback rate %.1f%% above %.1f%%', $rbRate, $t['max_rollback_rate']);
            } elseif (($gate['state'] ?? 'OK') === 'BLOCK') {
                $problem = 'Bug Trace gate: ' . implode('; ', $gate['reasons'] ?? []);
            }
            if ($problem !== null) {
                $pause = $ro['auto_pause'] && (($gate['state'] ?? 'OK') !== 'BLOCK' || $ro['auto_pause_on_gate']);
                service('notifier')->notify('rollout.alert', ['title' => "Rollout alert: {$ro['version']}", 'body' => $problem . ($pause ? ' — rollout paused automatically.' : ' — review and pause if needed.'), 'link' => '/admin/releases/' . $ro['public_id'] . '?tab=rollout']);
                if ($pause) {
                    $this->action($release, 'pause', ['reason' => $problem], true);
                    $actions[] = "paused {$ro['public_id']}: {$problem}";
                } else {
                    $actions[] = "alerted {$ro['public_id']}: {$problem}";
                }

                continue;
            }
            if ($ro['auto_advance'] && $ro['stage_started_at'] && strtotime($ro['stage_started_at'] . ' UTC') <= time() - (int) $t['dwell_hours'] * 3600) {
                $success = $stats['success_rate'];
                if ($success === null || $success >= $t['min_success_rate']) {
                    $this->action($release, 'increase', ['reason' => 'Auto-advance after dwell time'], true);
                    $actions[] = "advanced {$ro['public_id']}";
                }
            }
        }

        return $actions;
    }
}

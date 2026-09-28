<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\CdnPurgeJob;
use App\Jobs\PrerenderJob;
use App\Jobs\SubscriberNotifyJob;
use App\Libraries\Queue;
use App\Libraries\Semver;
use App\Libraries\Ulid;

/**
 * Release lifecycle (§4.3, FR-UC-REL-01..14, WF-C02/C05/C06/C10/C13/C14/C15).
 *
 *   DRAFT ⇄ UPLOADING → READY_FOR_TEST → TESTING → APPROVED → PUBLISHED ⇄ PAUSED
 *   PUBLISHED|PAUSED → WITHDRAWN (terminal) | SUPERSEDED
 */
class ReleaseService
{
    public const TRANSITIONS = [
        'DRAFT'          => ['UPLOADING', 'READY_FOR_TEST'],
        'UPLOADING'      => ['DRAFT', 'READY_FOR_TEST'],
        'READY_FOR_TEST' => ['TESTING', 'DRAFT', 'UPLOADING'],
        'TESTING'        => ['APPROVED', 'DRAFT', 'UPLOADING'],
        'APPROVED'       => ['PUBLISHED', 'DRAFT'],
        'PUBLISHED'      => ['PAUSED', 'WITHDRAWN', 'SUPERSEDED'],
        'PAUSED'         => ['PUBLISHED', 'WITHDRAWN', 'SUPERSEDED'],
        'WITHDRAWN'      => [],
        'SUPERSEDED'     => ['WITHDRAWN'],
    ];

    public const TYPES = ['OPTIONAL', 'RECOMMENDED', 'MANDATORY', 'CRITICAL'];

    public const SUPPORT_STATES = ['SUPPORTED', 'DEPRECATED', 'UNSUPPORTED', 'BLOCKED'];

    public const PRE_PUBLISH = ['DRAFT', 'UPLOADING', 'READY_FOR_TEST', 'TESTING', 'APPROVED'];

    private AuditService $audit;

    public function __construct(private readonly ?AuthContext $auth = null)
    {
        $this->audit = service('audit');
    }

    private function ctx(): AuthContext
    {
        return $this->auth ?? service('authContext');
    }

    public function transition(array $release, string $to, array $extra = []): array
    {
        $from = $release['status'];
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new ApiException('INVALID_TRANSITION', "Release cannot move from {$from} to {$to}", 409, ['from' => $from, 'to' => $to]);
        }
        db_connect()->table('releases')->where('id', $release['id'])->where('status', $from)->update(['status' => $to, 'updated_at' => Catalog::now()] + $extra);
        if (db_connect()->affectedRows() !== 1) {
            throw new ApiException('INVALID_TRANSITION', 'Release status changed concurrently', 409);
        }

        return Catalog::releaseById((int) $release['id']);
    }

    // ---- Create / edit ---------------------------------------------------

    public function create(array $in): array
    {
        $app = Catalog::app((string) ($in['app_id'] ?? $in['app'] ?? ''));
        if ($app['status'] === 'RETIRED') {
            throw new ApiException('APP_RETIRED', 'Application is retired', 409);
        }
        $version = trim((string) ($in['version'] ?? ''));
        $errors  = [];
        if (! Semver::isValid($version)) {
            $errors['version'] = 'Version must be SemVer (e.g. 2.7.0 or 2.8.0-beta.2)';
        }
        $build = filter_var($in['build'] ?? $in['build_number'] ?? null, FILTER_VALIDATE_INT);
        if ($build === false || $build <= 0) {
            $errors['build'] = 'Build number must be a positive integer';
        }
        $type = strtoupper((string) ($in['release_type'] ?? 'RECOMMENDED'));
        if (! in_array($type, self::TYPES, true)) {
            $errors['release_type'] = 'OPTIONAL, RECOMMENDED, MANDATORY or CRITICAL';
        }
        if (! empty($in['minimum_supported_version']) && ! Semver::isValid((string) $in['minimum_supported_version'])) {
            $errors['minimum_supported_version'] = 'Invalid SemVer';
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
        $version = ltrim($version, 'v');
        $channel = Catalog::channel((string) ($in['channel'] ?? 'STABLE'), (int) $app['id']);
        $db      = db_connect();
        $enabled = $db->table('application_channels')->where('application_id', $app['id'])->where('channel_id', $channel['id'])->where('enabled', 1)->countAllResults();
        if (! $enabled) {
            throw ApiException::validation(['channel' => "Channel {$channel['code']} is not enabled for {$app['name']}"]);
        }

        $existing = $db->table('releases')->where('application_id', $app['id'])->where('channel_id', $channel['id'])->where('version', $version)->get()->getRowArray();
        if ($existing) {
            // CI idempotency on app+channel+version+build (WF-C03).
            if ((int) $existing['build_number'] === $build && $this->ctx()->apiClient !== null) {
                return $existing;
            }

            throw ApiException::conflict('RELEASE_EXISTS', "{$app['name']} {$version} already exists on {$channel['code']}", ['release_id' => $existing['public_id']]);
        }
        $maxBuild = (int) $db->table('releases')->selectMax('build_number')->where('application_id', $app['id'])->where('channel_id', $channel['id'])->get()->getRow('build_number');
        if ($build <= $maxBuild) {
            throw new ApiException('BUILD_NOT_INCREASING', "Build must be greater than {$maxBuild} (latest on {$channel['code']})", 422, ['previous_build' => $maxBuild]);
        }

        $p   = Semver::parse($version);
        $now = Catalog::now();
        $mandatory = ! empty($in['mandatory']) || $type === 'MANDATORY';
        $row = [
            'public_id' => Ulid::prefixed('rel'), 'application_id' => $app['id'], 'channel_id' => $channel['id'],
            'version' => $version, 'version_major' => $p['major'], 'version_minor' => $p['minor'], 'version_patch' => $p['patch'], 'version_pre' => $p['pre'],
            'version_sort' => Semver::sortKey($version), 'build_number' => $build, 'build_revision' => 0,
            'release_type' => $type, 'status' => 'DRAFT', 'support_state' => 'SUPPORTED', 'visibility' => 'PUBLIC',
            'minimum_supported_version' => ($in['minimum_supported_version'] ?? null) ?: null,
            'minimum_no_change' => ! empty($in['minimum_no_change']) ? 1 : 0,
            'mandatory' => $mandatory ? 1 : 0, 'mandatory_after' => Catalog::toDb($in['mandatory_after'] ?? null),
            'grace_minutes' => isset($in['grace_minutes']) && $in['grace_minutes'] !== '' ? (int) $in['grace_minutes'] : ($type === 'CRITICAL' ? 60 : null),
            'rollback_supported' => array_key_exists('rollback_supported', $in) ? (int) (bool) $in['rollback_supported'] : 1,
            'notes' => $in['notes'] ?? null, 'known_issues' => $in['known_issues'] ?? null,
            'release_date' => Catalog::toDb($in['release_date'] ?? null),
            'created_by' => $this->ctx()->userId(), 'created_by_client' => $this->ctx()->clientId(),
            'created_at' => $now, 'updated_at' => $now,
        ];
        $db->table('releases')->insert($row);
        $release = Catalog::releaseById((int) $db->insertID());
        (new RolloutService())->ensure($release, $channel['code']);
        if ($app['kind'] === 'SERVER') {
            $db->table('release_server_meta')->insert(['release_id' => $release['id'], 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->audit->record('release.created', 'release', $release['public_id'], null, ['app' => $app['slug'], 'version' => $version, 'build' => $build, 'channel' => $channel['code'], 'type' => $type]);

        return $release;
    }

    public function update(array $release, array $in): array
    {
        $pre     = in_array($release['status'], self::PRE_PUBLISH, true);
        $allowed = $pre
            ? ['release_type', 'release_date', 'notes', 'known_issues', 'visibility', 'rollback_supported', 'migration_compatibility', 'requirements_override', 'scheduled_publish_at', 'auto_pause_on_gate', 'minimum_supported_version', 'minimum_no_change', 'mandatory', 'mandatory_after', 'grace_minutes', 'build']
            : ['notes', 'known_issues', 'visibility', 'auto_pause_on_gate'];
        $set     = [];
        foreach ($in as $k => $v) {
            if (! in_array($k, $allowed, true)) {
                if (in_array($k, ['version', 'channel', 'app_id'], true) || (! $pre && in_array($k, ['build', 'release_type'], true))) {
                    throw new ApiException('RELEASE_IMMUTABLE', "{$k} cannot be changed" . ($pre ? '' : ' after publication'), 409);
                }

                continue;
            }
            $set[$k] = $v;
        }
        if (isset($set['release_type'])) {
            $set['release_type'] = strtoupper((string) $set['release_type']);
            if (! in_array($set['release_type'], self::TYPES, true)) {
                throw ApiException::validation(['release_type' => 'Invalid release type']);
            }
        }
        if (isset($set['visibility']) && ! in_array($set['visibility'], ['PUBLIC', 'HIDDEN'], true)) {
            throw ApiException::validation(['visibility' => 'PUBLIC or HIDDEN']);
        }
        if (array_key_exists('build', $set)) {
            $set['build_number'] = (int) $set['build'];
            unset($set['build']);
        }
        foreach (['release_date', 'scheduled_publish_at', 'mandatory_after'] as $d) {
            if (array_key_exists($d, $set)) {
                $set[$d] = Catalog::toDb($set[$d]);
            }
        }
        foreach (['migration_compatibility', 'requirements_override'] as $j) {
            if (array_key_exists($j, $set) && ! is_string($set[$j])) {
                $set[$j] = json_encode($set[$j]);
            }
        }
        foreach (['rollback_supported', 'auto_pause_on_gate', 'minimum_no_change', 'mandatory'] as $b) {
            if (array_key_exists($b, $set)) {
                $set[$b] = $set[$b] ? 1 : 0;
            }
        }
        if (! empty($set['minimum_supported_version']) && ! Semver::isValid((string) $set['minimum_supported_version'])) {
            throw ApiException::validation(['minimum_supported_version' => 'Invalid SemVer']);
        }
        if ($set === []) {
            return $release;
        }
        $before = array_intersect_key($release, $set);
        db_connect()->table('releases')->where('id', $release['id'])->update($set + ['updated_at' => Catalog::now()]);
        $this->audit->record('release.updated', 'release', $release['public_id'], $before, $set);

        return Catalog::releaseById((int) $release['id']);
    }

    // ---- Workflow ----------------------------------------------------------

    public function submitForTest(array $release): array
    {
        if (! in_array($release['status'], ['DRAFT', 'UPLOADING'], true)) {
            throw new ApiException('INVALID_TRANSITION', "Cannot submit a {$release['status']} release for test", 409);
        }
        $check = (new ReleaseValidationService())->check($release, ['approvals', 'manifest_signature', 'staging_test']);
        if (! $check['passed']) {
            throw new ApiException('CHECKLIST_FAILED', 'Checklist has failing items', 422, ['items' => ReleaseValidationService::failures($check)]);
        }
        $release = $this->transition($release, 'READY_FOR_TEST');
        $this->audit->record('release.submitted_for_test', 'release', $release['public_id']);
        service('notifier')->notify('release.ready_for_qa', ['title' => 'Release ready for QA', 'body' => $this->label($release) . ' is ready for testing.', 'link' => '/admin/releases/' . $release['public_id']]);

        return $release;
    }

    public function startTesting(array $release): array
    {
        $release = $this->transition($release, 'TESTING');
        $this->audit->record('release.testing_started', 'release', $release['public_id']);

        return $release;
    }

    /** QA pass/fail (per platform optional). SoD: uploader cannot QA-approve on production Stable. */
    public function qa(array $release, string $decision, ?string $platform, string $comment): array
    {
        $decision = strtoupper($decision);
        if (! in_array($decision, ['PASS', 'FAIL'], true)) {
            throw ApiException::validation(['decision' => 'PASS or FAIL']);
        }
        if (! in_array($release['status'], ['READY_FOR_TEST', 'TESTING'], true)) {
            throw new ApiException('INVALID_TRANSITION', 'QA decisions are recorded while READY_FOR_TEST or TESTING', 409);
        }
        $uid = $this->ctx()->userId() ?? throw new ApiException('FORBIDDEN', 'QA approval requires a user', 403);
        if ($decision === 'PASS' && $this->sodApplies($release) && in_array($uid, $this->uploaderIds($release), true)) {
            throw new ApiException('SOD_VIOLATION', 'The user who uploaded packages cannot give the QA approval', 403);
        }
        if ($release['status'] === 'READY_FOR_TEST') {
            $release = $this->transition($release, 'TESTING');
        }
        db_connect()->table('release_approvals')->insert(['release_id' => $release['id'], 'kind' => 'QA', 'decision' => $decision, 'platform' => $platform ?: null, 'user_id' => $uid, 'comment' => $comment, 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        $this->audit->record('release.qa_' . strtolower($decision), 'release', $release['public_id'], null, ['platform' => $platform, 'comment' => $comment]);
        if ($decision === 'FAIL') {
            // QA fail → back to DRAFT with notes; earlier approvals no longer count.
            db_connect()->table('release_approvals')->where('release_id', $release['id'])->where('decision !=', 'FAIL')->delete();
            $release = $this->transition($release, 'DRAFT', ['qa_notes' => $comment]);
            service('notifier')->notify('release.qa_failed', ['title' => 'QA failed', 'body' => $this->label($release) . ': ' . $comment, 'link' => '/admin/releases/' . $release['public_id']]);
        } elseif ($platform === null) {
            service('notifier')->notify('release.qa_passed', ['title' => 'QA passed', 'body' => $this->label($release) . ' is waiting for Release Manager approval.', 'link' => '/admin/releases/' . $release['public_id']]);
        }

        return $release;
    }

    public function approve(array $release, string $comment = ''): array
    {
        if ($release['status'] !== 'TESTING') {
            throw new ApiException('INVALID_TRANSITION', 'Only releases in TESTING can be approved', 409);
        }
        $uid     = $this->ctx()->userId() ?? throw new ApiException('FORBIDDEN', 'Approval requires a user', 403);
        $channel = Catalog::channelById((int) $release['channel_id']);
        $db      = db_connect();
        $qa      = $db->table('release_approvals')->where('release_id', $release['id'])->where('kind', 'QA')->where('decision', 'PASS')->get()->getResultArray();
        if ($channel['code'] === 'STABLE' && $qa === []) {
            throw new ApiException('QA_APPROVAL_MISSING', 'QA must pass before Release Manager approval', 409);
        }
        if ($this->sodApplies($release)) {
            if (in_array($uid, array_map('intval', array_column($qa, 'user_id')), true) && count(array_unique(array_column($qa, 'user_id'))) === 1) {
                throw new ApiException('SOD_VIOLATION', 'Release Manager approval must come from a different person than QA', 403);
            }
            if (in_array($uid, $this->uploaderIds($release), true)) {
                throw new ApiException('SOD_VIOLATION', 'The uploader cannot approve the release for publication', 403);
            }
        }
        $db->table('release_approvals')->insert(['release_id' => $release['id'], 'kind' => 'RELEASE', 'decision' => 'APPROVED', 'user_id' => $uid, 'comment' => $comment, 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        $release = $this->transition($release, 'APPROVED');
        $this->audit->record('release.approved', 'release', $release['public_id'], null, ['comment' => $comment]);
        service('notifier')->notify('release.approved', ['title' => 'Release approved', 'body' => $this->label($release) . ' is approved and can be published.', 'link' => '/admin/releases/' . $release['public_id']]);

        return $release;
    }

    public function publishSummary(array $release): array
    {
        $db       = db_connect();
        $app      = Catalog::appById((int) $release['application_id']);
        $channel  = Catalog::channelById((int) $release['channel_id']);
        $rollout  = (new RolloutService())->present($release);
        $current  = (new ManifestService())->currentRelease((int) $app['id'], (int) $channel['id']);
        $supersedes = $db->table('releases')->select('public_id, version')->where('application_id', $app['id'])->where('channel_id', $channel['id'])->whereIn('status', ['PUBLISHED', 'PAUSED'])->where('id !=', $release['id'])->get()->getResultArray();
        $minPolicy = (new MinimumVersionService())->channelMinimum((int) $app['id'], (int) $channel['id'], null);
        $checklist = (new ReleaseValidationService())->check($release);
        $devices   = $db->table('device_versions')->where('application_id', $app['id'])->where('channel', $channel['code'])->where('last_seen >=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))->countAllResults();
        $firstPct  = $rollout['stages'][0] ?? 100;

        return [
            'channel'                   => $channel['code'],
            'release_type'              => $release['release_type'],
            'rollout'                   => ['mode' => $rollout['mode'], 'stages' => $rollout['stages']],
            'minimum_supported_version' => $release['minimum_supported_version'],
            'effective_minimum'         => Semver::max($release['minimum_supported_version'], $minPolicy['minimum_version'] ?? null),
            'mandatory'                 => (bool) $release['mandatory'],
            'mandatory_after'           => Catalog::iso($release['mandatory_after']),
            'supersedes'                => array_map(static fn ($s) => ['id' => $s['public_id'], 'version' => $s['version']], $supersedes),
            'current_offered'           => $current ? $current['version'] : null,
            'approvals'                 => $this->approvals($release),
            'checklist'                 => $checklist,
            'affected'                  => ['active_devices' => $devices, 'will_be_offered' => (int) round($devices * $firstPct / 100)],
            'health_ok'                 => (new HealthService())->publishBlockers() === [],
            'requires_mfa'              => $this->stepUpNeeded(),
            'scheduled_publish_at'      => Catalog::iso($release['scheduled_publish_at']),
        ];
    }

    /** WF-C06. */
    public function publish(array $release, ?string $reason = null, ?string $scheduleAt = null, bool $system = false): array
    {
        if ($release['status'] !== 'APPROVED') {
            throw new ApiException('INVALID_TRANSITION', 'Only APPROVED releases can be published', 409);
        }
        $uid = $this->ctx()->userId();
        if (! $system && $this->sodApplies($release) && $uid !== null && in_array($uid, $this->uploaderIds($release), true)) {
            throw new ApiException('SOD_VIOLATION', 'The Release Manager who publishes cannot be the uploader', 403);
        }
        if ($scheduleAt !== null && $scheduleAt !== '') {
            $at = Catalog::toDb($scheduleAt);
            if (strtotime($at . ' UTC') > time() + 60) {
                db_connect()->table('releases')->where('id', $release['id'])->update(['scheduled_publish_at' => $at, 'published_by' => $uid, 'updated_at' => Catalog::now()]);
                $this->audit->record('release.publish_scheduled', 'release', $release['public_id'], null, ['at' => $at], $reason);

                return Catalog::releaseById((int) $release['id']);
            }
        }
        $check = (new ReleaseValidationService())->check($release);
        if (! $check['passed']) {
            throw new ApiException('CHECKLIST_FAILED', 'Publish blocked: checklist has failing items', 422, ['items' => ReleaseValidationService::failures($check)]);
        }
        $blockers = (new HealthService())->publishBlockers();
        if ($blockers !== []) {
            throw new ApiException('DISTRIBUTION_DEGRADED', 'Publishing is blocked while storage or signing is unhealthy', 503, ['blockers' => $blockers]);
        }

        $ms = new ManifestService();
        try {
            $ms->signRelease($release, $reason ?? 'Publish');
        } catch (ApiException $e) {
            db_connect()->table('releases')->where('id', $release['id'])->update(['last_error' => $e->getMessage()]);
            service('notifier')->notify('release.publish_failed', ['title' => 'Publish failed', 'body' => $this->label($release) . ': ' . $e->getMessage(), 'link' => '/admin/releases/' . $release['public_id']]);

            throw $e;
        }

        $db  = db_connect();
        $now = Catalog::now();
        $db->transStart();
        $previous = $db->table('releases')->where('application_id', $release['application_id'])->where('channel_id', $release['channel_id'])->whereIn('status', ['PUBLISHED', 'PAUSED'])->where('id !=', $release['id'])->get()->getResultArray();
        $release  = $this->transition($release, 'PUBLISHED', ['published_at' => $now, 'published_by' => $uid ?? $release['published_by'], 'scheduled_publish_at' => null, 'last_error' => null, 'paused_at' => null]);
        foreach ($previous as $p) {
            if (Semver::compare($p['version'], $release['version']) < 0) {
                $db->table('releases')->where('id', $p['id'])->update(['status' => 'SUPERSEDED', 'superseded_at' => $now, 'superseded_by_release_id' => $release['id'], 'updated_at' => $now]);
            }
        }
        $db->transComplete();
        (new RolloutService())->start($release);
        $ms->signChannel((int) $release['application_id'], (int) $release['channel_id'], 'Publish ' . $release['version']);

        $this->afterStatusChange($release, 'release.published', $reason, $system);
        foreach ($previous as $p) {
            if (Semver::compare($p['version'], $release['version']) < 0) {
                $this->audit->record('release.superseded', 'release', $p['public_id'], ['status' => $p['status']], ['status' => 'SUPERSEDED', 'by' => $release['public_id']], null, ['type' => 'SYSTEM', 'id' => 'release-service', 'label' => 'automatic supersede']);
                (new TenantEventsService())->emit('release.superseded', (int) $p['application_id'], (int) $p['id'], (int) $p['channel_id']);
            }
        }
        Queue::push(SubscriberNotifyJob::class, ['release_id' => (int) $release['id']], 'notifications');
        if (Catalog::appById((int) $release['application_id'])['kind'] === 'SERVER') {
            (new ServerUpdateService())->onPublished($release);
        }
        service('notifier')->notify('release.published', ['title' => 'Release published', 'body' => $this->label($release) . ' is live.', 'link' => '/admin/releases/' . $release['public_id']], null, false);
        if (class_exists(\App\Services\BugTrace\BugTraceReleaseHooks::class)) {
            try {
                (new \App\Services\BugTrace\BugTraceReleaseHooks())->onReleasePublished($release);
            } catch (\Throwable $e) {
                log_message('error', 'Bug Trace publish hook failed: ' . $e->getMessage());
            }
        }

        return Catalog::releaseById((int) $release['id']);
    }

    /** WF-C13 — stops new offers; channel manifest reverts to the previous release. */
    public function pause(array $release, string $reason): array
    {
        $release = $this->transition($release, 'PAUSED', ['paused_at' => Catalog::now()]);
        db_connect()->table('release_rollouts')->where('release_id', $release['id'])->where('status', 'ACTIVE')->update(['status' => 'PAUSED', 'paused_at' => Catalog::now()]);
        (new ManifestService())->signChannel((int) $release['application_id'], (int) $release['channel_id'], 'Pause ' . $release['version']);
        $this->afterStatusChange($release, 'release.paused', $reason);

        return $release;
    }

    public function resume(array $release, ?string $reason = null): array
    {
        if ($release['status'] !== 'PAUSED') {
            throw new ApiException('INVALID_TRANSITION', 'Only PAUSED releases can be resumed', 409);
        }
        $release = $this->transition($release, 'PUBLISHED', ['paused_at' => null]);
        db_connect()->table('release_rollouts')->where('release_id', $release['id'])->where('status', 'PAUSED')->update(['status' => 'ACTIVE', 'paused_at' => null]);
        (new ManifestService())->signChannel((int) $release['application_id'], (int) $release['channel_id'], 'Resume ' . $release['version']);
        $this->afterStatusChange($release, 'release.resumed', $reason);

        return $release;
    }

    /** WF-C14. */
    public function withdraw(array $release, string $reason, string $confirmVersion): array
    {
        if ($confirmVersion !== $release['version']) {
            throw ApiException::validation(['confirm_version' => 'Type the version number exactly to confirm']);
        }
        $release = $this->transition($release, 'WITHDRAWN', ['withdrawn_at' => Catalog::now(), 'withdrawn_by' => $this->ctx()->userId(), 'withdraw_reason' => $reason]);
        db_connect()->table('release_rollouts')->where('release_id', $release['id'])->update(['status' => 'DONE']);
        $lowered = (new MinimumVersionService())->lowerIfMinimum($release);
        (new ManifestService())->signChannel((int) $release['application_id'], (int) $release['channel_id'], 'Withdraw ' . $release['version']);
        $this->afterStatusChange($release, 'release.withdrawn', $reason);
        if (Catalog::appById((int) $release['application_id'])['kind'] === 'SERVER') {
            (new ServerUpdateService())->notifyWithdrawn($release, $reason);
        }
        service('notifier')->notify('release.withdrawn', ['title' => 'Release withdrawn', 'body' => $this->label($release) . ': ' . $reason . ($lowered ? " Minimum version lowered to {$lowered}." : ''), 'link' => '/admin/releases/' . $release['public_id']]);
        if (class_exists(\App\Services\BugTrace\BugTraceReleaseHooks::class) && method_exists(\App\Services\BugTrace\BugTraceReleaseHooks::class, 'onReleaseWithdrawn')) {
            try {
                (new \App\Services\BugTrace\BugTraceReleaseHooks())->onReleaseWithdrawn($release);
            } catch (\Throwable) {
            }
        }

        return Catalog::releaseById((int) $release['id']) + ['minimum_lowered_to' => $lowered];
    }

    /** Manual supersede (FR-UC-REL-13). */
    public function supersede(array $release, string $reason): array
    {
        $newer = db_connect()->table('releases')->where('application_id', $release['application_id'])->where('channel_id', $release['channel_id'])->where('status', 'PUBLISHED')->where('version_sort >', $release['version_sort'])->orderBy('version_sort', 'DESC')->get()->getRowArray();
        $release = $this->transition($release, 'SUPERSEDED', ['superseded_at' => Catalog::now(), 'superseded_by_release_id' => $newer['id'] ?? null]);
        (new ManifestService())->signChannel((int) $release['application_id'], (int) $release['channel_id'], 'Supersede ' . $release['version']);
        $this->afterStatusChange($release, 'release.superseded', $reason);

        return $release;
    }

    /** Minimum / mandatory / critical policy (WF-C10, §7.6). */
    public function setPolicy(array $release, array $in): array
    {
        $published = in_array($release['status'], ['PUBLISHED', 'PAUSED'], true);
        $set       = [];
        if (array_key_exists('release_type', $in)) {
            if ($published) {
                // Type changes after publish only toward stricter urgency.
                $order = array_flip(self::TYPES);
                if (($order[strtoupper($in['release_type'])] ?? -1) < $order[$release['release_type']]) {
                    throw new ApiException('RELEASE_IMMUTABLE', 'Release type can only be raised after publication', 409);
                }
            }
            $set['release_type'] = strtoupper((string) $in['release_type']);
        }
        if (array_key_exists('mandatory', $in)) {
            $set['mandatory'] = $in['mandatory'] ? 1 : 0;
        }
        if (array_key_exists('mandatory_after', $in)) {
            $at = Catalog::toDb($in['mandatory_after']);
            if ($at !== null && strtotime($at . ' UTC') < time() && empty($in['immediate'])) {
                throw new ApiException('DEADLINE_IN_PAST', 'Deadline is in the past — confirm "immediate" to enforce now', 422);
            }
            $set['mandatory_after'] = $at;
        }
        if (array_key_exists('grace_minutes', $in)) {
            $set['grace_minutes'] = $in['grace_minutes'] === null || $in['grace_minutes'] === '' ? null : max(0, (int) $in['grace_minutes']);
        }
        if (array_key_exists('minimum_supported_version', $in)) {
            $v = $in['minimum_supported_version'] ?: null;
            if ($v !== null && ! Semver::isValid($v)) {
                throw ApiException::validation(['minimum_supported_version' => 'Invalid SemVer']);
            }
            if ($v !== null && Semver::compare($v, $release['version']) > 0) {
                throw new ApiException('MINIMUM_ABOVE_LATEST', 'Release minimum cannot exceed the release version', 422);
            }
            $set['minimum_supported_version'] = $v;
        }
        if (array_key_exists('minimum_no_change', $in)) {
            $set['minimum_no_change'] = $in['minimum_no_change'] ? 1 : 0;
        }
        if ($set === []) {
            return $release;
        }
        $before = array_intersect_key($release, $set);
        db_connect()->table('releases')->where('id', $release['id'])->update($set + ['updated_at' => Catalog::now()]);
        $release = Catalog::releaseById((int) $release['id']);
        $this->audit->record('release.policy_changed', 'release', $release['public_id'], $before, $set, $in['reason'] ?? null);
        if ($published) {
            // Policy is part of the signed manifests → re-sign (WF-C10 step 2).
            (new ManifestService())->signRelease($release, 'Policy change: ' . ($in['reason'] ?? ''));
            (new ManifestService())->signChannel((int) $release['application_id'], (int) $release['channel_id'], 'Policy change');
            (new TenantEventsService())->emit('release.policy_changed', (int) $release['application_id'], (int) $release['id'], (int) $release['channel_id'], ['mandatory' => (bool) $release['mandatory'], 'mandatory_after' => Catalog::iso($release['mandatory_after']), 'release_type' => $release['release_type']]);
            UpdateResolverService::bumpCacheEpoch();
        }

        return $release;
    }

    /** SUPPORTED / DEPRECATED / UNSUPPORTED / BLOCKED (FR-UC-VER-01..04). */
    public function setSupportState(array $release, string $state, string $reason): array
    {
        $state = strtoupper($state);
        if (! in_array($state, self::SUPPORT_STATES, true)) {
            throw ApiException::validation(['support_state' => implode(', ', self::SUPPORT_STATES)]);
        }
        db_connect()->table('releases')->where('id', $release['id'])->update(['support_state' => $state, 'updated_at' => Catalog::now()]);
        $this->audit->record('release.support_state_changed', 'release', $release['public_id'], ['support_state' => $release['support_state']], ['support_state' => $state], $reason);
        $release = Catalog::releaseById((int) $release['id']);
        if (in_array($release['status'], ['PUBLISHED', 'PAUSED', 'SUPERSEDED', 'WITHDRAWN'], true)) {
            (new ManifestService())->signChannel((int) $release['application_id'], (int) $release['channel_id'], 'Support state ' . $release['version'] . ' → ' . $state);
            (new TenantEventsService())->emit('version.support_changed', (int) $release['application_id'], (int) $release['id'], (int) $release['channel_id'], ['support_state' => $state]);
            Queue::push(PrerenderJob::class, ['app_id' => (int) $release['application_id']], 'default');
        }
        UpdateResolverService::bumpCacheEpoch();

        return $release;
    }

    /**
     * Mirrors BaseApiController::requireStepUp(): a fresh code is needed unless MFA was
     * verified in the last 10 minutes; outside production, users without MFA are exempt.
     */
    public function stepUpNeeded(): bool
    {
        $ctx = $this->ctx();
        if ($ctx->user === null) {
            return config('UpdateCenter')->isProduction();
        }
        if ($ctx->hasRecentMfa()) {
            return false;
        }

        return config('UpdateCenter')->isProduction() || ! empty($ctx->user['mfa_enabled']);
    }

    public function regenerateManifest(array $release, string $reason): array
    {
        if (! in_array($release['status'], ['PUBLISHED', 'PAUSED'], true)) {
            throw new ApiException('INVALID_TRANSITION', 'Only published releases have manifests to regenerate', 409);
        }
        $ms     = new ManifestService();
        $before = $ms->latestSigned((int) $release['id']);
        $row    = $ms->signRelease($release, $reason);
        $ms->signChannel((int) $release['application_id'], (int) $release['channel_id'], 'Regenerate: ' . $reason);
        $diff = ManifestService::diff($before ? json_decode($before['manifest_json'], true) : [], json_decode($row['manifest_json'], true));
        $this->audit->record('manifest.regenerated', 'release', $release['public_id'], ['sha256' => $before['sha256'] ?? null], ['sha256' => $row['sha256'], 'changes' => count($diff)], $reason);
        Queue::push(CdnPurgeJob::class, ['paths' => $this->purgePaths($release)], 'default');

        return $ms->info($release) + ['diff' => $diff];
    }

    // ---- Helpers -------------------------------------------------------

    private function afterStatusChange(array $release, string $event, ?string $reason, bool $system = false): void
    {
        $this->audit->record($event, 'release', $release['public_id'], null, ['status' => $release['status'], 'version' => $release['version']], $reason, $system ? ['type' => 'SYSTEM', 'id' => 'scheduler', 'label' => 'scheduled publish'] : null);
        (new TenantEventsService())->emit($event === 'release.resumed' ? 'release.published' : $event, (int) $release['application_id'], (int) $release['id'], (int) $release['channel_id']);
        Queue::push(CdnPurgeJob::class, ['paths' => $this->purgePaths($release)], 'default');
        Queue::push(PrerenderJob::class, ['app_id' => (int) $release['application_id'], 'release_id' => (int) $release['id']], 'default');
        UpdateResolverService::bumpCacheEpoch();
    }

    public function purgePaths(array $release): array
    {
        $app     = Catalog::appById((int) $release['application_id']);
        $channel = strtolower(Catalog::channelById((int) $release['channel_id'])['code']);

        return [
            "/api/v1/apps/{$app['app_identifier']}/channels/{$channel}/manifest",
            "/api/v1/apps/{$app['slug']}/channels/{$channel}/manifest",
            "/api/v1/releases/{$release['public_id']}/manifest",
            "/api/v1/releases/{$release['public_id']}/state",
            "/apps/{$app['slug']}",
            "/apps/{$app['slug']}/releases/{$release['version']}",
        ];
    }

    private function sodApplies(array $release): bool
    {
        $cfg = config('UpdateCenter');

        return ($cfg->enforceSod || $cfg->isProduction()) && Catalog::channelById((int) $release['channel_id'])['code'] === 'STABLE';
    }

    /** @return list<int> */
    public function uploaderIds(array $release): array
    {
        return array_values(array_unique(array_map('intval', array_filter(array_column(db_connect()->table('release_package_revisions r')->select('r.uploaded_by')
            ->join('release_packages p', 'p.id = r.release_package_id')->where('p.release_id', $release['id'])->where('r.uploaded_by IS NOT NULL')->get()->getResultArray(), 'uploaded_by')))));
    }

    public function approvals(array $release): array
    {
        return array_map(static fn ($a) => ['kind' => $a['kind'], 'decision' => $a['decision'], 'platform' => $a['platform'], 'user' => Catalog::userLabel((int) $a['user_id']), 'comment' => $a['comment'], 'created_at' => Catalog::iso($a['created_at'])],
            db_connect()->table('release_approvals')->where('release_id', $release['id'])->orderBy('id')->get()->getResultArray());
    }

    private function label(array $release): string
    {
        return Catalog::appById((int) $release['application_id'])['name'] . ' ' . $release['version'] . ' (' . Catalog::channelById((int) $release['channel_id'])['code'] . ')';
    }

    /** Actions the caller may take now (drives UI buttons). */
    public function allowedActions(array $release, AuthContext $ctx): array
    {
        $s   = $release['status'];
        $a   = [];
        $can = static fn (string $p) => $ctx->can($p);
        if (in_array($s, self::PRE_PUBLISH, true) && $can('release.edit')) {
            $a[] = 'edit';
        }
        if (in_array($s, ['DRAFT', 'UPLOADING', 'READY_FOR_TEST', 'TESTING'], true) && ($can('release.upload') || $can('release.file.upload'))) {
            $a[] = 'upload';
        }
        if (in_array($s, ['DRAFT', 'UPLOADING'], true) && $can('release.edit')) {
            $a[] = 'submit_test';
        }
        if ($s === 'READY_FOR_TEST' && ($can('release.approve.qa') || $can('release.edit'))) {
            $a[] = 'start_testing';
        }
        if (in_array($s, ['READY_FOR_TEST', 'TESTING'], true) && $can('release.approve.qa')) {
            $a[] = 'qa';
        }
        if ($s === 'TESTING' && $can('release.approve')) {
            $a[] = 'approve';
        }
        if ($s === 'APPROVED' && $can('release.publish')) {
            $a[] = 'publish';
        }
        if ($s === 'PUBLISHED' && $can('release.publish')) {
            $a[] = 'pause';
        }
        if ($s === 'PAUSED' && $can('release.publish')) {
            $a[] = 'resume';
        }
        if (in_array($s, ['PUBLISHED', 'PAUSED', 'SUPERSEDED'], true) && $can('release.withdraw')) {
            $a[] = 'withdraw';
        }
        if (in_array($s, ['PUBLISHED', 'PAUSED'], true) && $can('release.publish')) {
            $a[] = 'supersede';
            $a[] = 'regenerate_manifest';
        }
        if (in_array($s, ['PUBLISHED', 'PAUSED'], true) && $can('release.file.replace')) {
            $a[] = 'replace_file';
        }
        if (in_array($s, ['DRAFT', 'UPLOADING', 'READY_FOR_TEST', 'TESTING'], true) && $can('release.file.delete')) {
            $a[] = 'delete_file';
        }

        return $a;
    }

    /** Called by the scheduler for scheduled publishes (FR-UC-REL-09). */
    public function runScheduled(): array
    {
        $out = [];
        foreach (db_connect()->table('releases')->where('status', 'APPROVED')->where('scheduled_publish_at IS NOT NULL')->where('scheduled_publish_at <=', Catalog::now())->get()->getResultArray() as $r) {
            try {
                $this->publish($r, 'Scheduled publish', null, true);
                $out[] = "published {$r['public_id']}";
            } catch (\Throwable $e) {
                db_connect()->table('releases')->where('id', $r['id'])->update(['last_error' => $e->getMessage(), 'scheduled_publish_at' => null]);
                service('notifier')->notify('release.publish_failed', ['title' => 'Scheduled publish failed', 'body' => $r['version'] . ': ' . $e->getMessage(), 'link' => '/admin/releases/' . $r['public_id']]);
                $out[] = "failed {$r['public_id']}: {$e->getMessage()}";
            }
        }

        return $out;
    }
}

<?php

namespace App\Services\BugTrace;

use App\Libraries\BugTrace\Bt;
use App\Libraries\BugTrace\Fingerprinter;
use App\Libraries\BugTrace\ReleaseLinker;
use App\Libraries\BugTrace\Symbolicator;
use App\Libraries\Semver;

/**
 * Queue-side processing of one (already scrubbed) event (§18.3, WF-C30):
 * symbolicate → fingerprint → issue upsert (human id per project) → store
 * event → counters (events/users/installations/tenants, releases, hourly) →
 * regression / ignore-condition checks → alerts.
 */
class EventProcessor
{
    private ReleaseLinker $linker;

    /** @var array<int, array<string, mixed>> */
    private static array $projects = [];

    public function __construct(?ReleaseLinker $linker = null, private ?AlertService $alerts = null)
    {
        $this->linker = $linker ?? new ReleaseLinker();
        $this->alerts ??= new AlertService();
    }

    public static function project(int $id): ?array
    {
        return self::$projects[$id] ??= Bt::db()->table('bt_projects')->where('id', $id)->get()->getRowArray() ?: null;
    }

    public static function flushCache(): void
    {
        self::$projects = [];
    }

    /**
     * @param array<string, mixed> $p payload from IngestService: project_id, event, received_at,
     *                                tenant_ref, edition, country_code, scrub_count, scrub_summary
     *
     * @return array{issue_id: int, public_id: string, new_issue: bool, regressed: bool}|null
     */
    public function process(array $p): ?array
    {
        $db      = Bt::db();
        $event   = $p['event'];
        $eventId = (string) $event['event_id'];
        if ($db->table('bt_events')->where('event_id', $eventId)->countAllResults() > 0) {
            return null; // client retry — already stored
        }
        self::$projects = [];
        $project = self::project((int) $p['project_id']);
        if ($project === null) {
            return null;
        }
        $type     = in_array($event['type'] ?? '', ['crash', 'error', 'hang', 'message'], true) ? $event['type'] : 'error';
        $release  = isset($event['release']) ? (string) $event['release'] : null;
        $link     = $this->linker->link((int) $project['application_id'], $release);
        $sym      = (new Symbolicator())->symbolicate($event, $project, $link['release_id']);
        $status   = (string) ($p['symbolication'] ?? $sym['status']);
        $rules    = $db->table('bt_fingerprint_rules')->where('enabled', 1)->groupStart()->where('project_id', null)->orWhere('project_id', $project['id'])->groupEnd()->orderBy('project_id', 'DESC')->orderBy('id')->get()->getResultArray();
        $fp       = (new Fingerprinter($rules))->compute($event + ['type' => $type], $sym['frames']);
        $ts       = Bt::clientTime($event['timestamp'] ?? null, isset($p['received_at']) ? (int) $p['received_at'] : null);

        [$issue, $isNew] = $this->findOrCreateIssue($project, $fp, $type, $ts, $link, $release);

        $contexts = is_array($event['contexts'] ?? null) ? $event['contexts'] : [];
        $contexts['bugtrace'] = ['scrubbed' => $p['scrub_summary'] ?? [], 'symbolication' => $status, 'fingerprint_components' => $fp['components']];
        $os      = self::nameVersion($contexts['os'] ?? null);
        $browser = self::nameVersion($contexts['browser'] ?? null);

        $row = [
            'event_id'            => $eventId,
            'issue_id'            => $issue['id'],
            'project_id'          => $project['id'],
            'release_id'          => $link['release_id'],
            'release'             => $release !== null ? mb_substr($release, 0, 80) : null,
            'release_known'       => $link['known'] ? 1 : 0,
            'installation_ref'    => $event['installation_ref'] ?? null,
            'tenant_ref'          => $p['tenant_ref'] ?? null,
            'edition'             => isset($event['edition']) ? strtoupper(mb_substr((string) $event['edition'], 0, 16)) : ($p['edition'] ?? null),
            'user_ref'            => $event['user_ref'] ?? null,
            'session_id'          => isset($event['session_id']) ? mb_substr((string) $event['session_id'], 0, 64) : null,
            'type'                => $type,
            'environment'         => isset($event['environment']) ? mb_substr((string) $event['environment'], 0, 32) : null,
            'timestamp'           => $ts,
            'platform'            => isset($event['platform']) ? mb_substr((string) $event['platform'], 0, 16) : $project['platform'],
            'os'                  => $os,
            'browser'             => $browser,
            'country_code'        => $p['country_code'] ?? null,
            'device'              => Bt::json($contexts['device'] ?? null),
            'exception'           => Bt::json(['values' => $event['exception'] ?? [], 'message' => $event['message'] ?? null, 'title' => $fp['title']]),
            'contexts'            => Bt::json($contexts),
            'breadcrumbs'         => Bt::json($event['breadcrumbs'] ?? []),
            'tags'                => Bt::json(($event['tags'] ?? []) + array_filter(['level' => $event['level'] ?? null])),
            'symbolicated_frames' => Bt::json($sym['frames']),
            'scrub_count'         => (int) ($p['scrub_count'] ?? 0),
            'processed_at'        => Bt::now(),
            'fingerprint'         => $fp['fingerprint'],
            'symbolication'       => $status,
        ];

        try {
            $db->table('bt_events')->insert($row);
        } catch (\Throwable $e) {
            if ($db->table('bt_events')->where('event_id', $eventId)->countAllResults() > 0) {
                return null;
            }

            throw $e;
        }

        $this->updateCounters($issue, $row, $release);
        $this->linkAttachments($project, $eventId, $event['attachments'] ?? []);
        // Reports that referenced this event before it was processed get their suggestion now.
        $db->table('bt_reports')->where('related_event_id', $eventId)->where('suggested_issue_id', null)->update(['suggested_issue_id' => $issue['id']]);

        $regressed = false;
        if (! $isNew) {
            $regressed = $this->checkStatusTransitions($issue, $row, $release);
        }
        if ($isNew) {
            $this->alerts->issueEvent('new_issue', $this->reload($issue['id']), $project);
        }

        return ['issue_id' => (int) $issue['id'], 'public_id' => (string) $issue['public_id'], 'new_issue' => $isNew, 'regressed' => $regressed];
    }

    private static function nameVersion(mixed $ctx): ?string
    {
        if (! is_array($ctx) || empty($ctx['name'])) {
            return null;
        }

        return mb_substr(trim($ctx['name'] . ' ' . ($ctx['version'] ?? '')), 0, 64);
    }

    /**
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function findOrCreateIssue(array $project, array $fp, string $type, string $ts, array $link, ?string $release): array
    {
        $db    = Bt::db();
        $issue = $db->table('bt_issues')->where('project_id', $project['id'])->where('fingerprint', $fp['fingerprint'])->get()->getRowArray();
        if ($issue) {
            return [$this->followMerge($issue), false];
        }
        $seq = Bt::nextCounter('issue:' . $project['id'], 1296);
        $row = [
            'public_id'        => $project['issue_prefix'] . '-' . Bt::base36($seq),
            'project_id'       => $project['id'],
            'fingerprint'      => $fp['fingerprint'],
            'type'             => $type,
            'title'            => $fp['title'],
            'culprit'          => $fp['culprit'],
            'status'           => 'NEW',
            'priority'         => match ($type) { 'crash', 'hang' => 'P2', 'message' => 'P4', default => 'P3' },
            'first_seen'       => $ts,
            'last_seen'        => $ts,
            'first_release_id' => $link['release_id'],
            'first_release'    => $release !== null ? mb_substr($release, 0, 80) : null,
            'last_release'     => $release !== null ? mb_substr($release, 0, 80) : null,
            'labels'           => '[]',
            'created_at'       => Bt::now(),
            'updated_at'       => Bt::now(),
        ];

        try {
            $db->table('bt_issues')->insert($row);
        } catch (\Throwable $e) {
            $issue = $db->table('bt_issues')->where('project_id', $project['id'])->where('fingerprint', $fp['fingerprint'])->get()->getRowArray();
            if ($issue) {
                return [$this->followMerge($issue), false];
            }

            throw $e;
        }

        return [$db->table('bt_issues')->where('id', $db->insertID())->get()->getRowArray(), true];
    }

    /** Events of a merged (DUPLICATE) issue count towards the issue it was merged into. */
    private function followMerge(array $issue): array
    {
        $seen = [];
        while (! empty($issue['merged_into_id']) && ! isset($seen[$issue['id']])) {
            $seen[$issue['id']] = true;
            $next = Bt::db()->table('bt_issues')->where('id', $issue['merged_into_id'])->get()->getRowArray();
            if (! $next) {
                break;
            }
            $issue = $next;
        }

        return $issue;
    }

    private function reload(int $id): array
    {
        return Bt::db()->table('bt_issues')->where('id', $id)->get()->getRowArray();
    }

    private function updateCounters(array $issue, array $ev, ?string $release): void
    {
        $db  = Bt::db();
        $new = ['user' => 0, 'inst' => 0];
        foreach (['user' => $ev['user_ref'], 'inst' => $ev['installation_ref'], 'tnt' => $ev['tenant_ref']] as $kind => $ref) {
            if ($ref === null || $ref === '') {
                continue;
            }
            $db->table('bt_issue_users')->ignore(true)->insert(['issue_id' => $issue['id'], 'kind' => $kind, 'ref' => mb_substr((string) $ref, 0, 128)]);
            if ($db->affectedRows() > 0 && isset($new[$kind])) {
                $new[$kind] = 1;
            }
        }
        $b = $db->table('bt_issues')->where('id', $issue['id'])
            ->set('events_count', 'events_count + 1', false)
            ->set('users_count', 'users_count + ' . $new['user'], false)
            ->set('installations_count', 'installations_count + ' . $new['inst'], false)
            ->set('updated_at', Bt::now());
        if ($issue['last_seen'] === null || $ev['timestamp'] > $issue['last_seen']) {
            $b->set('last_seen', $ev['timestamp']);
        }
        if ($ev['timestamp'] < $issue['first_seen']) {
            $b->set('first_seen', $ev['timestamp']);
        }
        if ($release !== null && ($issue['last_release'] === null || ReleaseLinker::compare($release, $issue['last_release']) >= 0)) {
            $b->set('last_release', mb_substr($release, 0, 80));
        }
        if ($issue['first_release_id'] === null && $ev['release_id'] !== null && $release === $issue['first_release']) {
            $b->set('first_release_id', $ev['release_id']);
        }
        $b->update();

        if ($release !== null) {
            $rel = mb_substr($release, 0, 80);
            Bt::increment('bt_issue_releases', ['issue_id' => $issue['id'], 'release' => $rel], ['events' => 1], [], ['first_seen' => $ev['timestamp'], 'last_seen' => $ev['timestamp']]);
            $db->table('bt_issue_releases')->where('issue_id', $issue['id'])->where('release', $rel)->where('last_seen <', $ev['timestamp'])->update(['last_seen' => $ev['timestamp']]);
        }
        $hour = substr($ev['timestamp'], 0, 13) . ':00:00';
        Bt::increment('bt_issue_stats_hourly', ['issue_id' => $issue['id'], 'hour' => $hour], ['events' => 1, 'users' => $new['user'], 'installations' => $new['inst']]);
    }

    private function linkAttachments(array $project, string $eventId, mixed $atts): void
    {
        if (! is_array($atts)) {
            return;
        }
        foreach ($atts as $a) {
            if (! empty($a['upload_id'])) {
                Bt::db()->table('bt_attachments')->where('public_id', (string) $a['upload_id'])->where('project_id', $project['id'])->where('owner_id', null)
                    ->update(['owner_type' => 'event', 'owner_id' => $eventId, 'expires_at' => null, 'updated_at' => Bt::now()]);
            }
        }
    }

    /**
     * Regression (FR-BT-ING-06) and IGNORED-until conditions.
     */
    private function checkStatusTransitions(array $issue, array $ev, ?string $release): bool
    {
        $db = Bt::db();
        if ($issue['status'] === 'RESOLVED') {
            if (! self::isRegression($issue, $release, $ev['timestamp'])) {
                return false;
            }
            $db->table('bt_issues')->where('id', $issue['id'])->where('status', 'RESOLVED')->update([
                'status' => 'REGRESSED', 'regressed_at' => Bt::now(), 'updated_at' => Bt::now(),
            ]);
            if ($db->affectedRows() === 0) {
                return false;
            }
            IssueService::activity((int) $issue['id'], null, sprintf('Regressed: occurred again in %s (resolved in %s)', $release ?? 'an unknown release', $issue['resolve_version'] ?? ($issue['resolution'] === 'NEXT_RELEASE' ? 'next release' : 'a previous version')));
            $fresh = $this->reload((int) $issue['id']);
            $this->alerts->issueEvent('regression', $fresh, self::project((int) $fresh['project_id']) ?? []);

            return true;
        }
        if ($issue['status'] === 'IGNORED' && self::ignoreConditionMet($issue, $release)) {
            $db->table('bt_issues')->where('id', $issue['id'])->update(['status' => 'NEW', 'ignore_until' => null, 'updated_at' => Bt::now()]);
            IssueService::activity((int) $issue['id'], null, 'Un-ignored: the ignore condition was met');
        }

        return false;
    }

    /**
     * RESOLVED in version X (VERSION, or NEXT_RELEASE once that release is
     * published) regresses when an event arrives from a version ≥ X. Resolved
     * NOW (or NEXT_RELEASE before publication) regresses on events from a
     * version newer than the newest one seen when it was resolved; events from
     * old versions still in the field do not regress it.
     */
    public static function isRegression(array $issue, ?string $release, ?string $ts = null): bool
    {
        $v = ReleaseLinker::parse($release)['version'];
        if (! empty($issue['resolve_version'])) {
            if ($v === null) {
                return false;
            }

            try {
                return Semver::compare($v, (string) $issue['resolve_version']) >= 0;
            } catch (\Throwable) {
                return false;
            }
        }
        $base = ReleaseLinker::parse($issue['resolved_last_release'] ?? null)['version'];
        if ($issue['resolution'] === 'NEXT_RELEASE') {
            return $v !== null && $base !== null && Semver::compare($v, $base) > 0;
        }
        // NOW: any newer version, or (no version information) any event after resolution.
        if ($v !== null && $base !== null) {
            return Semver::compare($v, $base) >= 0 && ($ts === null || $issue['resolved_at'] === null || $ts > $issue['resolved_at']);
        }

        return $ts !== null && $issue['resolved_at'] !== null && $ts > $issue['resolved_at'];
    }

    public static function ignoreConditionMet(array $issue, ?string $release): bool
    {
        $c = Bt::decode($issue['ignore_until'] ?? null, []);
        if (! is_array($c) || $c === []) {
            return false; // ignored forever
        }
        if (! empty($c['until']) && strtotime((string) $c['until']) !== false && strtotime((string) $c['until']) <= time()) {
            return true;
        }
        if (isset($c['count']) && (int) $issue['events_count'] + 1 - (int) ($c['base_events'] ?? 0) >= (int) $c['count']) {
            return true;
        }
        if (isset($c['users']) && (int) $issue['users_count'] - (int) ($c['base_users'] ?? 0) >= (int) $c['users']) {
            return true;
        }
        if (! empty($c['next_release']) && $release !== null && ReleaseLinker::compare($release, $c['base_release'] ?? null) > 0) {
            return true;
        }

        return false;
    }
}

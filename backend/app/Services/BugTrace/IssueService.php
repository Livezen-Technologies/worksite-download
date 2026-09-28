<?php

namespace App\Services\BugTrace;

use App\Exceptions\ApiException;
use App\Libraries\BugTrace\Bt;
use App\Libraries\BugTrace\ReleaseLinker;
use App\Libraries\Semver;
use App\Services\AuthContext;

/**
 * Issue triage (FR-BT-ISS-01..07): list/filter/sort, detail, status changes
 * (resolve now / in next release / in version X, ignore until, won't fix),
 * assignment, priority, labels, comments with @mentions, links, merge/split
 * and bulk actions.
 */
class IssueService
{
    public const SORTS = ['users' => 'users_count', 'events' => 'events_count', 'first_seen' => 'first_seen', 'last_seen' => 'last_seen'];

    public static function activity(int $issueId, ?int $userId, string $body, string $target = 'issue'): void
    {
        Bt::db()->table('bt_comments')->insert([
            'target_type' => $target, 'target_id' => $issueId, 'user_id' => $userId, 'body' => mb_substr($body, 0, 2000),
            'kind' => 'ACTIVITY', 'created_at' => Bt::now(), 'updated_at' => Bt::now(),
        ]);
    }

    public function find(string $publicId): array
    {
        $i = Bt::db()->table('bt_issues')->where('public_id', strtoupper(trim($publicId)))->get()->getRowArray();
        if (! $i) {
            throw ApiException::notFound('NOT_FOUND', 'Issue not found');
        }

        return $i;
    }

    public function findById(int $id): ?array
    {
        return Bt::db()->table('bt_issues')->where('id', $id)->get()->getRowArray() ?: null;
    }

    // -------------------------------------------------------------- listing

    /**
     * @param array<string, mixed> $q query parameters
     *
     * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int, kpis: array<string, mixed>}
     */
    public function list(array $q, AuthContext $auth, int $limit = 50, int $offset = 0): array
    {
        $b     = $this->filtered($q, $auth);
        $total = (clone $b)->countAllResults(false);
        $sort  = self::SORTS[$q['sort'] ?? 'last_seen'] ?? 'last_seen';
        $rows  = $b->orderBy('bt_issues.' . $sort, 'DESC')->orderBy('bt_issues.id', 'DESC')->limit($limit, $offset)->get()->getResultArray();
        $spark = $this->sparklines(array_map('intval', array_column($rows, 'id')));

        return [
            'data'   => array_map(fn ($r) => $this->format($r, $spark[(int) $r['id']] ?? array_fill(0, 24, 0)), $rows),
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
            'kpis'   => $this->kpis(),
        ];
    }

    private function filtered(array $q, AuthContext $auth): \CodeIgniter\Database\BaseBuilder
    {
        $db = Bt::db();
        $b  = $db->table('bt_issues')->select('bt_issues.*');
        if (! empty($q['project'])) {
            $ids = [];
            foreach (explode(',', (string) $q['project']) as $ref) {
                $p = ProjectService::resolve($ref);
                $ids[] = $p ? (int) $p['id'] : 0;
            }
            $b->whereIn('bt_issues.project_id', $ids);
        }
        if (! empty($q['platform'])) {
            $pids = array_map('intval', array_column($db->table('bt_projects')->select('id')->whereIn('platform', explode(',', (string) $q['platform']))->get()->getResultArray(), 'id'));
            $b->whereIn('bt_issues.project_id', $pids ?: [0]);
        }
        $statuses = [];
        foreach (array_filter(explode(',', strtoupper((string) ($q['status'] ?? '')))) as $s) {
            $statuses = array_merge($statuses, $s === 'UNRESOLVED' ? Bt::UNRESOLVED : [$s]);
        }
        if ($statuses !== []) {
            $b->whereIn('bt_issues.status', $statuses);
        } else {
            $b->where('bt_issues.status !=', 'DUPLICATE');
        }
        if (! empty($q['type'])) {
            $b->whereIn('bt_issues.type', explode(',', strtolower((string) $q['type'])));
        }
        if (! empty($q['priority'])) {
            $b->whereIn('bt_issues.priority', explode(',', strtoupper((string) $q['priority'])));
        }
        if (! empty($q['release'])) {
            $rel = (string) $q['release'];
            if (str_starts_with($rel, 'rel_')) {
                $rel = (string) ($db->table('releases')->select('version')->where('public_id', $rel)->get()->getRow('version') ?? '__none__');
            }
            $b->where('EXISTS (SELECT 1 FROM bt_issue_releases ir WHERE ir.issue_id = bt_issues.id AND (ir.' . $db->protectIdentifiers('release') . ' = ' . $db->escape($rel) . ' OR ir.' . $db->protectIdentifiers('release') . ' LIKE ' . $db->escape($rel . '+%') . '))', null, false);
        }
        if (! empty($q['environment'])) {
            $b->where('EXISTS (SELECT 1 FROM bt_events ev WHERE ev.issue_id = bt_issues.id AND ev.environment = ' . $db->escape((string) $q['environment']) . ')', null, false);
        }
        if (! empty($q['edition'])) {
            $b->where('EXISTS (SELECT 1 FROM bt_events ev WHERE ev.issue_id = bt_issues.id AND ev.edition = ' . $db->escape(strtoupper((string) $q['edition'])) . ')', null, false);
        }
        if (! empty($q['tenant']) && $auth->can('bugtrace.tenant.view')) {
            $b->where('EXISTS (SELECT 1 FROM bt_issue_users iu WHERE iu.issue_id = bt_issues.id AND iu.kind = ' . $db->escape('tnt') . ' AND iu.ref = ' . $db->escape((string) $q['tenant']) . ')', null, false);
        }
        if (isset($q['assignee']) && $q['assignee'] !== '') {
            $a = (string) $q['assignee'];
            if ($a === 'none') {
                $b->where('bt_issues.assignee_id', null);
            } elseif ($a === 'me') {
                $b->where('bt_issues.assignee_id', $auth->userId() ?? 0);
            } else {
                $b->where('bt_issues.assignee_id', (int) ($db->table('users')->select('id')->where('public_id', $a)->get()->getRow('id') ?? 0));
            }
        }
        foreach (['first_seen_from' => ['first_seen', '>='], 'first_seen_to' => ['first_seen', '<='], 'last_seen_from' => ['last_seen', '>='], 'last_seen_to' => ['last_seen', '<='], 'since' => ['last_seen', '>=']] as $param => [$col, $op]) {
            if (! empty($q[$param]) && ($t = strtotime((string) $q[$param])) !== false) {
                $b->where("bt_issues.{$col} {$op}", gmdate('Y-m-d H:i:s', $t));
            }
        }
        if (! empty($q['q'])) {
            $term = mb_substr(trim((string) $q['q']), 0, 100);
            $b->groupStart()->like('bt_issues.title', $term)->orLike('bt_issues.culprit', $term)->orLike('bt_issues.public_id', $term)->groupEnd();
        }
        if (! empty($q['label'])) {
            $b->like('bt_issues.labels', '"' . str_replace('"', '', (string) $q['label']) . '"');
        }

        return $b;
    }

    /** @return array<int, list<int>> 24 hourly buckets, oldest first */
    public function sparklines(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $start = time() - 23 * 3600;
        $rows  = Bt::db()->table('bt_issue_stats_hourly')->whereIn('issue_id', $ids)->where('hour >=', gmdate('Y-m-d H:00:00', $start))->get()->getResultArray();
        $out   = [];
        foreach ($ids as $id) {
            $out[$id] = array_fill(0, 24, 0);
        }
        $base = (int) (floor($start / 3600) * 3600);
        foreach ($rows as $r) {
            $idx = (int) ((strtotime($r['hour'] . ' UTC') - $base) / 3600);
            if ($idx >= 0 && $idx < 24) {
                $out[(int) $r['issue_id']][$idx] += (int) $r['events'];
            }
        }

        return $out;
    }

    /** @return array{unresolved: int, regressed: int, crash_free_users: ?float, events_24h: int, untriaged_reports: int} */
    public function kpis(): array
    {
        $db    = Bt::db();
        $since = gmdate('Y-m-d', time() - 6 * 86400);
        $users = $db->table('bt_session_users')->select('COUNT(*) AS n, SUM(crashed) AS c')->where('date >=', $since)->get()->getRowArray();

        return [
            'unresolved'        => $db->table('bt_issues')->whereIn('status', Bt::UNRESOLVED)->countAllResults(),
            'regressed'         => $db->table('bt_issues')->where('status', 'REGRESSED')->countAllResults(),
            'crash_free_users'  => (int) ($users['n'] ?? 0) > 0 ? round(100 * (1 - (int) $users['c'] / (int) $users['n']), 2) : null,
            'events_24h'        => (int) ($db->table('bt_issue_stats_hourly')->selectSum('events')->where('hour >=', gmdate('Y-m-d H:00:00', time() - 23 * 3600))->get()->getRow('events') ?? 0),
            'untriaged_reports' => $db->table('bt_reports')->where('status', 'UNTRIAGED')->countAllResults(),
        ];
    }

    /** @return array<string, mixed> Issue (api-contract) */
    public function format(array $r, ?array $sparkline = null): array
    {
        $project = EventProcessor::project((int) $r['project_id']);
        $merged  = $r['merged_into_id'] ? $this->findById((int) $r['merged_into_id']) : null;

        return [
            'id'                  => $r['public_id'],
            'project'             => $project ? ['id' => Bt::projectPublicId($project), 'name' => $project['name'], 'platform' => $project['platform']] : null,
            'type'                => $r['type'],
            'title'               => $r['title'],
            'culprit'             => $r['culprit'],
            'status'              => $r['status'],
            'resolution'          => $r['resolution'],
            'resolve_version'     => $r['resolve_version'],
            'priority'            => $r['priority'],
            'assignee'            => Bt::userRef($r['assignee_id'] !== null ? (int) $r['assignee_id'] : null),
            'first_seen'          => Bt::iso($r['first_seen']),
            'last_seen'           => Bt::iso($r['last_seen']),
            'first_release'       => $r['first_release'],
            'last_release'        => $r['last_release'],
            'events_count'        => (int) $r['events_count'],
            'users_count'         => (int) $r['users_count'],
            'installations_count' => (int) $r['installations_count'],
            'labels'              => Bt::decode($r['labels'], []),
            'sparkline'           => $sparkline ?? ($this->sparklines([(int) $r['id']])[(int) $r['id']] ?? array_fill(0, 24, 0)),
            'ignore_until'        => Bt::decode($r['ignore_until'] ?? null, null),
            'known_issue_public'  => $r['known_issue_public'],
            'resolved_at'         => Bt::iso($r['resolved_at']),
            'regressed_at'        => Bt::iso($r['regressed_at']),
            'merged_into'         => $merged['public_id'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public static function formatEvent(array $e, AuthContext $auth): array
    {
        $ex       = Bt::decode($e['exception'], []);
        $contexts = Bt::decode($e['contexts'], []);
        $release  = $e['release_id'] ? Bt::db()->table('releases')->select('public_id')->where('id', $e['release_id'])->get()->getRow('public_id') : null;
        $out      = [
            'event_id'         => $e['event_id'],
            'type'             => $e['type'],
            'timestamp'        => Bt::iso($e['timestamp']),
            'title'            => $ex['title'] ?? null,
            'message'          => $ex['message'] ?? null,
            'exception'        => array_map(static fn ($x) => ['type' => $x['type'] ?? null, 'value' => $x['value'] ?? null, 'mechanism' => $x['mechanism'] ?? null], $ex['values'] ?? []),
            'release'          => $e['release'],
            'release_id'       => $release,
            'release_known'    => (bool) $e['release_known'],
            'environment'      => $e['environment'],
            'platform'         => $e['platform'],
            'os'               => $e['os'],
            'browser'          => $e['browser'],
            'device'           => Bt::decode($e['device'], null),
            'edition'          => $e['edition'],
            'country_code'     => $e['country_code'],
            'installation_ref' => $e['installation_ref'],
            'user_ref'         => $e['user_ref'],
            'session_id'       => $e['session_id'],
            'contexts'         => $contexts,
            'tags'             => Bt::decode($e['tags'], new \stdClass()) ?: new \stdClass(),
            'scrub_count'      => (int) $e['scrub_count'],
            'scrubbed'         => $contexts['bugtrace']['scrubbed'] ?? [],
            'symbolication'    => $e['symbolication'],
        ];
        if ($auth->can('bugtrace.tenant.view')) {
            $out['tenant_ref'] = $e['tenant_ref'];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function detail(string $publicId, AuthContext $auth): array
    {
        $db    = Bt::db();
        $issue = $this->find($publicId);
        $id    = (int) $issue['id'];
        $out   = $this->format($issue);
        $latest = $db->table('bt_events')->where('issue_id', $id)->orderBy('timestamp', 'DESC')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        $frames = [];
        if ($latest) {
            foreach (array_reverse(Bt::decode($latest['symbolicated_frames'], [])) as $f) {
                $frame = ['file' => $f['file'] ?? null, 'function' => $f['function'] ?? null, 'line' => $f['line'] ?? null, 'col' => $f['col'] ?? null, 'in_app' => (bool) ($f['in_app'] ?? false)];
                if (isset($f['context'])) {
                    $frame['context'] = $f['context'];
                }
                if (! empty($f['symbolicated'])) {
                    $frame['symbolicated'] = true;
                }
                $frames[] = $frame;
            }
        }
        $dist = [];
        foreach (['os' => 'os', 'browser' => 'browser', 'release' => 'release', 'edition' => 'edition'] as $key => $col) {
            $rows = $db->table('bt_events')->select($db->protectIdentifiers($col) . ' AS name, COUNT(*) AS count', false)->where('issue_id', $id)->where($db->protectIdentifiers($col) . ' IS NOT NULL', null, false)
                ->groupBy($col)->orderBy('count', 'DESC')->limit(10)->get()->getResultArray();
            $dist[$key] = array_map(static fn ($r) => ['name' => (string) $r['name'], 'count' => (int) $r['count']], $rows);
        }
        $hourly = [];
        $start  = (int) (floor((time() - 167 * 3600) / 3600) * 3600);
        $hmap   = array_column($db->table('bt_issue_stats_hourly')->where('issue_id', $id)->where('hour >=', gmdate('Y-m-d H:i:s', $start))->get()->getResultArray(), 'events', 'hour');
        for ($t = $start; $t <= time(); $t += 3600) {
            $h        = gmdate('Y-m-d H:00:00', $t);
            $hourly[] = ['hour' => Bt::iso($h), 'events' => (int) ($hmap[$h] ?? 0)];
        }
        $reportRows = $db->table('bt_reports')->groupStart()->where('issue_id', $id)->orWhere('suggested_issue_id', $id)->groupEnd()->orderBy('created_at', 'DESC')->limit(100)->get()->getResultArray();
        $reports    = new ReportService();

        $out += [
            'latest_event'    => $latest ? self::formatEvent($latest, $auth) : null,
            'frames'          => $frames,
            'breadcrumbs'     => $latest ? Bt::decode($latest['breadcrumbs'], []) : [],
            'tags'            => $latest ? (Bt::decode($latest['tags'], new \stdClass()) ?: new \stdClass()) : new \stdClass(),
            'distributions'   => $dist,
            'releases'        => array_map(static fn ($r) => ['release' => $r['release'], 'events' => (int) $r['events'], 'first_seen' => Bt::iso($r['first_seen']), 'last_seen' => Bt::iso($r['last_seen'])], $db->table('bt_issue_releases')->where('issue_id', $id)->orderBy('last_seen', 'DESC')->get()->getResultArray()),
            'hourly'          => $hourly,
            'reports'         => array_map(static fn ($r) => $reports->summary($r), $reportRows),
            'comments'        => $this->comments($id),
            'links'           => array_map(static fn ($l) => ['kind' => $l['kind'], 'ref' => $l['ref'], 'url' => $l['url'], 'created_at' => Bt::iso($l['created_at'])], $db->table('bt_issue_links')->where('issue_id', $id)->orderBy('id')->get()->getResultArray()),
            'scrubbed_fields' => $latest ? (int) $latest['scrub_count'] : 0,
            'symbolication'   => $latest['symbolication'] ?? null,
            'fingerprint'     => substr((string) $issue['fingerprint'], 0, 12),
        ];
        if ($auth->can('bugtrace.tenant.view')) {
            $out['tenant_count'] = $db->table('bt_issue_users')->where('issue_id', $id)->where('kind', 'tnt')->countAllResults();
            $out['tenants']      = array_map(static fn ($r) => ['tenant_ref' => $r['tenant_ref'], 'installations' => (int) $r['installations'], 'events' => (int) $r['events']], $db->table('bt_events')
                ->select('tenant_ref, COUNT(DISTINCT installation_ref) AS installations, COUNT(*) AS events')->where('issue_id', $id)->where('tenant_ref IS NOT NULL', null, false)
                ->groupBy('tenant_ref')->orderBy('events', 'DESC')->limit(50)->get()->getResultArray());
        }

        return $out;
    }

    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function events(string $publicId, AuthContext $auth, int $limit, int $offset): array
    {
        $issue = $this->find($publicId);
        $b     = Bt::db()->table('bt_events')->where('issue_id', $issue['id']);
        $total = (clone $b)->countAllResults(false);
        $rows  = $b->orderBy('timestamp', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return ['data' => array_map(static fn ($e) => self::formatEvent($e, $auth), $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /** @return list<array<string, mixed>> */
    public function comments(int $issueId, string $target = 'issue'): array
    {
        return array_map(static fn ($c) => [
            'id'         => (int) $c['id'],
            'user'       => Bt::userRef($c['user_id'] !== null ? (int) $c['user_id'] : null),
            'body'       => $c['body'],
            'kind'       => $c['kind'],
            'created_at' => Bt::iso($c['created_at']),
        ], Bt::db()->table('bt_comments')->where('target_type', $target)->where('target_id', $issueId)->orderBy('id')->get()->getResultArray());
    }

    // --------------------------------------------------------------- updates

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function patch(string $publicId, array $body, AuthContext $auth): array
    {
        $issue = $this->find($publicId);
        $this->applyPatch($issue, $body, $auth);

        return $this->format($this->find($publicId));
    }

    /** @param array<string, mixed> $body */
    public function applyPatch(array $issue, array $body, AuthContext $auth): void
    {
        $db      = Bt::db();
        $uid     = $auth->userId();
        $row     = [];
        $notes   = [];
        $errors  = [];
        $status  = isset($body['status']) ? strtoupper((string) $body['status']) : null;
        if ($status === null && (isset($body['resolution']) || isset($body['resolve_version']))) {
            $status = 'RESOLVED';
        }
        if ($status !== null && ! in_array($status, Bt::STATUSES, true)) {
            $errors['status'] = 'Unknown status';
        }
        if ($status === 'DUPLICATE') {
            $errors['status'] = 'Use POST /issues/{id}/merge to mark a duplicate';
        }
        if (array_key_exists('priority', $body)) {
            in_array(strtoupper((string) $body['priority']), Bt::PRIORITIES, true) ? $row['priority'] = strtoupper((string) $body['priority']) : $errors['priority'] = 'P0..P4';
        }
        $assigneeId = null;
        if (array_key_exists('assignee_id', $body)) {
            if ($body['assignee_id'] === null || $body['assignee_id'] === '') {
                $row['assignee_id'] = null;
            } else {
                $u = $db->table('users')->select('id, kind, status')->where('public_id', (string) $body['assignee_id'])->get()->getRowArray();
                if (! $u || $u['kind'] !== 'STAFF' || $u['status'] !== 'ACTIVE') {
                    $errors['assignee_id'] = 'Unknown staff user';
                } else {
                    $row['assignee_id'] = $assigneeId = (int) $u['id'];
                }
            }
        }
        if (array_key_exists('labels', $body)) {
            $labels = array_values(array_unique(array_filter(array_map(static fn ($l) => mb_substr(trim((string) $l), 0, 40), (array) $body['labels']), static fn ($l) => $l !== '')));
            count($labels) > 20 ? $errors['labels'] = 'At most 20 labels' : $row['labels'] = Bt::json($labels);
        }
        if (array_key_exists('known_issue_public', $body)) {
            $k = $body['known_issue_public'];
            $row['known_issue_public'] = $k === null || $k === '' || $k === false ? null : mb_substr((string) $k, 0, 1000);
        }

        $resolvedNow = false;
        if ($status !== null && ! isset($errors['status'])) {
            $row['status'] = $status;
            if ($status === 'RESOLVED') {
                $resolution = strtoupper((string) ($body['resolution'] ?? 'NOW'));
                if (! in_array($resolution, ['NOW', 'NEXT_RELEASE', 'VERSION'], true)) {
                    $errors['resolution'] = 'NOW, NEXT_RELEASE or VERSION';
                }
                $row['resolution']             = $resolution;
                $row['resolve_version']        = null;
                $row['resolved_in_release_id'] = null;
                $row['resolved_at']            = Bt::now();
                $row['resolved_by']            = $uid;
                $row['resolved_last_release']  = $issue['last_release'];
                $row['regressed_at']           = null;
                if ($resolution === 'VERSION') {
                    $v = trim((string) ($body['resolve_version'] ?? ''));
                    if ($v === '' || ! Semver::isValid(ReleaseLinker::parse($v)['version'] ?? '')) {
                        $errors['resolve_version'] = 'A valid version (e.g. 3.4.1) is required';
                    } else {
                        $row['resolve_version'] = ReleaseLinker::parse($v)['version'];
                        $project = EventProcessor::project((int) $issue['project_id']);
                        $rel     = $project ? $db->table('releases')->select('id')->where('application_id', $project['application_id'])->where('version', $row['resolve_version'])->orderBy('id', 'DESC')->get()->getRowArray() : null;
                        $row['resolved_in_release_id'] = $rel['id'] ?? null;
                    }
                }
                $resolvedNow = true;
                $notes[]     = 'Resolved ' . match ($resolution) { 'NEXT_RELEASE' => 'in next release', 'VERSION' => 'in ' . ($row['resolve_version'] ?? '?'), default => 'now' };
            } else {
                if ($issue['status'] === 'RESOLVED' || $issue['status'] === 'REGRESSED') {
                    $row += ['resolution' => null, 'resolve_version' => null, 'resolved_in_release_id' => null, 'resolved_at' => null, 'resolved_last_release' => null];
                }
                if ($status === 'IGNORED') {
                    $cond = is_array($body['ignore_until'] ?? null) ? array_intersect_key($body['ignore_until'], array_flip(['until', 'count', 'users', 'next_release'])) : [];
                    if (isset($cond['until']) && strtotime((string) $cond['until']) === false) {
                        $errors['ignore_until'] = 'until must be a date';
                    }
                    $row['ignore_until'] = $cond === [] ? null : Bt::json($cond + ['base_events' => (int) $issue['events_count'], 'base_users' => (int) $issue['users_count'], 'base_release' => $issue['last_release']]);
                } else {
                    $row['ignore_until'] = null;
                }
                $notes[] = 'Status → ' . $status . ($status === 'WONT_FIX' && ! empty($body['reason']) ? ': ' . mb_substr((string) $body['reason'], 0, 500) : '');
            }
        } elseif ($assigneeId !== null && $issue['status'] === 'NEW') {
            $row['status'] = 'TRIAGED'; // priority and owner set (§18.6)
            $notes[]       = 'Status → TRIAGED';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        if (isset($row['priority']) && $row['priority'] !== $issue['priority']) {
            $notes[] = 'Priority ' . $issue['priority'] . ' → ' . $row['priority'];
        }
        if (array_key_exists('assignee_id', $row) && $row['assignee_id'] !== ($issue['assignee_id'] !== null ? (int) $issue['assignee_id'] : null)) {
            $notes[] = $row['assignee_id'] === null ? 'Unassigned' : 'Assigned to ' . (Bt::userRef($row['assignee_id'])['name'] ?? '?');
        }
        if ($row === []) {
            return;
        }
        $db->table('bt_issues')->where('id', $issue['id'])->update($row + ['updated_at' => Bt::now()]);
        foreach ($notes as $n) {
            self::activity((int) $issue['id'], $uid, $n);
        }
        if ($notes !== []) {
            service('audit')->record('bugtrace.issue.updated', 'bt_issue', $issue['public_id'], ['status' => $issue['status'], 'priority' => $issue['priority']], array_intersect_key($row, array_flip(['status', 'priority', 'resolution', 'resolve_version', 'assignee_id'])));
        }
        $fresh   = $this->findById((int) $issue['id']);
        $project = EventProcessor::project((int) $issue['project_id']) ?? [];
        if ($assigneeId !== null && $assigneeId !== $uid && $assigneeId !== (int) ($issue['assignee_id'] ?? 0)) {
            service('notifier')->notify('bugtrace.alert', ['title' => 'Assigned to you: ' . $issue['public_id'], 'body' => $issue['title'], 'link' => '/admin/bugtrace/issues/' . $issue['public_id']], [$assigneeId], false);
        }
        if ($resolvedNow) {
            (new AlertService())->issueEvent('resolved', $fresh, $project);
            (new ReportService())->onIssueResolved($fresh);
        }
        if (($row['status'] ?? null) === 'WONT_FIX') {
            (new ReportService())->onIssueClosed($fresh, 'WONT_FIX', (string) ($body['reason'] ?? ''));
        }
    }

    /** @return array<string, mixed> target issue */
    public function merge(string $sourceId, string $intoId, AuthContext $auth): array
    {
        $db     = Bt::db();
        $source = $this->find($sourceId);
        $target = $this->find($intoId);
        if ((int) $source['id'] === (int) $target['id']) {
            throw ApiException::validation(['into' => 'Cannot merge an issue into itself']);
        }
        if ((int) $source['project_id'] !== (int) $target['project_id']) {
            throw ApiException::validation(['into' => 'Issues must belong to the same project']);
        }
        if (! empty($target['merged_into_id'])) {
            throw ApiException::validation(['into' => 'Target issue is itself merged into another issue']);
        }
        $db->transStart();
        $db->table('bt_events')->where('issue_id', $source['id'])->update(['issue_id' => $target['id']]);
        $db->table('bt_issues')->where('merged_into_id', $source['id'])->update(['merged_into_id' => $target['id']]);
        $db->table('bt_reports')->where('issue_id', $source['id'])->update(['issue_id' => $target['id']]);
        $db->table('bt_reports')->where('suggested_issue_id', $source['id'])->update(['suggested_issue_id' => $target['id']]);
        foreach ($db->table('bt_issue_users')->where('issue_id', $source['id'])->get()->getResultArray() as $u) {
            $db->table('bt_issue_users')->ignore(true)->insert(['issue_id' => $target['id'], 'kind' => $u['kind'], 'ref' => $u['ref']]);
        }
        foreach ($db->table('bt_issue_releases')->where('issue_id', $source['id'])->get()->getResultArray() as $r) {
            Bt::increment('bt_issue_releases', ['issue_id' => $target['id'], 'release' => $r['release']], ['events' => (int) $r['events']], [], ['first_seen' => $r['first_seen'], 'last_seen' => $r['last_seen']]);
        }
        foreach ($db->table('bt_issue_stats_hourly')->where('issue_id', $source['id'])->get()->getResultArray() as $h) {
            Bt::increment('bt_issue_stats_hourly', ['issue_id' => $target['id'], 'hour' => $h['hour']], ['events' => (int) $h['events']]);
        }
        $db->table('bt_issues')->where('id', $target['id'])->update([
            'events_count' => (int) $target['events_count'] + (int) $source['events_count'],
            'first_seen'   => min($target['first_seen'], $source['first_seen']),
            'last_seen'    => max($target['last_seen'], $source['last_seen']),
            'updated_at'   => Bt::now(),
        ]);
        $this->recountUsers((int) $target['id']);
        $db->table('bt_issues')->where('id', $source['id'])->update(['status' => 'DUPLICATE', 'merged_into_id' => $target['id'], 'updated_at' => Bt::now()]);
        $db->transComplete();

        self::activity((int) $source['id'], $auth->userId(), 'Merged into ' . $target['public_id']);
        self::activity((int) $target['id'], $auth->userId(), 'Merged ' . $source['public_id'] . ' into this issue');
        service('audit')->record('bugtrace.issue.merged', 'bt_issue', $source['public_id'], null, ['into' => $target['public_id']]);

        return $this->format($this->find($intoId));
    }

    /** Undo a merge: the DUPLICATE issue gets its own events (by fingerprint) back. */
    public function split(string $publicId, AuthContext $auth): array
    {
        $db     = Bt::db();
        $source = $this->find($publicId);
        if ($source['status'] !== 'DUPLICATE' || empty($source['merged_into_id'])) {
            throw ApiException::conflict('INVALID_TRANSITION', 'Only merged (DUPLICATE) issues can be split out');
        }
        $target = $this->findById((int) $source['merged_into_id']);
        $db->transStart();
        $movedRows = $db->table('bt_events')->select('user_ref, installation_ref, tenant_ref')->where('issue_id', $target['id'])->where('fingerprint', $source['fingerprint'])->get()->getResultArray();
        $moved     = count($movedRows);
        foreach ($movedRows as $e) {
            foreach (['user' => $e['user_ref'], 'inst' => $e['installation_ref'], 'tnt' => $e['tenant_ref']] as $kind => $ref) {
                if ($ref !== null && $ref !== '') {
                    $db->table('bt_issue_users')->ignore(true)->insert(['issue_id' => $source['id'], 'kind' => $kind, 'ref' => $ref]);
                }
            }
        }
        $db->table('bt_events')->where('issue_id', $target['id'])->where('fingerprint', $source['fingerprint'])->update(['issue_id' => $source['id']]);
        $delta = max((int) $source['events_count'], $moved);
        foreach ($db->table('bt_issue_releases')->where('issue_id', $source['id'])->get()->getResultArray() as $r) {
            $db->table('bt_issue_releases')->where('issue_id', $target['id'])->where('release', $r['release'])->set('events', 'CASE WHEN events > ' . (int) $r['events'] . ' THEN events - ' . (int) $r['events'] . ' ELSE 0 END', false)->update();
        }
        foreach ($db->table('bt_issue_stats_hourly')->where('issue_id', $source['id'])->get()->getResultArray() as $h) {
            $db->table('bt_issue_stats_hourly')->where('issue_id', $target['id'])->where('hour', $h['hour'])->set('events', 'CASE WHEN events > ' . (int) $h['events'] . ' THEN events - ' . (int) $h['events'] . ' ELSE 0 END', false)->update();
        }
        // Drop target user refs that only came from the split-out issue.
        foreach ($db->table('bt_issue_users')->where('issue_id', $source['id'])->get()->getResultArray() as $u) {
            $col = ['user' => 'user_ref', 'inst' => 'installation_ref', 'tnt' => 'tenant_ref'][$u['kind']] ?? null;
            if ($col !== null && $db->table('bt_events')->where('issue_id', $target['id'])->where($col, $u['ref'])->countAllResults() === 0) {
                $db->table('bt_issue_users')->where('issue_id', $target['id'])->where('kind', $u['kind'])->where('ref', $u['ref'])->delete();
            }
        }
        $db->table('bt_issues')->where('id', $target['id'])->update(['events_count' => max(0, (int) $target['events_count'] - $delta), 'updated_at' => Bt::now()]);
        $db->table('bt_issues')->where('id', $source['id'])->update(['status' => 'NEW', 'merged_into_id' => null, 'events_count' => max((int) $source['events_count'], $moved), 'updated_at' => Bt::now()]);
        $this->recountUsers((int) $target['id']);
        $this->recountUsers((int) $source['id']);
        $db->transComplete();
        self::activity((int) $source['id'], $auth->userId(), 'Split out of ' . $target['public_id']);
        self::activity((int) $target['id'], $auth->userId(), 'Split ' . $source['public_id'] . ' out of this issue');
        service('audit')->record('bugtrace.issue.split', 'bt_issue', $source['public_id'], ['merged_into' => $target['public_id']], null);

        return $this->format($this->find($publicId));
    }

    public function recountUsers(int $issueId): void
    {
        $db = Bt::db();
        $db->table('bt_issues')->where('id', $issueId)->update([
            'users_count'         => $db->table('bt_issue_users')->where('issue_id', $issueId)->where('kind', 'user')->countAllResults(),
            'installations_count' => $db->table('bt_issue_users')->where('issue_id', $issueId)->where('kind', 'inst')->countAllResults(),
        ]);
    }

    /** @return array<string, mixed> */
    public function comment(string $publicId, array $body, AuthContext $auth, string $target = 'issue', ?int $targetId = null): array
    {
        $text = trim((string) ($body['body'] ?? ''));
        if ($text === '') {
            throw ApiException::validation(['body' => 'Required']);
        }
        $db = Bt::db();
        if ($targetId === null) {
            $targetId = (int) $this->find($publicId)['id'];
        }
        $db->table('bt_comments')->insert(['target_type' => $target, 'target_id' => $targetId, 'user_id' => $auth->userId(), 'body' => mb_substr($text, 0, 10000), 'kind' => 'COMMENT', 'created_at' => Bt::now(), 'updated_at' => Bt::now()]);
        $id = (int) $db->insertID();
        // @mentions: @usr_… or @email
        preg_match_all('/@(usr_[0-9A-Za-z]+|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $text, $m);
        $ids = [];
        foreach (array_unique($m[1]) as $h) {
            $u = $db->table('users')->select('id')->where(str_starts_with($h, 'usr_') ? 'public_id' : 'email', str_starts_with($h, 'usr_') ? $h : strtolower($h))->where('kind', 'STAFF')->get()->getRowArray();
            if ($u && (int) $u['id'] !== $auth->userId()) {
                $ids[] = (int) $u['id'];
            }
        }
        if ($ids !== []) {
            service('notifier')->notify('bugtrace.alert', ['title' => 'You were mentioned on ' . $publicId, 'body' => mb_substr($text, 0, 200), 'link' => $target === 'issue' ? '/admin/bugtrace/issues/' . $publicId : '/admin/bugtrace/reports/' . $publicId], $ids, false);
        }
        $c = $db->table('bt_comments')->where('id', $id)->get()->getRowArray();

        return ['id' => $id, 'user' => Bt::userRef($auth->userId()), 'body' => $c['body'], 'kind' => 'COMMENT', 'created_at' => Bt::iso($c['created_at']), 'mentions' => count($ids)];
    }

    /** @return array<string, mixed> */
    public function link(string $publicId, array $body, AuthContext $auth): array
    {
        $issue = $this->find($publicId);
        $kind  = strtolower((string) ($body['kind'] ?? ''));
        $ref   = trim((string) ($body['ref'] ?? ''));
        $url   = $body['url'] ?? null;
        $err   = [];
        if (! in_array($kind, ['github', 'task', 'support'], true)) {
            $err['kind'] = 'github, task or support';
        }
        if ($ref === '') {
            $err['ref'] = 'Required';
        }
        if ($url !== null && $url !== '' && (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('~^https?://~i', (string) $url))) {
            $err['url'] = 'Must be an http(s) URL';
        }
        if ($err !== []) {
            throw ApiException::validation($err);
        }
        Bt::db()->table('bt_issue_links')->insert(['issue_id' => $issue['id'], 'kind' => $kind, 'ref' => mb_substr($ref, 0, 255), 'url' => $url ?: null, 'created_by' => $auth->userId(), 'created_at' => Bt::now(), 'updated_at' => Bt::now()]);
        self::activity((int) $issue['id'], $auth->userId(), 'Linked ' . $kind . ' ' . $ref);
        if ($kind === 'github' && $issue['status'] === 'TRIAGED') {
            Bt::db()->table('bt_issues')->where('id', $issue['id'])->update(['status' => 'IN_PROGRESS']);
        }

        return ['kind' => $kind, 'ref' => $ref, 'url' => $url ?: null, 'created_at' => Bt::iso(Bt::now())];
    }

    /** @return array{updated: int, errors: array<string, mixed>} */
    public function bulk(array $ids, array $patch, AuthContext $auth): array
    {
        if ($ids === [] || count($ids) > 500) {
            throw ApiException::validation(['ids' => '1..500 issue ids required']);
        }
        $updated = 0;
        $errors  = [];
        foreach ($ids as $id) {
            try {
                $this->applyPatch($this->find((string) $id), $patch, $auth);
                $updated++;
            } catch (ApiException $e) {
                $errors[(string) $id] = $e->details !== [] ? $e->details : $e->getMessage();
            }
        }

        return ['updated' => $updated, 'errors' => $errors === [] ? new \stdClass() : $errors];
    }

    public function csv(array $q, AuthContext $auth): string
    {
        $rows = $this->filtered($q, $auth)->orderBy('bt_issues.last_seen', 'DESC')->limit(50000)->get()->getResultArray();
        $out  = fopen('php://temp', 'w+');
        fputcsv($out, ['id', 'project', 'type', 'title', 'culprit', 'status', 'resolution', 'resolve_version', 'priority', 'assignee', 'first_seen', 'last_seen', 'first_release', 'last_release', 'events', 'users', 'installations'], ',', '"', '');
        foreach ($rows as $r) {
            $f = $this->format($r, []);
            fputcsv($out, [$f['id'], $f['project']['name'] ?? '', $f['type'], self::csvSafe($f['title']), self::csvSafe((string) $f['culprit']), $f['status'], $f['resolution'], $f['resolve_version'], $f['priority'], $f['assignee']['name'] ?? '', $f['first_seen'], $f['last_seen'], $f['first_release'], $f['last_release'], $f['events_count'], $f['users_count'], $f['installations_count']], ',', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return (string) $csv;
    }

    /** Neutralise spreadsheet formula injection. */
    public static function csvSafe(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }
}

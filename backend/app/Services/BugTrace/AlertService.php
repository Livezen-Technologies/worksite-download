<?php

namespace App\Services\BugTrace;

use App\Exceptions\ApiException;
use App\Jobs\SendWebhookJob;
use App\Libraries\BugTrace\Bt;
use App\Libraries\Queue;
use App\Libraries\SecretStore;
use App\Libraries\Ulid;

/**
 * Alert rules and delivery (FR-BT-HLT-03, FR-BT-ING-06).
 *
 * Conditions: new_issue, regression, spike (x × hourly baseline), threshold
 * (events or users in a window), resolved (integrations), crash_free_below
 * (release gate). Channels: in_app, email (notification centre / mail to
 * bugtrace.triage holders), webhook (signed, queued SendWebhookJob — WorkSuite
 * Chat, Slack, GitHub bridges). Quiet hours ("22:00-07:00", Asia/Colombo)
 * defer delivery; digest rules are summarised by `spark bugtrace:alerts --digest`.
 */
class AlertService
{
    public const CONDITIONS = ['new_issue', 'regression', 'spike', 'threshold', 'resolved', 'crash_free_below'];
    public const CHANNELS   = ['in_app', 'email', 'webhook'];
    public const TIMEZONE   = 'Asia/Colombo';

    private const WEBHOOK_EVENTS = [
        'new_issue'        => 'bugtrace.issue.created',
        'regression'       => 'bugtrace.issue.regressed',
        'spike'            => 'bugtrace.issue.spike',
        'threshold'        => 'bugtrace.issue.threshold',
        'resolved'         => 'bugtrace.issue.resolved',
        'crash_free_below' => 'bugtrace.release.gate',
    ];

    /** Fire rules for an issue-level event (new issue, regression, resolved). */
    public function issueEvent(string $kind, array $issue, array $project): int
    {
        $fired = 0;
        foreach ($this->rules($kind, (int) ($issue['project_id'] ?? 0)) as $rule) {
            $t = Bt::decode($rule['threshold'], []);
            if (! empty($t['types']) && ! in_array($issue['type'], (array) $t['types'], true)) {
                continue;
            }
            if (! empty($t['min_priority']) && strcmp((string) $issue['priority'], (string) $t['min_priority']) > 0) {
                continue;
            }
            $dedupe = $kind . ':' . $issue['id'] . ($kind === 'regression' ? ':' . $issue['regressed_at'] : '') . ($kind === 'resolved' ? ':' . $issue['resolved_at'] : '');
            $fired += (int) $this->fire($rule, $kind, $issue, $project, $dedupe);
        }
        if ($kind === 'regression' && ! empty($issue['assignee_id'])) {
            // FR-BT-ING-06: the assignee is always alerted about a regression.
            service('notifier')->notify('bugtrace.alert', $this->payload('regression', $issue, $project), [(int) $issue['assignee_id']]);
        }

        return $fired;
    }

    /** @return list<array<string, mixed>> */
    private function rules(string $condition, int $projectId): array
    {
        return Bt::db()->table('bt_alert_rules')->where('enabled', 1)->where('condition', $condition)
            ->groupStart()->where('project_id', null)->orWhere('project_id', $projectId)->groupEnd()->get()->getResultArray();
    }

    /** @return array<string, mixed> */
    public function payload(string $kind, array $issue, array $project, array $extra = []): array
    {
        $titles = [
            'new_issue'  => 'New issue %s',
            'regression' => 'Regression %s',
            'spike'      => 'Spike in %s',
            'threshold'  => 'Threshold reached for %s',
            'resolved'   => 'Resolved %s',
        ];

        return [
            'title' => sprintf($titles[$kind] ?? 'Bug Trace alert %s', $issue['public_id']),
            'body'  => mb_substr((string) $issue['title'], 0, 200) . (isset($extra['detail']) ? ' — ' . $extra['detail'] : '') . sprintf(' (%d events, %d users)', $issue['events_count'], $issue['users_count']),
            'link'  => '/admin/bugtrace/issues/' . $issue['public_id'],
            'issue' => [
                'id'           => $issue['public_id'],
                'title'        => $issue['title'],
                'type'         => $issue['type'],
                'status'       => $issue['status'],
                'priority'     => $issue['priority'],
                'project'      => $project !== [] ? ['id' => Bt::projectPublicId($project), 'name' => $project['name'], 'platform' => $project['platform']] : null,
                'events_count' => (int) $issue['events_count'],
                'users_count'  => (int) $issue['users_count'],
                'last_release' => $issue['last_release'],
            ],
        ] + $extra;
    }

    /** Record an alert firing and deliver it (or defer it for quiet hours / digest). */
    public function fire(array $rule, string $kind, array $issue, array $project, string $dedupe, array $extra = []): bool
    {
        $db = Bt::db();
        if ($db->table('bt_alert_events')->where('rule_id', $rule['id'])->where('dedupe_key', $dedupe)->countAllResults() > 0) {
            return false;
        }
        $payload = $issue !== [] ? $this->payload($kind, $issue, $project, $extra) : $extra;
        $db->table('bt_alert_events')->insert([
            'rule_id'    => $rule['id'],
            'issue_id'   => $issue['id'] ?? null,
            'project_id' => $project['id'] ?? null,
            'kind'       => $kind,
            'message'    => (string) ($payload['title'] ?? $kind),
            'payload'    => Bt::json($payload),
            'dedupe_key' => $dedupe,
            'created_at' => Bt::now(),
            'updated_at' => Bt::now(),
        ]);
        $eventId = (int) $db->insertID();
        $db->table('bt_alert_rules')->where('id', $rule['id'])->update(['last_fired_at' => Bt::now()]);

        if ((int) $rule['digest'] === 1 || self::inQuietHours((string) ($rule['quiet_hours'] ?? ''))) {
            return true; // delivered later by bugtrace:alerts
        }
        $this->deliver($rule, $kind, $payload);
        $db->table('bt_alert_events')->where('id', $eventId)->update(['delivered_at' => Bt::now()]);

        return true;
    }

    private function deliver(array $rule, string $kind, array $payload): void
    {
        $channels = (array) Bt::decode($rule['channels'], ['in_app']);
        if (in_array('in_app', $channels, true) || in_array('email', $channels, true)) {
            service('notifier')->notify('bugtrace.alert', $payload, null, in_array('email', $channels, true));
        }
        if (in_array('webhook', $channels, true) && ! empty($rule['webhook_url'])) {
            Queue::push(SendWebhookJob::class, [
                'url'        => $rule['webhook_url'],
                'event'      => self::WEBHOOK_EVENTS[$kind] ?? 'bugtrace.alert',
                'payload'    => $payload,
                'secret_ref' => $rule['webhook_secret_ref'] ?? null,
            ], 'notifications');
        }
    }

    public static function inQuietHours(string $spec, ?int $now = null): bool
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', trim($spec), $m)) {
            return false;
        }
        $local = new \DateTimeImmutable('@' . ($now ?? time()));
        $local = $local->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $cur   = (int) $local->format('G') * 60 + (int) $local->format('i');
        $from  = (int) $m[1] * 60 + (int) $m[2];
        $to    = (int) $m[3] * 60 + (int) $m[4];

        return $from <= $to ? ($cur >= $from && $cur < $to) : ($cur >= $from || $cur < $to);
    }

    // --------------------------------------------------------- periodic checks

    /** Spike and threshold rules over the hourly stats. Returns alerts fired. */
    public function evaluateIssueRules(?int $now = null): int
    {
        $now   = $now ?? time();
        $db    = Bt::db();
        $fired = 0;
        $cur   = gmdate('Y-m-d H:00:00', $now);
        $prev  = gmdate('Y-m-d H:00:00', $now - 3600);
        $base0 = gmdate('Y-m-d H:00:00', $now - 26 * 3600);
        $rules = $db->table('bt_alert_rules')->where('enabled', 1)->whereIn('condition', ['spike', 'threshold'])->get()->getResultArray();
        if ($rules === []) {
            return 0;
        }
        // Issues active in the last 2 hours.
        $active = $db->table('bt_issue_stats_hourly s')->select('s.issue_id, SUM(s.events) AS events')
            ->where('s.hour >=', $prev)->groupBy('s.issue_id')->get()->getResultArray();
        foreach ($active as $a) {
            $issue = $db->table('bt_issues')->where('id', $a['issue_id'])->get()->getRowArray();
            if (! $issue || in_array($issue['status'], ['IGNORED', 'DUPLICATE', 'WONT_FIX'], true)) {
                continue;
            }
            $project = EventProcessor::project((int) $issue['project_id']) ?? [];
            $current = (int) ($db->table('bt_issue_stats_hourly')->selectSum('events')->where('issue_id', $issue['id'])->where('hour', $cur)->get()->getRow('events') ?? 0);
            $last    = (int) ($db->table('bt_issue_stats_hourly')->selectSum('events')->where('issue_id', $issue['id'])->where('hour', $prev)->get()->getRow('events') ?? 0);
            $window  = max($current, $last);
            $baseSum = (int) ($db->table('bt_issue_stats_hourly')->selectSum('events')->where('issue_id', $issue['id'])->where('hour >=', $base0)->where('hour <', $prev)->get()->getRow('events') ?? 0);
            $baseline = $baseSum / 24;
            foreach ($rules as $rule) {
                if ($rule['project_id'] !== null && (int) $rule['project_id'] !== (int) $issue['project_id']) {
                    continue;
                }
                $t = Bt::decode($rule['threshold'], []);
                if ($rule['condition'] === 'spike') {
                    $mult = (float) ($t['multiplier'] ?? 5);
                    $min  = (int) ($t['min_events'] ?? 20);
                    if ($window >= $min && $window >= $mult * max($baseline, 1.0)) {
                        $fired += (int) $this->fire($rule, 'spike', $issue, $project, 'spike:' . $issue['id'] . ':' . gmdate('YmdH', $now - ($now % 21600)), ['detail' => sprintf('%d events in the last hour vs %.1f/h baseline', $window, $baseline)]);
                    }
                } else {
                    $events = (int) ($t['events'] ?? 0);
                    $users  = (int) ($t['users'] ?? 0);
                    if (($events > 0 && $window >= $events) || ($users > 0 && (int) $issue['users_count'] >= $users)) {
                        $fired += (int) $this->fire($rule, 'threshold', $issue, $project, 'threshold:' . $issue['id'] . ':' . ($users > 0 && $events === 0 ? 'users' : gmdate('Ymd', $now)), ['detail' => $events > 0 ? "{$window} events in the last hour" : "{$issue['users_count']} users affected"]);
                    }
                }
            }
        }

        return $fired;
    }

    /** Deliver alerts deferred by quiet hours (non-digest rules) once quiet hours end. */
    public function deliverPending(): int
    {
        $db   = Bt::db();
        $sent = 0;
        foreach ($db->table('bt_alert_rules')->where('digest', 0)->get()->getResultArray() as $rule) {
            if (self::inQuietHours((string) ($rule['quiet_hours'] ?? ''))) {
                continue;
            }
            $pending = $db->table('bt_alert_events')->where('rule_id', $rule['id'])->where('delivered_at', null)->orderBy('id')->get()->getResultArray();
            if ($pending === []) {
                continue;
            }
            $this->deliverSummary($rule, $pending, 'Bug Trace alerts during quiet hours');
            $sent += count($pending);
        }

        return $sent;
    }

    /** Daily digest for digest rules. */
    public function sendDigest(): int
    {
        $db   = Bt::db();
        $sent = 0;
        foreach ($db->table('bt_alert_rules')->where('digest', 1)->where('enabled', 1)->get()->getResultArray() as $rule) {
            $pending = $db->table('bt_alert_events')->where('rule_id', $rule['id'])->where('delivered_at', null)->orderBy('id')->get()->getResultArray();
            if ($pending === []) {
                continue;
            }
            $this->deliverSummary($rule, $pending, 'Bug Trace digest: ' . $rule['name']);
            $sent += count($pending);
        }

        return $sent;
    }

    private function deliverSummary(array $rule, array $pending, string $title): void
    {
        if (count($pending) === 1) {
            $this->deliver($rule, (string) $pending[0]['kind'], Bt::decode($pending[0]['payload'], []));
        } else {
            $lines = array_map(static fn ($e) => '• ' . $e['message'], array_slice($pending, 0, 50));
            $this->deliver($rule, 'digest', ['title' => $title . ' (' . count($pending) . ')', 'body' => implode("\n", $lines), 'link' => '/admin/bugtrace/issues', 'items' => array_map(static fn ($e) => Bt::decode($e['payload'], []), array_slice($pending, 0, 50))]);
        }
        Bt::db()->table('bt_alert_events')->whereIn('id', array_column($pending, 'id'))->update(['delivered_at' => Bt::now()]);
    }

    // ------------------------------------------------------------------ CRUD

    /** @return array<string, mixed> */
    public static function format(array $r): array
    {
        $project = $r['project_id'] !== null ? EventProcessor::project((int) $r['project_id']) : null;

        return [
            'id'            => $r['public_id'],
            'name'          => $r['name'],
            'project'       => $project ? ['id' => Bt::projectPublicId($project), 'name' => $project['name'], 'platform' => $project['platform']] : null,
            'condition'     => $r['condition'],
            'threshold'     => Bt::decode($r['threshold'], new \stdClass()) ?: new \stdClass(),
            'channels'      => Bt::decode($r['channels'], []),
            'webhook_url'   => $r['webhook_url'],
            'webhook_secret_set' => ! empty($r['webhook_secret_ref']),
            'quiet_hours'   => $r['quiet_hours'],
            'digest'        => (bool) $r['digest'],
            'enabled'       => (bool) $r['enabled'],
            'last_fired_at' => Bt::iso($r['last_fired_at'] ?? null),
            'created_at'    => Bt::iso($r['created_at']),
            'updated_at'    => Bt::iso($r['updated_at']),
        ];
    }

    public function find(string $publicId): array
    {
        $r = Bt::db()->table('bt_alert_rules')->where('public_id', $publicId)->get()->getRowArray();
        if (! $r) {
            throw ApiException::notFound('NOT_FOUND', 'Alert rule not found');
        }

        return $r;
    }

    /** @return array<string, mixed> validated row fields */
    public function validate(array $body, bool $partial = false): array
    {
        $row    = [];
        $errors = [];
        if (! $partial || array_key_exists('name', $body)) {
            $name = trim((string) ($body['name'] ?? ''));
            $name === '' ? $errors['name'] = 'Required' : $row['name'] = mb_substr($name, 0, 150);
        }
        if (! $partial || array_key_exists('condition', $body)) {
            in_array($body['condition'] ?? null, self::CONDITIONS, true) ? $row['condition'] = $body['condition'] : $errors['condition'] = 'One of ' . implode(', ', self::CONDITIONS);
        }
        if (array_key_exists('threshold', $body)) {
            is_array($body['threshold']) || $body['threshold'] === null ? $row['threshold'] = Bt::json($body['threshold'] ?? new \stdClass()) : $errors['threshold'] = 'Must be an object';
        } elseif (! $partial) {
            $row['threshold'] = '{}';
        }
        if (! $partial || array_key_exists('channels', $body)) {
            $ch = array_values(array_unique((array) ($body['channels'] ?? ['in_app'])));
            array_diff($ch, self::CHANNELS) === [] && $ch !== [] ? $row['channels'] = Bt::json($ch) : $errors['channels'] = 'Subset of ' . implode(', ', self::CHANNELS);
        }
        if (array_key_exists('webhook_url', $body)) {
            $url = $body['webhook_url'];
            if ($url === null || $url === '') {
                $row['webhook_url'] = null;
            } elseif (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('~^https://~i', (string) $url) && config('UpdateCenter')->isProduction()) {
                $errors['webhook_url'] = 'Must be an https URL';
            } else {
                $row['webhook_url'] = (string) $url;
            }
        }
        if (array_key_exists('webhook_secret', $body) && (string) $body['webhook_secret'] !== '') {
            $row['webhook_secret_ref'] = SecretStore::seal((string) $body['webhook_secret']);
        }
        if (array_key_exists('quiet_hours', $body)) {
            $q = $body['quiet_hours'];
            $q === null || $q === '' ? $row['quiet_hours'] = null : (preg_match('/^\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}$/', (string) $q) ? $row['quiet_hours'] = (string) $q : $errors['quiet_hours'] = 'Format HH:MM-HH:MM');
        }
        foreach (['digest', 'enabled'] as $b) {
            if (array_key_exists($b, $body)) {
                $row[$b] = (int) (bool) $body[$b];
            }
        }
        if (array_key_exists('project', $body) || array_key_exists('project_id', $body)) {
            $pid = $body['project'] ?? $body['project_id'];
            if ($pid === null || $pid === '') {
                $row['project_id'] = null;
            } else {
                $p = ProjectService::resolve((string) $pid);
                $p === null ? $errors['project'] = 'Unknown project' : $row['project_id'] = (int) $p['id'];
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $row;
    }

    public function create(array $body, ?int $userId): array
    {
        $row = $this->validate($body) + ['public_id' => Ulid::prefixed('alr'), 'created_by' => $userId, 'enabled' => 1, 'digest' => 0, 'created_at' => Bt::now(), 'updated_at' => Bt::now()];
        Bt::db()->table('bt_alert_rules')->insert($row);

        return self::format($this->find($row['public_id']));
    }
}

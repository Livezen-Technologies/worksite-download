<?php

namespace App\Services\BugTrace;

use App\Exceptions\ApiException;
use App\Libraries\Base64Url;
use App\Libraries\BugTrace\Bt;
use App\Libraries\BugTrace\ReleaseLinker;
use App\Libraries\BugTrace\Scrubber;
use App\Libraries\BugTrace\Vault;
use App\Libraries\SecretStore;
use App\Services\AuthContext;

/**
 * User bug reports (§18.5, FR-BT-REP-01..09, FR-BT-ISS-05): acceptance with a
 * BR- reference and status token (idempotent on client_report_id), suggested
 * issue by fingerprint of the related event, inbox triage, replies and
 * reporter notifications (received → linked → fixed in X → closed).
 */
class ReportService
{
    public const SEVERITIES = ['MINOR', 'ANNOYING', 'BLOCKS_WORK', 'DATA_WRONG'];
    public const CATEGORIES = ['BUG', 'PERFORMANCE', 'DATA', 'UI', 'FEATURE_REQUEST'];
    public const STATUSES   = ['UNTRIAGED', 'LINKED', 'FEATURE_REQUEST', 'NEED_INFO', 'CLOSED'];

    private const SEVERITY_ALIASES = ['MINOR' => 'MINOR', 'ANNOYING' => 'ANNOYING', 'BLOCKS_WORK' => 'BLOCKS_WORK', 'BLOCKS MY WORK' => 'BLOCKS_WORK', 'BLOCKS_MY_WORK' => 'BLOCKS_WORK', 'BLOCKING' => 'BLOCKS_WORK', 'DATA_WRONG' => 'DATA_WRONG', 'DATA LOOKS WRONG' => 'DATA_WRONG', 'DATA_LOOKS_WRONG' => 'DATA_WRONG'];
    private const CATEGORY_ALIASES = ['BUG' => 'BUG', 'PERFORMANCE' => 'PERFORMANCE', 'DATA' => 'DATA', 'UI' => 'UI', 'FEATURE_REQUEST' => 'FEATURE_REQUEST', 'FEATURE REQUEST' => 'FEATURE_REQUEST', 'FEATURE' => 'FEATURE_REQUEST'];

    public static function statusToken(string $reference): string
    {
        return substr(Base64Url::encode(hash_hmac('sha256', 'bt-report|' . $reference, SecretStore::appSecret(), true)), 0, 32);
    }

    public static function publicStatus(array $r): string
    {
        return match ($r['status']) {
            'LINKED'          => $r['fixed_in'] ? 'fixed' : 'linked',
            'FEATURE_REQUEST' => 'feature_request',
            'NEED_INFO'       => 'need_info',
            'CLOSED'          => 'closed',
            default           => 'received',
        };
    }

    public function find(string $reference): array
    {
        $r = Bt::db()->table('bt_reports')->where('reference', strtoupper(trim($reference)))->get()->getRowArray();
        if (! $r) {
            throw ApiException::notFound('NOT_FOUND', 'Report not found');
        }

        return $r;
    }

    // ------------------------------------------------------------ ingestion

    /**
     * @param array<string, mixed> $ctx policy context (PolicyService::context)
     * @param array<string, mixed> $body
     *
     * @return array{reference: string, status_url: string, status_token: string, created: bool}
     */
    public function create(array $project, array $ctx, array $body, ?int $portalUserId = null, string $source = 'app'): array
    {
        $db = Bt::db();
        if (! ($ctx['policy']['user_reports'] ?? true)) {
            throw new ApiException('POLICY_DISABLED', 'Bug reports are turned off by your organisation', 403);
        }
        $clientId = isset($body['client_report_id']) && is_string($body['client_report_id']) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $body['client_report_id']) ? $body['client_report_id'] : null;
        if ($clientId !== null) {
            $existing = $db->table('bt_reports')->where('project_id', $project['id'])->where('client_report_id', $clientId)->get()->getRowArray();
            if ($existing) {
                return $this->receipt($existing['reference'], false);
            }
        }

        $errors   = [];
        $title    = trim((string) ($body['title'] ?? ''));
        $severity = self::SEVERITY_ALIASES[strtoupper(trim((string) ($body['severity'] ?? 'ANNOYING')))] ?? null;
        $category = self::CATEGORY_ALIASES[strtoupper(trim((string) ($body['category'] ?? 'BUG')))] ?? null;
        if ($title === '') {
            $errors['title'] = 'Required';
        }
        if ($severity === null) {
            $errors['severity'] = 'One of ' . implode(', ', self::SEVERITIES);
        }
        if ($category === null) {
            $errors['category'] = 'One of ' . implode(', ', self::CATEGORIES);
        }
        $email = null;
        if (! empty($body['contact']['consent']) && ! empty($body['contact']['email'])) {
            $email = strtolower(trim((string) $body['contact']['email']));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['contact.email'] = 'Invalid e-mail address';
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        $scrubber = new Scrubber(IngestService::customScrubRules((int) $project['id']));
        $steps    = array_values(array_filter(array_map(static fn ($s) => is_scalar($s) ? $scrubber->scrubString(mb_substr(trim((string) $s), 0, 500)) : null, array_slice((array) ($body['steps'] ?? []), 0, 30)), static fn ($s) => $s !== null && $s !== ''));
        $context  = $scrubber->scrubValue(is_array($body['context'] ?? null) ? $body['context'] : [], '', 0);
        $release  = isset($body['release']) ? mb_substr((string) $body['release'], 0, 80) : null;
        $link     = (new ReleaseLinker())->link((int) $project['application_id'], $release);

        $related   = isset($body['related_event_id']) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', (string) $body['related_event_id']) ? (string) $body['related_event_id'] : null;
        $suggested = $related !== null ? $db->table('bt_events')->select('issue_id')->where('event_id', $related)->get()->getRow('issue_id') : null;

        $reference = 'BR-' . Bt::nextCounter('report', 20001);
        $contactEnc = $email !== null ? SecretStore::seal($email) : null;
        if ($contactEnc === null && ! empty($body['contact']['consent']) && ! empty($body['contact']['email_ref']) && str_starts_with((string) $body['contact']['email_ref'], 'enc:')) {
            $contactEnc = 'ref:' . mb_substr((string) $body['contact']['email_ref'], 0, 500); // tenant-side relay reference (not decryptable here)
        }
        $row = [
            'reference'          => $reference,
            'client_report_id'   => $clientId,
            'project_id'         => $project['id'],
            'release_id'         => $link['release_id'],
            'release'            => $release,
            'app'                => isset($body['app']) ? mb_substr((string) $body['app'], 0, 80) : null,
            'title'              => mb_substr($scrubber->scrubString($title), 0, 255),
            'description'        => $scrubber->scrubString(mb_substr((string) ($body['description'] ?? ''), 0, 10000)),
            'steps'              => Bt::json($steps),
            'expected'           => $scrubber->scrubString(mb_substr((string) ($body['expected'] ?? ''), 0, 2000)),
            'severity'           => $severity,
            'category'           => $category,
            'status'             => $category === 'FEATURE_REQUEST' ? 'FEATURE_REQUEST' : 'UNTRIAGED',
            'suggested_issue_id' => $suggested,
            'related_event_id'   => $related,
            'context'            => Bt::json($context + ['bugtrace' => ['scrubbed' => $scrubber->summary()]]),
            'contact_consent'    => ! empty($body['contact']['consent']) ? 1 : 0,
            'contact_email_enc'  => ! empty($body['contact']['consent']) ? $contactEnc : null,
            'user_ref'           => isset($body['user_ref']) ? mb_substr((string) $body['user_ref'], 0, 128) : null,
            'portal_user_id'     => $portalUserId,
            'installation_ref'   => isset($body['installation_ref']) ? mb_substr((string) $body['installation_ref'], 0, 128) : null,
            'tenant_ref'         => $ctx['tenant_ref'] ?? null,
            'source'             => $source,
            'status_token_hash'  => hash('sha256', self::statusToken($reference)),
            'created_at'         => Bt::now(),
            'updated_at'         => Bt::now(),
        ];

        try {
            $db->table('bt_reports')->insert($row);
        } catch (\Throwable $e) {
            if ($clientId !== null && ($existing = $db->table('bt_reports')->where('project_id', $project['id'])->where('client_report_id', $clientId)->get()->getRowArray())) {
                return $this->receipt($existing['reference'], false);
            }

            throw $e;
        }
        $reportId = (int) $db->insertID();
        $this->addUpdate($reportId, $row['status'], 'Received — thank you for your report.');

        foreach (array_slice((array) ($body['attachments'] ?? []), 0, 10) as $a) {
            if (! is_array($a) || empty($a['upload_id'])) {
                continue;
            }
            $att = $db->table('bt_attachments')->where('public_id', (string) $a['upload_id'])->where('project_id', $project['id'])->where('owner_id', null)->get()->getRowArray();
            if (! $att || ($att['kind'] === 'screenshot' && ! $ctx['policy']['screenshots']) || ($att['kind'] === 'log' && ! $ctx['policy']['logs'])) {
                continue;
            }
            $meta = Bt::decode($att['meta'], []) + array_filter(['annotated' => isset($a['annotated']) ? (bool) $a['annotated'] : null, 'redacted_regions' => isset($a['redacted_regions']) ? (int) $a['redacted_regions'] : null], static fn ($v) => $v !== null);
            $db->table('bt_attachments')->where('id', $att['id'])->update(['owner_type' => 'report', 'owner_id' => $reference, 'meta' => Bt::json($meta), 'expires_at' => null, 'updated_at' => Bt::now()]);
        }

        $this->notifyReporter($db->table('bt_reports')->where('id', $reportId)->get()->getRowArray(), 'We received your bug report ' . $reference, "Thank you — your report \"{$row['title']}\" was received.\nReference: {$reference}\nWe will let you know when it is linked to a fix.");

        return $this->receipt($reference, true);
    }

    /** @return array{reference: string, status_url: string, status_token: string, created: bool} */
    private function receipt(string $reference, bool $created): array
    {
        return ['reference' => $reference, 'status_url' => '/api/v1/bugtrace/reports/' . $reference . '/status', 'status_token' => self::statusToken($reference), 'created' => $created];
    }

    /** @return array<string, mixed> */
    public function status(string $reference, string $token): array
    {
        $r = Bt::db()->table('bt_reports')->where('reference', strtoupper(trim($reference)))->get()->getRowArray();
        if (! $r || $token === '' || ! hash_equals((string) $r['status_token_hash'], hash('sha256', $token))) {
            throw ApiException::notFound('NOT_FOUND', 'Report not found'); // never reveal whether the reference exists
        }

        return [
            'reference'  => $r['reference'],
            'title'      => $r['title'],
            'status'     => self::publicStatus($r),
            'fixed_in'   => $r['fixed_in'],
            'created_at' => Bt::iso($r['created_at']),
            'updated_at' => Bt::iso($r['updated_at']),
            'updates'    => $this->updates((int) $r['id'], true),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function updates(int $reportId, bool $publicOnly): array
    {
        $b = Bt::db()->table('bt_report_updates')->where('report_id', $reportId);
        if ($publicOnly) {
            $b->where('public', 1);
        }

        return array_map(static fn ($u) => ['status' => $u['status'], 'message' => $u['message'], 'public' => (bool) $u['public'], 'created_at' => Bt::iso($u['created_at'])], $b->orderBy('id')->get()->getResultArray());
    }

    private function addUpdate(int $reportId, string $status, string $message, bool $public = true): void
    {
        $db = Bt::db();
        $db->table('bt_report_updates')->insert(['report_id' => $reportId, 'status' => $status, 'message' => mb_substr($message, 0, 5000), 'public' => $public ? 1 : 0, 'created_at' => Bt::now(), 'updated_at' => Bt::now()]);
        $db->table('bt_reports')->where('id', $reportId)->update(['updated_at' => Bt::now()]);
    }

    public static function reporterEmail(array $r): ?string
    {
        if (! (int) $r['contact_consent'] || empty($r['contact_email_enc']) || ! str_starts_with((string) $r['contact_email_enc'], 'enc:')) {
            return null;
        }
        $email = SecretStore::get((string) $r['contact_email_enc']);

        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function notifyReporter(array $r, string $subject, string $message): void
    {
        $email = self::reporterEmail($r);
        if ($email !== null) {
            service('notifier')->email($email, $subject, $message . "\n\nReference: " . $r['reference']);
        }
        if (! empty($r['portal_user_id'])) {
            service('notifier')->notify('bugtrace.report', ['title' => $subject, 'body' => mb_substr($message, 0, 300), 'link' => '/portal/bug-reports'], [(int) $r['portal_user_id']], false);
        }
    }

    // ----------------------------------------------------------------- admin

    /** @return array<string, mixed> ReportSummary */
    public function summary(array $r): array
    {
        $db      = Bt::db();
        $project = EventProcessor::project((int) $r['project_id']);
        $issue   = $r['issue_id'] ? $db->table('bt_issues')->select('public_id, title')->where('id', $r['issue_id'])->get()->getRowArray() : null;
        $sugg    = $r['suggested_issue_id'] && (int) $r['suggested_issue_id'] !== (int) $r['issue_id'] ? $db->table('bt_issues')->select('public_id, title, events_count')->where('id', $r['suggested_issue_id'])->get()->getRowArray() : null;

        return [
            'reference'       => $r['reference'],
            'title'           => $r['title'],
            'severity'        => $r['severity'],
            'category'        => $r['category'],
            'status'          => $r['status'],
            'app'             => $r['app'] ?? ($project ? $db->table('applications')->select('app_identifier')->where('id', $project['application_id'])->get()->getRow('app_identifier') : null),
            'platform'        => $project['platform'] ?? null,
            'project'         => $project ? ['id' => Bt::projectPublicId($project), 'name' => $project['name'], 'platform' => $project['platform']] : null,
            'release'         => $r['release'],
            'created_at'      => Bt::iso($r['created_at']),
            'updated_at'      => Bt::iso($r['updated_at']),
            'has_screenshot'  => $db->table('bt_attachments')->where('owner_type', 'report')->where('owner_id', $r['reference'])->where('kind', 'screenshot')->countAllResults() > 0,
            'suggested_issue' => $sugg ? ['id' => $sugg['public_id'], 'title' => $sugg['title'], 'events_count' => (int) $sugg['events_count']] : null,
            'issue'           => $issue ? ['id' => $issue['public_id'], 'title' => $issue['title']] : null,
            'fixed_in'        => $r['fixed_in'],
            'source'          => $r['source'],
        ];
    }

    /** @return array{data: list<array<string, mixed>>, total: int, counts: array<string, int>} */
    public function list(array $q, int $limit, int $offset): array
    {
        $db = Bt::db();
        $b  = $db->table('bt_reports');
        if (! empty($q['status'])) {
            $b->whereIn('status', explode(',', strtoupper((string) $q['status'])));
        }
        if (! empty($q['project'])) {
            $p = ProjectService::resolve((string) $q['project']);
            $b->where('project_id', $p['id'] ?? 0);
        }
        if (! empty($q['severity'])) {
            $b->whereIn('severity', explode(',', strtoupper((string) $q['severity'])));
        }
        if (! empty($q['issue'])) {
            $b->where('issue_id', (int) ($db->table('bt_issues')->select('id')->where('public_id', strtoupper((string) $q['issue']))->get()->getRow('id') ?? 0));
        }
        if (! empty($q['q'])) {
            $t = mb_substr(trim((string) $q['q']), 0, 100);
            $b->groupStart()->like('title', $t)->orLike('reference', $t)->orLike('description', $t)->groupEnd();
        }
        $total  = (clone $b)->countAllResults(false);
        $rows   = $b->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->limit($limit, $offset)->get()->getResultArray();
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($db->table('bt_reports')->select('status, COUNT(*) AS n')->groupBy('status')->get()->getResultArray() as $c) {
            $counts[$c['status']] = (int) $c['n'];
        }

        return ['data' => array_map(fn ($r) => $this->summary($r), $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'counts' => $counts];
    }

    /** @return array<string, mixed> */
    public function detail(string $reference, AuthContext $auth): array
    {
        $r   = $this->find($reference);
        $out = $this->summary($r) + [
            'description'      => $r['description'],
            'steps'            => Bt::decode($r['steps'], []),
            'expected'         => $r['expected'],
            'context'          => Bt::decode($r['context'], new \stdClass()) ?: new \stdClass(),
            'related_event_id' => $r['related_event_id'],
            'user_ref'         => $r['user_ref'],
            'installation_ref' => $r['installation_ref'],
            'close_reason'     => $r['close_reason'],
            'closed_at'        => Bt::iso($r['closed_at']),
            'attachments'      => $this->attachments('report', $r['reference']),
            'updates'          => $this->updates((int) $r['id'], false),
            'comments'         => (new IssueService())->comments((int) $r['id'], 'report'),
            'contact_consent'  => (bool) $r['contact_consent'],
            'contact'          => null,
        ];
        if ($auth->can('bugtrace.reporter.contact') && (int) $r['contact_consent']) {
            $email          = self::reporterEmail($r);
            $out['contact'] = ['email' => $email, 'relay_ref' => $email === null && str_starts_with((string) $r['contact_email_enc'], 'ref:') ? substr((string) $r['contact_email_enc'], 4) : null];
        }
        if ($auth->can('bugtrace.tenant.view')) {
            $out['tenant_ref'] = $r['tenant_ref'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function attachments(string $ownerType, string $ownerId): array
    {
        return array_map(static fn ($a) => [
            'id'           => $a['public_id'],
            'kind'         => $a['kind'],
            'url'          => Vault::signedPath($a['public_id']),
            'content_type' => $a['content_type'],
            'size'         => (int) $a['size'],
            'meta'         => Bt::decode($a['meta'], new \stdClass()) ?: new \stdClass(),
        ], Bt::db()->table('bt_attachments')->where('owner_type', $ownerType)->where('owner_id', $ownerId)->where('status', 'STORED')->orderBy('id')->get()->getResultArray());
    }

    /** @return array<string, mixed> */
    public function patch(string $reference, array $body, AuthContext $auth): array
    {
        $db     = Bt::db();
        $r      = $this->find($reference);
        $row    = [];
        $status = isset($body['status']) ? strtoupper((string) $body['status']) : null;
        if ($status !== null && ! in_array($status, self::STATUSES, true)) {
            throw ApiException::validation(['status' => 'One of ' . implode(', ', self::STATUSES)]);
        }
        $issue = null;
        if (array_key_exists('issue_id', $body)) {
            if ($body['issue_id'] === null || $body['issue_id'] === '') {
                $row['issue_id'] = null;
                $status ??= $r['status'] === 'LINKED' ? 'UNTRIAGED' : null;
            } else {
                $issue           = (new IssueService())->find((string) $body['issue_id']);
                $row['issue_id'] = (int) $issue['id'];
                $status ??= 'LINKED';
            }
        }
        if ($status === 'LINKED' && empty(array_key_exists('issue_id', $row) ? $row['issue_id'] : $r['issue_id'])) {
            throw ApiException::validation(['issue_id' => 'Link an issue to mark the report as LINKED']);
        }
        if ($status !== null) {
            $row['status'] = $status;
            if ($status === 'CLOSED') {
                $row['closed_at']    = Bt::now();
                $row['close_reason'] = isset($body['close_reason']) ? mb_substr((string) $body['close_reason'], 0, 1000) : null;
            } elseif ($r['status'] === 'CLOSED') {
                $row['closed_at'] = null;
            }
        }
        if ($row === []) {
            return $this->detail($reference, $auth);
        }
        $db->table('bt_reports')->where('id', $r['id'])->update($row + ['updated_at' => Bt::now()]);
        $fresh = $this->find($reference);
        if ($status !== null && $status !== $r['status'] || isset($row['issue_id']) && (int) $row['issue_id'] !== (int) $r['issue_id']) {
            $msg = match ($fresh['status']) {
                'LINKED'          => 'Your report was linked to a known problem our team is working on.',
                'FEATURE_REQUEST' => 'Your report was recorded as a feature request.',
                'NEED_INFO'       => trim('We need more information to investigate. ' . (string) ($body['message'] ?? '')),
                'CLOSED'          => 'Your report was closed.' . (! empty($row['close_reason']) ? ' ' . $row['close_reason'] : ''),
                default           => 'Your report is waiting for triage.',
            };
            $this->addUpdate((int) $r['id'], $fresh['status'], $msg);
            $this->notifyReporter($fresh, 'Update on your bug report ' . $fresh['reference'], $msg);
            IssueService::activity((int) $r['id'], $auth->userId(), 'Status → ' . $fresh['status'] . ($issue ? ' (' . $issue['public_id'] . ')' : ''), 'report');
            if ($issue !== null) {
                IssueService::activity((int) $issue['id'], $auth->userId(), 'Linked user report ' . $fresh['reference']);
                // Already fixed in a published release → tell the reporter now.
                if ($issue['status'] === 'RESOLVED' && ! empty($issue['resolve_version'])) {
                    $this->markFixed($issue, (string) $issue['resolve_version'], $issue['resolved_in_release_id'] !== null ? (int) $issue['resolved_in_release_id'] : null, true);
                }
            }
        }

        return $this->detail($reference, $auth);
    }

    /** @return array<string, mixed> */
    public function reply(string $reference, array $body, AuthContext $auth): array
    {
        $r   = $this->find($reference);
        $msg = trim((string) ($body['message'] ?? ''));
        if ($msg === '') {
            throw ApiException::validation(['message' => 'Required']);
        }
        if (($body['public'] ?? true) === false) {
            (new IssueService())->comment($reference, ['body' => $msg], $auth, 'report', (int) $r['id']);
        } else {
            $this->addUpdate((int) $r['id'], $r['status'], $msg);
            $this->notifyReporter($r, 'Reply to your bug report ' . $r['reference'], $msg);
            IssueService::activity((int) $r['id'], $auth->userId(), 'Replied to reporter', 'report');
        }

        return $this->detail($reference, $auth);
    }

    /** @return array<string, mixed> the new Issue */
    public function createIssue(string $reference, AuthContext $auth): array
    {
        $db = Bt::db();
        $r  = $this->find($reference);
        if ($r['issue_id']) {
            throw ApiException::conflict('ALREADY_LINKED', 'Report is already linked to an issue');
        }
        $project = EventProcessor::project((int) $r['project_id']);
        $seq     = Bt::nextCounter('issue:' . $project['id'], 1296);
        $context = Bt::decode($r['context'], []);
        $db->table('bt_issues')->insert([
            'public_id'        => $project['issue_prefix'] . '-' . Bt::base36($seq),
            'project_id'       => $project['id'],
            'fingerprint'      => hash('sha256', 'report:' . $r['reference']),
            'type'             => $r['category'] === 'PERFORMANCE' ? 'hang' : 'error',
            'title'            => $r['title'],
            'culprit'          => isset($context['app']['route']) ? mb_substr((string) $context['app']['route'], 0, 255) : null,
            'status'           => 'TRIAGED',
            'priority'         => match ($r['severity']) { 'BLOCKS_WORK', 'DATA_WRONG' => 'P2', 'ANNOYING' => 'P3', default => 'P4' },
            'first_seen'       => $r['created_at'],
            'last_seen'        => $r['created_at'],
            'first_release_id' => $r['release_id'],
            'first_release'    => $r['release'],
            'last_release'     => $r['release'],
            'users_count'      => $r['user_ref'] ? 1 : 0,
            'labels'           => Bt::json(['user-report']),
            'created_at'       => Bt::now(),
            'updated_at'       => Bt::now(),
        ]);
        $issueId = (int) $db->insertID();
        if ($r['user_ref']) {
            $db->table('bt_issue_users')->ignore(true)->insert(['issue_id' => $issueId, 'kind' => 'user', 'ref' => $r['user_ref']]);
        }
        IssueService::activity($issueId, $auth->userId(), 'Created from user report ' . $r['reference']);
        $issue = (new IssueService())->findById($issueId);
        $this->patch($reference, ['issue_id' => $issue['public_id']], $auth);

        return (new IssueService())->format((new IssueService())->findById($issueId));
    }

    // ------------------------------------------------------ issue lifecycle

    public function onIssueResolved(array $issue): void
    {
        $db = Bt::db();
        if ($issue['resolution'] === 'VERSION' && ! empty($issue['resolve_version'])) {
            $published = $issue['resolved_in_release_id'] !== null && $db->table('releases')->where('id', $issue['resolved_in_release_id'])->where('status', 'PUBLISHED')->countAllResults() > 0;
            if ($published) {
                $this->markFixed($issue, (string) $issue['resolve_version'], (int) $issue['resolved_in_release_id']);

                return;
            }
            $this->broadcast($issue, 'A fix is planned for version ' . $issue['resolve_version'] . '. We will let you know when it is available.');

            return;
        }
        if ($issue['resolution'] === 'NEXT_RELEASE') {
            $this->broadcast($issue, 'A fix will ship in the next release. We will let you know when it is available.');

            return;
        }
        $this->broadcast($issue, 'The problem you reported has been fixed.', true);
    }

    public function onIssueClosed(array $issue, string $why, string $reason): void
    {
        $this->broadcast($issue, "Our team decided not to fix this problem" . ($reason !== '' ? ': ' . mb_substr($reason, 0, 500) : '.'), true);
    }

    private function broadcast(array $issue, string $message, bool $email = false): void
    {
        foreach (Bt::db()->table('bt_reports')->where('issue_id', $issue['id'])->where('status !=', 'CLOSED')->get()->getResultArray() as $r) {
            $this->addUpdate((int) $r['id'], $r['status'], $message);
            if ($email) {
                $this->notifyReporter($r, 'Update on your bug report ' . $r['reference'], $message);
            }
        }
    }

    /**
     * Reporters learn that their problem is fixed in a published release
     * (FR-BT-REP-06, FR-BT-ISS-07, AC-C69). Each report is notified once.
     */
    public function markFixed(array $issue, string $version, ?int $releaseId, bool $onlyUnnotified = true): int
    {
        $db = Bt::db();
        $b  = $db->table('bt_reports')->where('issue_id', $issue['id'])->where('status !=', 'CLOSED');
        if ($onlyUnnotified) {
            $b->where('notified_fixed_at', null);
        }
        $n = 0;
        foreach ($b->get()->getResultArray() as $r) {
            $msg = "Fixed in {$version} — update available.";
            $db->table('bt_reports')->where('id', $r['id'])->update(['fixed_in' => $version, 'fixed_release_id' => $releaseId, 'notified_fixed_at' => Bt::now(), 'status' => 'LINKED', 'updated_at' => Bt::now()]);
            $this->addUpdate((int) $r['id'], 'LINKED', $msg);
            $this->notifyReporter($r, "Fixed in {$version}: " . $r['title'], "Good news — the problem you reported (\"{$r['title']}\") is fixed in WorkSuite {$version}. Please update to get the fix.");
            $n++;
        }

        return $n;
    }

    /** "My bug reports" for a signed-in portal user (FR-BT-REP-07). */
    public function forPortalUser(int $userId): array
    {
        $rows = Bt::db()->table('bt_reports')->where('portal_user_id', $userId)->orderBy('created_at', 'DESC')->limit(200)->get()->getResultArray();

        return ['data' => array_map(static fn ($r) => ['reference' => $r['reference'], 'title' => $r['title'], 'status' => self::publicStatus($r), 'updated_at' => Bt::iso($r['updated_at']), 'fixed_in' => $r['fixed_in']], $rows)];
    }
}

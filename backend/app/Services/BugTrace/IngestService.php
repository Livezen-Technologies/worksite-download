<?php

namespace App\Services\BugTrace;

use App\Exceptions\ApiException;
use App\Jobs\BugTraceProcessEventJob;
use App\Jobs\BugTraceProcessSessionsJob;
use App\Libraries\BugTrace\Bt;
use App\Libraries\BugTrace\Scrubber;
use App\Libraries\Queue;
use App\Libraries\Ulid;

/**
 * Ingest side of Bug Trace (FR-BT-ING-01/02/03/08): authenticates the project
 * key, applies the tenant policy, quotas and sampling, scrubs PII and queues
 * the rest of the processing. Nothing here waits for processing.
 *
 * Scrubbing runs at ingest (it is cheap) so that raw PII never reaches the
 * job queue table or any other storage.
 */
class IngestService
{
    public const MAX_EVENTS_PER_ENVELOPE = 200;
    public const MAX_SESSIONS_PER_ENVELOPE = 500;

    public function __construct(private ?PolicyService $policies = null, private ?QuotaService $quotas = null)
    {
        $this->policies ??= new PolicyService();
        $this->quotas   ??= new QuotaService();
    }

    public function policies(): PolicyService
    {
        return $this->policies;
    }

    /** Resolve an active project by public ingest key. Raw license keys are never accepted here. */
    public function project(?string $key): array
    {
        $key = trim((string) $key);
        if ($key === '' || ! preg_match('/^pk_[A-Za-z0-9_]{8,60}$/', $key)) {
            throw new ApiException('BT_KEY_INVALID', 'Missing or invalid X-BT-Key', 401);
        }
        $p = Bt::db()->table('bt_projects')->where('public_key', $key)->get()->getRowArray();
        if (! $p || $p['status'] !== 'ACTIVE') {
            throw new ApiException('BT_KEY_INVALID', 'Unknown or disabled ingest key', 401);
        }

        return $p;
    }

    /** @return list<array{pattern: string, replacement: ?string}> */
    public static function customScrubRules(int $projectId): array
    {
        return Bt::db()->table('bt_scrub_rules')->select('pattern, replacement')->where('enabled', 1)
            ->groupStart()->where('scope', 'global')->orWhere('project_id', $projectId)->groupEnd()->get()->getResultArray();
    }

    /**
     * @param array<string, mixed> $ctx    from PolicyService::context() + country_code, ip_key
     * @param list<mixed>          $events
     *
     * @return array{ids: list<string>, accepted: int, dropped: array<string, int>}
     */
    public function acceptEvents(array $project, array $ctx, array $events, ?int $now = null): array
    {
        $now     = $now ?? time();
        $dropped = ['policy' => 0, 'quota' => 0, 'sampled' => 0, 'invalid' => 0];
        $ids     = [];
        $keep    = [];
        foreach (array_slice($events, 0, self::MAX_EVENTS_PER_ENVELOPE) as $e) {
            if (! is_array($e)) {
                $dropped['invalid']++;

                continue;
            }
            $type = strtolower((string) ($e['type'] ?? (isset($e['exception']) ? 'error' : 'message')));
            if (! in_array($type, ['crash', 'error', 'hang', 'message'], true)) {
                $type = 'error';
            }
            $e['type'] = $type;
            if (! isset($e['event_id']) || ! is_string($e['event_id']) || ! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $e['event_id'])) {
                $e['event_id'] = Ulid::generate();
            }
            if (! PolicyService::allows($ctx['policy'], $type, (string) $project['platform'])) {
                $dropped['policy']++;

                continue;
            }
            if (! $this->sampled($project, $e, $type)) {
                $dropped['sampled']++;

                continue;
            }
            $keep[] = $e;
        }
        $dropped['invalid'] += max(0, count($events) - self::MAX_EVENTS_PER_ENVELOPE);

        $instKey = (string) ($ctx['installation_id'] ?? ($keep[0]['installation_ref'] ?? null) ?? $ctx['ip_key'] ?? 'anon');
        $allowed = $this->quotas->admit($project, $instKey, count($keep), $now);
        $dropped['quota'] = count($keep) - $allowed;
        $keep = array_slice($keep, 0, $allowed);
        $this->quotas->countDropped($project, 'policy', $dropped['policy']);
        $this->quotas->countDropped($project, 'sampled', $dropped['sampled']);

        $rules = self::customScrubRules((int) $project['id']);
        foreach ($keep as $e) {
            $scrubber = new Scrubber($rules);
            $clean    = $scrubber->scrubEvent($e);
            $ids[]    = (string) $clean['event_id'];
            Queue::push(BugTraceProcessEventJob::class, [
                'project_id'    => (int) $project['id'],
                'event'         => $clean,
                'received_at'   => $now,
                'tenant_ref'    => $ctx['tenant_ref'] ?? null,
                'edition'       => $ctx['edition'] ?? null,
                'country_code'  => $ctx['country_code'] ?? null,
                'scrub_count'   => $scrubber->count(),
                'scrub_summary' => $scrubber->summary(),
                'symbolication' => $e['_symbolication'] ?? null,
            ], 'bugtrace');
        }

        return ['ids' => $ids, 'accepted' => count($ids), 'dropped' => $dropped];
    }

    /**
     * Per-project sample rates for events the client did not already sample
     * (events carrying `sample_rate` were sampled by the SDK — FR-BT-SDK-08).
     */
    private function sampled(array $project, array $e, string $type): bool
    {
        if (isset($e['sample_rate'])) {
            return true;
        }
        $rates = Bt::decode($project['sample_rates'] ?? null, []);
        $rate  = (float) ($rates[$type] ?? 1.0);
        if ($rate >= 1.0) {
            return true;
        }

        return Bt::sampleValue((string) $e['event_id']) < $rate;
    }

    /**
     * @param list<mixed> $sessions
     */
    public function acceptSessions(array $project, array $ctx, array $sessions, ?int $now = null): int
    {
        if ($sessions === [] || $ctx['policy']['policy'] === 'OFF' || ($project['platform'] === 'server' && ! $ctx['policy']['server_reporting'])) {
            return 0;
        }
        $clean = [];
        foreach (array_slice($sessions, 0, self::MAX_SESSIONS_PER_ENVELOPE) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $status = strtolower((string) ($s['status'] ?? 'ok'));
            $clean[] = [
                'sid'      => mb_substr((string) ($s['sid'] ?? $s['session_id'] ?? ''), 0, 64),
                'init'     => (bool) ($s['init'] ?? ($status === 'started')),
                'status'   => in_array($status, ['started', 'ok', 'exited', 'crashed', 'abnormal', 'errored'], true) ? $status : 'ok',
                'release'  => mb_substr((string) ($s['release'] ?? $s['attrs']['release'] ?? ''), 0, 80),
                'user_ref' => isset($s['user_ref']) ? mb_substr((string) $s['user_ref'], 0, 128) : (isset($s['did']) ? mb_substr((string) $s['did'], 0, 128) : null),
                'started'  => $s['started'] ?? $s['timestamp'] ?? null,
            ];
        }
        if ($clean === []) {
            return 0;
        }
        Queue::push(BugTraceProcessSessionsJob::class, ['project_id' => (int) $project['id'], 'sessions' => $clean, 'received_at' => $now ?? time()], 'bugtrace');

        return count($clean);
    }

    /**
     * Parse an envelope body: a single event, `{events:[…], sessions:[…]}`, or
     * a Sentry-style newline-delimited envelope (§16.1 Q12).
     *
     * @return array{events: list<mixed>, sessions: list<mixed>, tpt: ?string}
     */
    public static function parseEnvelope(string $raw): array
    {
        $raw  = trim($raw);
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $tpt = isset($json['tpt']) && is_string($json['tpt']) ? $json['tpt'] : null;
            if (array_is_list($json)) {
                return ['events' => $json, 'sessions' => [], 'tpt' => null];
            }
            if (isset($json['events']) || isset($json['sessions'])) {
                return ['events' => array_values((array) ($json['events'] ?? [])), 'sessions' => array_values((array) ($json['sessions'] ?? [])), 'tpt' => $tpt];
            }
            unset($json['tpt']);

            return ['events' => [$json], 'sessions' => [], 'tpt' => $tpt];
        }
        // Sentry envelope: header line, then (item header, payload) pairs.
        $lines = explode("\n", $raw);
        if (count($lines) < 2 || ! is_array(json_decode($lines[0], true))) {
            throw new ApiException('INVALID_ENVELOPE', 'Envelope is neither JSON nor a newline-delimited envelope', 400);
        }
        $events = $sessions = [];
        for ($i = 1; $i + 1 < count($lines); $i += 2) {
            $hdr     = json_decode($lines[$i], true);
            $payload = json_decode($lines[$i + 1], true);
            if (! is_array($hdr) || ! is_array($payload)) {
                continue;
            }
            match ($hdr['type'] ?? '') {
                'event'    => $events[] = $payload,
                'session'  => $sessions[] = $payload,
                'sessions' => $sessions = array_merge($sessions, (array) ($payload['aggregates'] ?? [])),
                default    => null,
            };
        }

        return ['events' => $events, 'sessions' => $sessions, 'tpt' => null];
    }

    /** Coarse country from the edge (Cloudflare / nginx GeoIP) — the IP itself is discarded. */
    public static function country(\CodeIgniter\HTTP\IncomingRequest $req): ?string
    {
        foreach (['CF-IPCountry', 'X-Country-Code', 'X-GeoIP-Country'] as $h) {
            $v = strtoupper(trim($req->getHeaderLine($h)));
            if (preg_match('/^[A-Z]{2}$/', $v) && $v !== 'XX' && $v !== 'T1') {
                return $v;
            }
        }

        return null;
    }
}

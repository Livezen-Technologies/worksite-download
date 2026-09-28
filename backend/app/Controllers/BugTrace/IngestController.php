<?php

namespace App\Controllers\BugTrace;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Libraries\BugTrace\Bt;
use App\Libraries\BugTrace\Vault;
use App\Libraries\SecretStore;
use App\Libraries\Ulid;
use App\Services\BugTrace\AttachmentService;
use App\Services\BugTrace\IngestService;
use App\Services\BugTrace\PolicyService;
use App\Services\BugTrace\ProjectService;
use App\Services\BugTrace\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public Bug Trace SDK endpoints (§18.12): /api/v1/bugtrace/*
 * Auth: per-project public ingest key (X-BT-Key or ?bt_key= for sendBeacon)
 * plus optional Tenant Policy Token (X-WorkSuite-Tenant-Policy, `tpt` field or
 * ?tpt=). Responses carry permissive CORS headers (no credentials).
 */
class IngestController extends BaseApiController
{
    private IngestService $ingest;

    public function _remap(string $method, ...$params)
    {
        $this->ingest = new IngestService();
        $res          = parent::_remap($method, ...$params);
        if ($res instanceof ResponseInterface) {
            self::cors($res);
        }

        return $res;
    }

    public static function cors(ResponseInterface $res): ResponseInterface
    {
        return $res->setHeader('Access-Control-Allow-Origin', '*')
            ->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, OPTIONS')
            ->setHeader('Access-Control-Allow-Headers', 'Content-Type, Content-Encoding, X-BT-Key, X-WorkSuite-Tenant-Policy, X-BT-Status-Token, X-CSRF-Token')
            ->setHeader('Access-Control-Max-Age', '86400')
            ->setHeader('Vary', 'Origin');
    }

    public function preflight(...$ignored): ResponseInterface
    {
        return $this->response->setStatusCode(204)->setBody('');
    }

    private function key(): ?string
    {
        $k = trim($this->request->getHeaderLine('X-BT-Key'));

        return $k !== '' ? $k : ($this->request->getGet('bt_key') ?: null);
    }

    private function tpt(?string $fromBody = null): ?string
    {
        $t = trim($this->request->getHeaderLine('X-WorkSuite-Tenant-Policy'));

        return $t !== '' ? $t : ($fromBody ?? ($this->request->getGet('tpt') ?: null));
    }

    /** @return array<string, mixed> */
    private function context(?string $tpt): array
    {
        $ctx                 = $this->ingest->policies()->context($tpt);
        $ctx['country_code'] = IngestService::country($this->request);
        // The IP is only used to key per-client quotas (hashed) and is then discarded.
        $ctx['ip_key'] = 'ip:' . substr(hash_hmac('sha256', (string) $this->request->getIPAddress(), SecretStore::appSecret()), 0, 24);

        return $ctx;
    }

    private function rawBody(int $max): string
    {
        $raw = (string) $this->request->getBody();
        if (strlen($raw) > $max) {
            throw new ApiException('PAYLOAD_TOO_LARGE', 'Envelope exceeds ' . $max . ' bytes', 413);
        }
        $gz = strtolower($this->request->getHeaderLine('Content-Encoding')) === 'gzip' || str_starts_with($raw, "\x1f\x8b");
        if ($gz && $raw !== '') {
            $dec = @gzdecode($raw, 10 * $max);
            if ($dec === false) {
                throw new ApiException('INVALID_ENCODING', 'Body is not valid gzip or expands beyond the limit', 400);
            }
            $raw = $dec;
        }

        return $raw;
    }

    /** POST /api/v1/bugtrace/envelope → 202 */
    public function envelope(): ResponseInterface
    {
        $project = $this->ingest->project($this->key());
        $raw     = $this->rawBody(config('UpdateCenter')->btEnvelopeMaxBytes);
        if (trim($raw) === '') {
            throw new ApiException('INVALID_ENVELOPE', 'Empty envelope', 400);
        }
        $env    = IngestService::parseEnvelope($raw);
        $ctx    = $this->context($this->tpt($env['tpt']));
        $result = $this->ingest->acceptEvents($project, $ctx, $env['events']);
        $sess   = $this->ingest->acceptSessions($project, $ctx, $env['sessions']);

        return $this->ok($result + ['sessions' => $sess, 'policy' => self::clientPolicy($ctx, $project)], 202);
    }

    /** POST /api/v1/bugtrace/minidump (multipart, ≤ 20 MB) → 202 */
    public function minidump(): ResponseInterface
    {
        $project = $this->ingest->project($this->key());
        $ctx     = $this->context($this->tpt($this->request->getPost('tpt')));
        $file    = null;
        foreach (['upload_file_minidump', 'minidump', 'file'] as $field) {
            $f = $this->request->getFile($field);
            if (Bt::uploadOk($f)) {
                $file = $f;
                break;
            }
        }
        if ($file === null) {
            throw ApiException::validation(['upload_file_minidump' => 'Minidump file is required']);
        }
        if ($file->getSize() > config('UpdateCenter')->btMinidumpMaxBytes) {
            throw new ApiException('PAYLOAD_TOO_LARGE', 'Minidump exceeds 20 MB', 413);
        }
        $fields = $this->request->getPost() ?? [];
        $event  = is_string($fields['event'] ?? null) ? (json_decode($fields['event'], true) ?: []) : [];
        $ptype  = (string) ($event['tags']['process_type'] ?? $fields['process_type'] ?? $fields['ptype'] ?? 'browser');
        $event += [
            'event_id'         => (string) ($fields['event_id'] ?? $fields['guid'] ?? Ulid::generate()),
            'type'             => 'crash',
            'platform'         => (string) ($fields['platform'] ?? $project['platform']),
            'release'          => (string) ($fields['release'] ?? $fields['ver'] ?? ''),
            'environment'      => (string) ($fields['environment'] ?? 'production'),
            'installation_ref' => $fields['installation_ref'] ?? null,
            'user_ref'         => $fields['user_ref'] ?? null,
            'session_id'       => $fields['session_id'] ?? null,
            'edition'          => $fields['edition'] ?? null,
            'timestamp'        => $fields['timestamp'] ?? gmdate('c'),
        ];
        if (! preg_match('/^[A-Za-z0-9_-]{8,64}$/', (string) $event['event_id'])) {
            $event['event_id'] = Ulid::generate();
        }
        $event['type'] = 'crash';
        if (! PolicyService::allows($ctx['policy'], 'crash', (string) $project['platform'])) {
            return $this->ok(['ids' => [], 'accepted' => 0, 'dropped' => ['policy' => 1], 'policy' => self::clientPolicy($ctx, $project)], 202);
        }
        $signal = (string) ($fields['signal'] ?? $event['exception'][0]['signal'] ?? 'NativeCrash');
        $event['exception'] = $event['exception'] ?? [[
            'type'       => mb_substr($signal, 0, 64),
            'value'      => 'Native crash in ' . $ptype . ' process',
            'module'     => isset($fields['crash_module']) ? (string) $fields['crash_module'] : null,
            'signal'     => $signal,
            'mechanism'  => ['type' => 'minidump', 'handled' => false],
            'stacktrace' => ['frames' => []],
        ]];
        $event['tags'] = ($event['tags'] ?? []) + ['process_type' => $ptype];

        $att = (new AttachmentService())->storeFile($project, 'minidump', $file->getTempName(), 'application/octet-stream', ['process_type' => $ptype, 'original_name' => mb_substr($file->getClientName(), 0, 100)]);
        $event['attachments']    = [['kind' => 'minidump', 'upload_id' => $att['id']]];
        $event['_symbolication'] = 'pending';
        $res = $this->ingest->acceptEvents($project, $ctx, [$event]);
        if ($res['accepted'] === 0) {
            $row = Bt::db()->table('bt_attachments')->where('public_id', $att['id'])->get()->getRowArray();
            Vault::delete((string) $row['storage_key']);
            Bt::db()->table('bt_attachments')->where('public_id', $att['id'])->delete();
        }

        return $this->ok($res + ['attachment_id' => $res['accepted'] ? $att['id'] : null, 'symbolication' => 'pending', 'policy' => self::clientPolicy($ctx, $project)], 202);
    }

    /**
     * POST /api/v1/bugtrace/attachments
     * - multipart/form-data: file + kind (+ meta JSON) → 201 stored
     * - JSON {kind, size, content_type} → 201 {id, upload_url (PUT, 15 min)}
     * - raw body with ?kind= and Content-Type → 201 stored
     */
    public function attachments(): ResponseInterface
    {
        $project = $this->ingest->project($this->key());
        $ctx     = $this->context($this->tpt());
        $svc     = new AttachmentService();
        $ct      = strtolower($this->request->getHeaderLine('Content-Type'));

        if (str_contains($ct, 'multipart/form-data')) {
            $kind = (string) $this->request->getPost('kind');
            $svc->checkKind($kind, $ctx['policy']);
            $file = $this->request->getFile('file');
            if (! Bt::uploadOk($file)) {
                throw ApiException::validation(['file' => 'File is required']);
            }
            $meta = json_decode((string) $this->request->getPost('meta'), true);

            return $this->ok($svc->storeFile($project, $kind, $file->getTempName(), $file->getClientMimeType() ?: $file->getMimeType(), self::meta(is_array($meta) ? $meta : [])), 201);
        }
        if (str_contains($ct, 'application/json')) {
            $b    = $this->body();
            $kind = (string) ($b['kind'] ?? '');
            $svc->checkKind($kind, $ctx['policy']);

            return $this->ok($svc->init($project, $kind, (int) ($b['size'] ?? 0), (string) ($b['content_type'] ?? 'application/octet-stream')), 201);
        }
        $kind = (string) $this->request->getGet('kind');
        $svc->checkKind($kind, $ctx['policy']);

        return $this->ok($this->storeRaw($project, $kind, $ct, $svc), 201);
    }

    /** PUT /api/v1/bugtrace/attachments/{id}/upload?exp=&sig= */
    public function upload(string $id): ResponseInterface
    {
        $raw = (string) $this->request->getBody();
        $tmp = tempnam(sys_get_temp_dir(), 'btu');
        file_put_contents($tmp, $raw);
        try {
            $res = (new AttachmentService())->completeUpload($id, (int) $this->request->getGet('exp'), (string) $this->request->getGet('sig'), $tmp, $this->request->getHeaderLine('Content-Type'));
        } finally {
            @unlink($tmp);
        }

        return $this->ok($res, 200);
    }

    private function storeRaw(array $project, string $kind, string $ct, AttachmentService $svc): array
    {
        $raw = (string) $this->request->getBody();
        if ($raw === '') {
            throw ApiException::validation(['body' => 'Empty upload']);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'btu');
        file_put_contents($tmp, $raw);
        try {
            return $svc->storeFile($project, $kind, $tmp, $ct);
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array<string, mixed> */
    private static function meta(array $m): array
    {
        return array_filter([
            'annotated'        => isset($m['annotated']) ? (bool) $m['annotated'] : null,
            'redacted_regions' => isset($m['redacted_regions']) ? (int) $m['redacted_regions'] : null,
            'width'            => isset($m['width']) ? (int) $m['width'] : null,
            'height'           => isset($m['height']) ? (int) $m['height'] : null,
            'duration_s'       => isset($m['duration_s']) ? (float) $m['duration_s'] : null,
        ], static fn ($v) => $v !== null);
    }

    /** POST /api/v1/bugtrace/reports → 201 {reference, status_url, status_token} */
    public function reports(): ResponseInterface
    {
        $project = $this->ingest->project($this->key());
        $body    = json_decode($this->rawBody(config('UpdateCenter')->btEnvelopeMaxBytes), true);
        if (! is_array($body)) {
            throw new ApiException('INVALID_JSON', 'Request body is not valid JSON', 400);
        }
        $ctx    = $this->context($this->tpt(is_string($body['tpt'] ?? null) ? $body['tpt'] : null));
        $portal = $this->auth->user !== null ? $this->auth->userId() : null;
        $r      = (new ReportService())->create($project, $ctx, $body, $portal, 'app');

        return $this->ok(['reference' => $r['reference'], 'status_url' => $r['status_url'], 'status_token' => $r['status_token']], $r['created'] ? 201 : 200);
    }

    /**
     * POST /api/v1/bugtrace/reports/public — anonymous download/installation
     * problem reports from the Update Center website (FR-BT-REP-09): captcha,
     * honeypot and rl:report (10/h per IP).
     */
    public function publicReport(): ResponseInterface
    {
        $body = $this->body();
        if (! empty($body['website'])) { // honeypot
            return $this->ok(['reference' => 'BR-0', 'status_url' => null, 'status_token' => null], 201);
        }
        if (! self::verifyCaptcha((string) ($body['captcha_token'] ?? ''), (string) $this->request->getIPAddress())) {
            throw new ApiException('CAPTCHA_FAILED', 'Captcha verification failed', 422);
        }
        $appRef = (string) ($body['app'] ?? '');
        $app    = Bt::db()->table('applications')->groupStart()->where('slug', $appRef)->orWhere('app_identifier', $appRef)->orWhere('public_id', $appRef)->groupEnd()->get()->getRowArray();
        $project = null;
        if ($app) {
            foreach (['desktop', 'server', 'web', 'mobile'] as $platform) {
                $project = Bt::db()->table('bt_projects')->where('application_id', $app['id'])->where('platform', $platform)->where('status', 'ACTIVE')->get()->getRowArray();
                if ($project) {
                    break;
                }
            }
        }
        $project ??= ProjectService::resolve('desktop');
        if ($project === null) {
            throw ApiException::validation(['app' => 'Unknown application']);
        }
        $ctx = ['tenant_ref' => null, 'policy' => (new PolicyService())->effective(null, null, null, false)];
        $body['category'] ??= 'BUG';
        $body['app'] = $app['app_identifier'] ?? $appRef;
        unset($body['attachments'], $body['user_ref'], $body['installation_ref']);
        $portal = $this->auth->user !== null ? $this->auth->userId() : null;
        $r      = (new ReportService())->create($project, $ctx, $body, $portal, 'public');

        return $this->ok(['reference' => $r['reference'], 'status_url' => $r['status_url'], 'status_token' => $r['status_token']], $r['created'] ? 201 : 200);
    }

    /**
     * Captcha placeholder: verifies a Cloudflare Turnstile / hCaptcha token when
     * `bugtrace.captchaSecretRef` is configured; otherwise (dev) any non-empty
     * token outside production.
     */
    public static function verifyCaptcha(string $token, string $ip): bool
    {
        $secret = SecretStore::get(env('bugtrace.captchaSecretRef', 'BUGTRACE_CAPTCHA_SECRET'));
        if ($secret === null) {
            return ! config('UpdateCenter')->isProduction() || $token !== '';
        }
        if ($token === '') {
            return false;
        }
        $url = env('bugtrace.captchaVerifyUrl', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $ip]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $res = curl_exec($ch);
        curl_close($ch);
        $j = is_string($res) ? json_decode($res, true) : null;

        return (bool) ($j['success'] ?? false);
    }

    /** GET /api/v1/bugtrace/reports/{ref}/status (X-BT-Status-Token or ?token=) */
    public function reportStatus(string $ref): array
    {
        $token = trim($this->request->getHeaderLine('X-BT-Status-Token')) ?: (string) $this->request->getGet('token');

        return (new ReportService())->status($ref, $token);
    }

    /** GET /api/v1/bugtrace/policy — effective policy (clients cache 24 h). */
    public function policy(): array
    {
        $project = null;
        if ($this->key() !== null) {
            $project = $this->ingest->project($this->key());
        }
        $ctx = $this->context($this->tpt());
        if ($ctx['tpt_error'] !== null && $this->tpt() !== null) {
            // Invalid TPT: answer with defaults (like the update check) but say why.
            return self::clientPolicy($ctx, $project) + ['warning' => 'TENANT_POLICY_INVALID'];
        }

        return self::clientPolicy($ctx, $project);
    }

    /** @return array<string, mixed> */
    public static function clientPolicy(array $ctx, ?array $project): array
    {
        $p = $ctx['policy'];

        return [
            'policy'               => $p['policy'],
            'user_reports'         => $p['user_reports'],
            'screenshots'          => $p['screenshots'],
            'logs'                 => $p['logs'],
            'server_reporting'     => $p['server_reporting'],
            'user_opt_out_allowed' => $p['user_opt_out_allowed'],
            'source'               => $p['source'],
            'sample_rates'         => $project ? Bt::decode($project['sample_rates'], ['crash' => 1.0, 'error' => 0.25]) : ['crash' => 1.0, 'error' => 0.25],
            'ttl'                  => PolicyService::CLIENT_TTL,
            'fetched_at'           => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }
}

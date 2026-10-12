<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\Base64Url;
use App\Libraries\CanonicalJson;
use App\Libraries\Semver;
use App\Libraries\Signing\Ed25519;
use App\Libraries\Trust\TokenException;
use App\Libraries\Ulid;

/**
 * Portal side of the Core System Update Service (WS-CORE-SYSUPDATE-SRS-001 §6, §10, §11):
 *
 *  - POST /server/installations/register — licence token in, installation_id + installation key out
 *  - GET  /server/auto-update/plan       — signed plan (2-hour expiry): release, ring open time, latest time
 *  - PUT  /server/auto-update/policy     — policy mirror, If-Match required, 412 on a stale version, audited
 *
 * The installation key is returned once and only its SHA-256 is stored. Every response
 * is signed with the update-response key (Ed25519 over canonical JSON without `signature`).
 */
class ServerAutoUpdateService
{
    public const MODES = ['OFF', 'NOTIFY', 'AUTO_SECURITY', 'AUTO_MINOR', 'AUTO_ALL'];

    public const PLAN_STATES = ['PLANNED', 'POSTPONED', 'SKIPPED', 'HELD', 'DONE', 'CANCELLED'];

    /** Plan validity (FR-SU-PLAN-01). */
    public const PLAN_TTL = 7200;

    public const KEY_PREFIX = 'wsik_';

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** Policy fields the portal can lock (FR-SU-POL-03). */
    public const LOCKABLE = ['mode', 'windows', 'blackouts', 'ring', 'backup_before', 'max_active_users', 'allow_one_way_migrations', 'web_idle_minutes', 'web_forced_hours', 'web_toast_non_admin'];

    // ---- Signing ------------------------------------------------------------

    /** Sign a server API response (update-response key, 15-minute validity unless $ttl says otherwise). */
    public static function sign(array $doc, ?int $ttl = null): array
    {
        $cfg               = config('UpdateCenter');
        $now               = time();
        $doc['issued_at']  = gmdate('Y-m-d\TH:i:s\Z', $now);
        $doc['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', $now + ($ttl ?? $cfg->updateResponseTtl));

        return Ed25519::signDocument($doc, service('responseKeys'), $cfg->responseKeyId);
    }

    // ---- Registration and installation keys -------------------------------------

    /**
     * FR-SU-LINK-01: verify the licence token, create or update the installation and issue
     * a new installation key (any previous key stops working).
     *
     * @return array{0: array<string, mixed>, 1: bool} signed response, created
     */
    public function register(?string $authHeader, array $in): array
    {
        $token = trim((string) ($in['license_token'] ?? ''));
        if ($token === '' && $authHeader !== null && str_starts_with($authHeader, 'License ')) {
            $token = substr($authHeader, 8);
        }
        if ($token === '') {
            throw ApiException::validation(['license_token' => 'The installation licence token is required']);
        }
        try {
            $lic = service('trustChain')->verifyLicenseToken($token);
        } catch (TokenException $e) {
            throw new ApiException('LICENSE_TOKEN_INVALID', 'Installation licence invalid: ' . $e->getMessage(), 401);
        }
        $edition = strtoupper((string) $lic['edition']);
        $channel = strtoupper(trim((string) ($in['channel'] ?? 'STABLE')));
        if (! in_array($channel, ['STABLE', 'BETA', 'LTS'], true)) {
            throw ApiException::validation(['channel' => 'stable, beta or lts']);
        }
        if ($channel === 'LTS' && $edition !== 'ENTERPRISE') {
            throw ApiException::validation(['channel' => 'The lts channel is for Enterprise installations only']);
        }
        $mode = strtoupper((string) ($in['mode'] ?? 'SELF_HOSTED'));
        if (! in_array($mode, ['MANAGED', 'SELF_HOSTED', 'AIR_GAPPED'], true)) {
            throw ApiException::validation(['mode' => 'MANAGED, SELF_HOSTED or AIR_GAPPED']);
        }
        $contacts = [];
        foreach (array_slice((array) ($in['admin_contacts'] ?? []), 0, 20) as $c) {
            if (filter_var($c, FILTER_VALIDATE_EMAIL)) {
                $contacts[] = strtolower((string) $c);
            }
        }
        $contacts = array_values(array_unique($contacts));

        $db  = db_connect();
        $now = Catalog::now();
        $key = self::KEY_PREFIX . Base64Url::encode(random_bytes(32));
        $set = [
            'edition' => $edition, 'channel' => $channel, 'mode' => $mode, 'key_hash' => hash('sha256', $key), 'key_issued_at' => $now,
            'admin_contacts' => json_encode($contacts), 'offline_mode' => ! empty($in['offline_mode']) ? 1 : 0, 'updated_at' => $now,
        ];
        foreach (['name' => 191, 'version' => 64] as $k => $len) {
            if (isset($in[$k]) && is_string($in[$k]) && trim($in[$k]) !== '') {
                $set[$k] = substr(trim($in[$k]), 0, $len);
            }
        }
        if (isset($set['version']) && ! Semver::isValid($set['version'])) {
            throw ApiException::validation(['version' => 'Installed version must be SemVer']);
        }
        if (isset($in['build'])) {
            $set['build'] = (int) $in['build'];
        }
        $row     = $db->table('server_installations')->where('installation_id', $lic['installation_id'])->get()->getRowArray();
        $created = $row === null;
        if ($created) {
            $db->table('server_installations')->insert($set + ['installation_id' => $lic['installation_id'], 'build' => $set['build'] ?? 0, 'registered_at' => $now, 'created_at' => $now]);
        } else {
            $db->table('server_installations')->where('id', $row['id'])->update($set + ($row['registered_at'] ? [] : ['registered_at' => $now]));
        }
        $row    = $db->table('server_installations')->where('installation_id', $lic['installation_id'])->get()->getRowArray();
        $policy = $this->policyRow((int) $row['id']);
        service('audit')->record($created ? 'server.installation_registered' : 'server.installation_relinked', 'server_installation', $row['installation_id'], null,
            ['edition' => $edition, 'channel' => $channel, 'mode' => $mode, 'key_fingerprint' => self::fingerprint($key)], null, self::actor($row));

        return [self::sign([
            'type'             => 'installation_registration',
            'installation_id'  => $row['installation_id'],
            'installation_key' => $key,
            'key_fingerprint'  => self::fingerprint($key),
            'edition'          => strtolower($edition),
            'channel'          => strtolower($channel),
            'mode'             => $mode,
            'ring'             => ServerRingService::ringOf($row, $policy['ring'] ?? null),
            'ring_name'        => ServerRingService::RINGS[ServerRingService::ringOf($row, $policy['ring'] ?? null)],
            'policy_version'   => $policy ? (int) $policy['version'] : 0,
            'portal_url'       => rtrim(config('UpdateCenter')->publicBaseUrl, '/'),
            'registered_at'    => Catalog::iso($row['registered_at']),
        ]), $created];
    }

    public static function fingerprint(string $key): string
    {
        return 'sha256:' . substr(hash('sha256', $key), 0, 16);
    }

    public static function actor(array $inst): array
    {
        return ['type' => 'INSTALLATION', 'id' => $inst['installation_id'], 'label' => $inst['name'] ?: $inst['installation_id']];
    }

    /** `Authorization: Installation <key>` → installation row (+ `license` shape used by the update API). */
    public function installationFromKey(string $header): array
    {
        $key = trim(substr($header, strlen('Installation ')));
        if (! str_starts_with($key, self::KEY_PREFIX) || strlen($key) < 40) {
            throw new ApiException('INSTALLATION_KEY_INVALID', 'Installation key is not valid', 401);
        }
        $row = db_connect()->table('server_installations')->where('key_hash', hash('sha256', $key))->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('INSTALLATION_KEY_INVALID', 'Installation key is not valid or was replaced — register the installation again', 401);
        }

        return $row + ['license' => ['installation_id' => $row['installation_id'], 'edition' => strtoupper((string) $row['edition'])]];
    }

    // ---- Policy mirror (FR-SU-POL-01..03) ------------------------------------------

    public function policyRow(int $installationPk): ?array
    {
        return db_connect()->table('server_auto_update_policies')->where('installation_id', $installationPk)->get()->getRowArray();
    }

    /** Edition default mode (§6): Professional NOTIFY, Business self-hosted AUTO_SECURITY, Enterprise NOTIFY, hosted AUTO_MINOR. */
    public static function defaultMode(array $inst): string
    {
        if (! empty($inst['offline_mode']) || ($inst['mode'] ?? '') === 'AIR_GAPPED') {
            return 'NOTIFY';
        }
        if (($inst['mode'] ?? '') === 'MANAGED') {
            return 'AUTO_MINOR';
        }

        return strtoupper((string) $inst['edition']) === 'BUSINESS' ? 'AUTO_SECURITY' : 'NOTIFY';
    }

    public function effectiveMode(array $inst, ?array $policy): string
    {
        return strtoupper((string) ($policy['mode'] ?? $inst['policy_mode'] ?? self::defaultMode($inst)));
    }

    public function presentPolicy(array $p): array
    {
        $locks = json_decode((string) $p['locks'], true) ?: [];

        return [
            'version'                  => (int) $p['version'],
            'mode'                     => $p['mode'],
            'windows'                  => json_decode((string) $p['windows'], true) ?: [],
            'blackouts'                => json_decode((string) $p['blackouts'], true) ?: [],
            'timezone'                 => $p['timezone'],
            'ring'                     => $p['ring'],
            'backup_before'            => (bool) $p['backup_before'],
            'auto_rollback'            => (bool) $p['auto_rollback'],
            'max_active_users'         => (int) $p['max_active_users'],
            'allow_one_way_migrations' => (bool) $p['allow_one_way_migrations'],
            'web_idle_minutes'         => (int) $p['web_idle_minutes'],
            'web_forced_hours'         => (int) $p['web_forced_hours'],
            'web_toast_non_admin'      => (bool) $p['web_toast_non_admin'],
            'org_owner'                => $p['org_owner'],
            'updated_by'               => $p['updated_by'],
            'locked_fields'            => array_map(static fn ($f, $l) => ['field' => $f, 'value' => $l['value'] ?? null, 'by' => $l['by'] ?? null, 'at' => $l['at'] ?? null], array_keys($locks), array_values($locks)),
            'synced_at'                => Catalog::iso($p['synced_at']),
        ];
    }

    public function getPolicy(array $inst): array
    {
        $p = $this->policyRow((int) $inst['id']);

        return self::sign([
            'type'            => 'auto_update_policy',
            'installation_id' => $inst['installation_id'],
            'version'         => $p ? (int) $p['version'] : 0,
            'policy'          => $p ? $this->presentPolicy($p) : null,
            'default_mode'    => self::defaultMode($inst),
        ]);
    }

    /** Parse `If-Match: "3"`, `W/"3"` or `3`. */
    public static function parseIfMatch(string $header): ?int
    {
        $h = trim($header);
        if ($h === '') {
            return null;
        }
        if (preg_match('/^(?:W\/)?"?(\d+)"?$/', $h, $m)) {
            return (int) $m[1];
        }

        throw ApiException::validation(['If-Match' => 'If-Match must be the policy version, e.g. "3"']);
    }

    /**
     * PUT /server/auto-update/policy. $ifMatch is the version the tenant last synced (0 = none yet).
     *
     * @return array<string, mixed> signed response
     */
    public function putPolicy(array $inst, ?int $ifMatch, array $in): array
    {
        if ($ifMatch === null) {
            throw new ApiException('PRECONDITION_REQUIRED', 'If-Match with the policy version is required', 428);
        }
        $db      = db_connect();
        $current = $this->policyRow((int) $inst['id']);
        $curVer  = $current ? (int) $current['version'] : 0;
        if ($ifMatch !== $curVer) {
            throw new ApiException('POLICY_VERSION_CONFLICT', "The policy changed since version {$ifMatch}; the portal has version {$curVer}", 412, [
                'current_version' => $curVer,
                'policy'          => $current ? $this->presentPolicy($current) : null,
            ]);
        }
        $clean = $this->validatePolicy($inst, $in);
        $locks = $current ? (json_decode((string) $current['locks'], true) ?: []) : [];
        $overridden = [];
        foreach ($locks as $field => $lock) {
            if (array_key_exists($field, $clean) && in_array($field, self::LOCKABLE, true)) {
                $locked = in_array($field, ['windows', 'blackouts'], true) ? json_encode($lock['value'] ?? []) : $lock['value'];
                if ($clean[$field] != $locked) {
                    $overridden[] = $field;
                }
                $clean[$field] = $locked;
            }
        }
        $now = Catalog::now();
        $row = $clean + [
            'version' => $curVer + 1, 'org_owner' => isset($in['org_owner']) ? substr((string) $in['org_owner'], 0, 191) : ($current['org_owner'] ?? null),
            'updated_by' => isset($in['updated_by']) ? substr((string) $in['updated_by'], 0, 191) : null, 'synced_at' => $now, 'updated_at' => $now,
        ];
        if ($current) {
            // Optimistic concurrency at the database too: only the version we read can be replaced.
            $db->table('server_auto_update_policies')->where('id', $current['id'])->where('version', $curVer)->update($row);
            if ($db->affectedRows() !== 1) {
                $fresh = $this->policyRow((int) $inst['id']);

                throw new ApiException('POLICY_VERSION_CONFLICT', 'The policy changed while saving', 412, ['current_version' => (int) $fresh['version'], 'policy' => $this->presentPolicy($fresh)]);
            }
        } else {
            $db->table('server_auto_update_policies')->insert($row + ['installation_id' => $inst['id'], 'locks' => json_encode([]), 'created_at' => $now]);
        }
        $saved = $this->policyRow((int) $inst['id']);
        $db->table('server_installations')->where('id', $inst['id'])->update(['policy_mode' => $saved['mode'], 'updated_at' => $now]);
        service('audit')->record('server.policy_synced', 'server_installation', $inst['installation_id'], $current ? $this->auditShape($current) : null, $this->auditShape($saved),
            $overridden ? 'Locked fields kept: ' . implode(', ', $overridden) : null, self::actor($inst));

        return self::sign([
            'type'            => 'auto_update_policy',
            'installation_id' => $inst['installation_id'],
            'version'         => (int) $saved['version'],
            'policy'          => $this->presentPolicy($saved),
            'overridden'      => $overridden,
        ]);
    }

    private function auditShape(array $p): array
    {
        $out = $this->presentPolicy($p);
        unset($out['synced_at'], $out['locked_fields']);

        return $out;
    }

    /** @return array<string, mixed> DB columns */
    private function validatePolicy(array $inst, array $in): array
    {
        $e    = [];
        $mode = strtoupper((string) ($in['mode'] ?? ''));
        if (! in_array($mode, self::MODES, true)) {
            $e['mode'] = 'OFF, NOTIFY, AUTO_SECURITY, AUTO_MINOR or AUTO_ALL';
        } elseif (($inst['mode'] ?? '') === 'MANAGED' && $mode === 'OFF') {
            $e['mode'] = 'OFF is not allowed for Livezen-hosted installations';
        } elseif ((! empty($inst['offline_mode']) || ($inst['mode'] ?? '') === 'AIR_GAPPED') && ! in_array($mode, ['OFF', 'NOTIFY'], true)) {
            $e['mode'] = 'Offline installations can only use OFF or NOTIFY';
        }
        $windows = [];
        foreach ((array) ($in['windows'] ?? []) as $i => $w) {
            $days = array_values(array_unique(array_map(static fn ($d) => strtolower(substr((string) $d, 0, 3)), (array) ($w['days'] ?? []))));
            $len  = (int) ($w['length_minutes'] ?? 0);
            if ($days === [] || array_diff($days, self::DAYS) !== [] || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($w['start'] ?? '')) || $len < 15 || $len > 720) {
                $e["windows.{$i}"] = 'Each window needs days (mon–sun), start HH:MM and length_minutes 15–720';

                continue;
            }
            $windows[] = ['days' => $days, 'start' => (string) $w['start'], 'length_minutes' => $len];
        }
        if (count($windows) > 14) {
            $e['windows'] = 'At most 14 maintenance windows';
        }
        $blackouts = [];
        foreach ((array) ($in['blackouts'] ?? []) as $i => $b) {
            $from = (string) ($b['from'] ?? '');
            $to   = (string) ($b['to'] ?? '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from || trim((string) ($b['name'] ?? '')) === '') {
                $e["blackouts.{$i}"] = 'Each blackout needs a name and from/to dates (YYYY-MM-DD, from ≤ to)';

                continue;
            }
            $blackouts[] = ['name' => substr(trim((string) $b['name']), 0, 100), 'from' => $from, 'to' => $to];
        }
        $tz = (string) ($in['timezone'] ?? 'UTC');
        if (! in_array($tz, \DateTimeZone::listIdentifiers(), true) && $tz !== 'UTC') {
            $e['timezone'] = 'Use an IANA time zone, e.g. Asia/Colombo';
        }
        $ring = strtoupper((string) ($in['ring'] ?? 'BROAD'));
        if (! isset(ServerRingService::POLICY_RING[$ring])) {
            $e['ring'] = 'EARLY, BROAD or CONSERVATIVE';
        }
        if (array_key_exists('auto_rollback', $in) && ! $in['auto_rollback']) {
            $e['auto_rollback'] = 'Auto-rollback on failed health cannot be turned off';
        }
        $range = static function (string $k, int $lo, int $hi, int $def) use ($in, &$e): int {
            $v = $in[$k] ?? $def;
            if (! is_numeric($v) || (int) $v < $lo || (int) $v > $hi) {
                $e[$k] = "Must be between {$lo} and {$hi}";
            }

            return (int) $v;
        };
        $maxUsers = $range('max_active_users', 0, 100000, 25);
        $idle     = $range('web_idle_minutes', 5, 60, 10);
        $forced   = $range('web_forced_hours', 4, 72, 24);
        if ($e !== []) {
            throw ApiException::validation($e);
        }

        return [
            'mode' => $mode, 'windows' => json_encode($windows), 'blackouts' => json_encode($blackouts), 'timezone' => $tz, 'ring' => $ring,
            'backup_before' => array_key_exists('backup_before', $in) ? ($in['backup_before'] ? 1 : 0) : 1, 'auto_rollback' => 1,
            'max_active_users' => $maxUsers, 'allow_one_way_migrations' => ! empty($in['allow_one_way_migrations']) ? 1 : 0,
            'web_idle_minutes' => $idle, 'web_forced_hours' => $forced, 'web_toast_non_admin' => array_key_exists('web_toast_non_admin', $in) ? ($in['web_toast_non_admin'] ? 1 : 0) : 1,
        ];
    }

    /**
     * Portal policy override (FR-SU-POL-03, FR-DL-AUD-01): lock fields and/or pin the ring.
     *
     * @param array{locks?: array<string, mixed>, ring?: ?int, reason: string} $in
     */
    public function override(array $inst, array $in, string $by): array
    {
        $db     = db_connect();
        $before = ['ring' => $inst['ring'] === null ? null : (int) $inst['ring']];
        $after  = [];
        if (array_key_exists('ring', $in)) {
            $ring = $in['ring'] === null || $in['ring'] === '' ? null : (int) $in['ring'];
            if ($ring !== null && ! isset(ServerRingService::RINGS[$ring])) {
                throw ApiException::validation(['ring' => 'Ring 0, 1, 2 or 3 (or null for the policy ring)']);
            }
            $db->table('server_installations')->where('id', $inst['id'])->update(['ring' => $ring, 'updated_at' => Catalog::now()]);
            $after['ring'] = $ring;
        }
        if (array_key_exists('locks', $in)) {
            $locks = [];
            foreach ((array) $in['locks'] as $field => $value) {
                if (! in_array($field, self::LOCKABLE, true)) {
                    throw ApiException::validation(['locks' => 'Lockable fields: ' . implode(', ', self::LOCKABLE)]);
                }
                if ($field === 'mode' && ! in_array(strtoupper((string) $value), self::MODES, true)) {
                    throw ApiException::validation(['locks.mode' => 'Invalid mode']);
                }
                $locks[$field] = ['value' => $field === 'mode' ? strtoupper((string) $value) : $value, 'by' => $by, 'at' => gmdate('Y-m-d\TH:i:s\Z')];
            }
            $policy = $this->policyRow((int) $inst['id']);
            $before['locks'] = $policy ? (json_decode((string) $policy['locks'], true) ?: []) : [];
            $now    = Catalog::now();
            if ($policy) {
                // A lock change is a new policy version, so the tenant's next sync sees it (If-Match).
                $set = ['locks' => json_encode($locks), 'version' => (int) $policy['version'] + 1, 'updated_at' => $now];
                foreach ($locks as $f => $l) {
                    $set[$f] = in_array($f, ['windows', 'blackouts'], true) ? json_encode($l['value']) : (is_bool($l['value']) ? (int) $l['value'] : $l['value']);
                }
                $db->table('server_auto_update_policies')->where('id', $policy['id'])->update($set);
            } else {
                $db->table('server_auto_update_policies')->insert(['installation_id' => $inst['id'], 'version' => 1, 'mode' => $locks['mode']['value'] ?? self::defaultMode($inst), 'windows' => '[]', 'blackouts' => '[]', 'locks' => json_encode($locks), 'created_at' => $now, 'updated_at' => $now]);
            }
            $after['locks'] = $locks;
        }
        service('audit')->record('server.policy_override', 'server_installation', $inst['installation_id'], $before, $after, (string) ($in['reason'] ?? ''));

        return $after;
    }

    // ---- Plans (FR-SU-PLAN-01, FR-DL-WD-01) ------------------------------------------------

    public static function bump(string $from, string $to): string
    {
        $a = Semver::parse(Semver::normalize($from));
        $b = Semver::parse(Semver::normalize($to));

        return $a['major'] !== $b['major'] ? 'major' : ($a['minor'] !== $b['minor'] ? 'minor' : 'patch');
    }

    /** Whether the policy mode lets this release install itself (§6 table). */
    public static function autoAllowed(string $mode, string $severity, string $from, string $to): bool
    {
        $bump = self::bump($from, $to);

        return match ($mode) {
            'AUTO_ALL'      => true,
            'AUTO_MINOR'    => $bump !== 'major',
            'AUTO_SECURITY' => in_array($severity, ServerRingService::SECURITY_SEVERITIES, true) && $bump === 'patch',
            default         => false,
        };
    }

    /** Next release this installation should install (first hop of the upgrade path) and the full path. */
    public function target(array $inst): array
    {
        $svc     = new ServerUpdateService();
        $app     = $svc->serverApp();
        $channel = Catalog::channel((string) ($inst['channel'] ?: 'STABLE'), (int) $app['id']);
        $version = Semver::normalize((string) ($inst['version'] ?: '0.0.0'));
        $edition = strtoupper((string) $inst['edition']);
        $db      = db_connect();
        $rows    = $db->table('releases')->where('application_id', $app['id'])->where('channel_id', $channel['id'])->where('status', 'PUBLISHED')
            ->where('support_state !=', 'BLOCKED')->orderBy('version_sort', 'DESC')->get()->getResultArray();
        $resolver = new UpdateResolverService();
        foreach ($rows as $r) {
            if (Semver::compare($r['version'], $version) <= 0) {
                break;
            }
            if (! $resolver->packageFor($r, 'server', 'any', 'zip', $edition) && ! $resolver->packageFor($r, 'server', 'any', 'zip', 'ALL')) {
                continue;
            }
            $chain = $svc->upgradeChain($app, $channel, $version, $r);

            return ['release' => $chain[0] ?? $r, 'target' => $r, 'path' => $chain === [] ? [$r] : $chain, 'channel' => $channel, 'app' => $app];
        }

        return ['release' => null, 'target' => null, 'path' => [], 'channel' => $channel, 'app' => $app];
    }

    /**
     * GET /server/auto-update/plan — computes, stores and signs the plan for one installation.
     */
    public function plan(array $inst): array
    {
        $db       = db_connect();
        $rings    = new ServerRingService();
        $policy   = $this->policyRow((int) $inst['id']);
        $ring     = ServerRingService::ringOf($inst, $policy['ring'] ?? null);
        $mode     = $this->effectiveMode($inst, $policy);
        $t        = $this->target($inst);
        $release  = $t['release'];
        $holds    = [];
        $doc      = [
            'type'            => 'auto_update_plan',
            'schema'          => 1,
            'installation_id' => $inst['installation_id'],
            'plan_id'         => null,
            'state'           => 'NONE',
            'release'         => null,
            'path'            => [],
            'ring'            => $ring,
            'ring_name'       => ServerRingService::RINGS[$ring],
            'ring_open'       => false,
            'ring_open_at'    => null,
            'earliest_at'     => null,
            'latest_at'       => null,
            'auto_install'    => false,
            'policy_mode'     => $mode,
            'policy_version'  => $policy ? (int) $policy['version'] : 0,
            'holds'           => [],
            'cancelled'       => $this->cancelledFor($inst),
        ];
        $min = (new MinimumVersionService())->channelMinimum((int) $t['app']['id'], (int) $t['channel']['id'], null);
        $doc['minimum_supported_version'] = $min['minimum_version'] ?? null;
        try {
            $doc['below_minimum'] = $min !== null && $inst['version'] && Semver::lt((string) $inst['version'], (string) $min['minimum_version']);
        } catch (\Throwable) {
            $doc['below_minimum'] = false;
        }

        if ($release !== null) {
            $meta     = ServerRingService::meta($release);
            $severity = ServerRingService::severity($release, $meta);
            $state    = $rings->state($release);
            $openTs   = $rings->openAt($release, $ring, $state);
            $isOpen   = $rings->isOpen($release, $ring, $state);
            $campaign = $db->table('notification_campaigns')->where('release_id', $release['id'])->orderBy('id', 'DESC')->get()->getRowArray();
            $deadline = $campaign['deadline'] ?? $release['mandatory_after'] ?? null;
            $base     = $openTs ?? time();
            $latest   = $deadline !== null ? strtotime($deadline . ' UTC') : $base + (in_array($severity, ServerRingService::SECURITY_SEVERITIES, true) ? 7 : 14) * 86400;
            $planState = 'PLANNED';
            if ($state['pause'] !== null && ($state['pause']['kind'] === 'MANUAL' || ! $isOpen)) {
                $planState = 'HELD';
                $holds[]   = ['code' => $state['pause']['kind'] === 'MANUAL' ? 'ROLLOUT_PAUSED' : 'HEALTH_GATE', 'message' => (string) ($state['pause']['reason'] ?? 'Rollout paused')];
            }
            $existing = $db->table('server_update_plans')->where('installation_id', $inst['id'])->where('release_id', $release['id'])->get()->getRowArray();
            if ($existing && in_array($existing['state'], ['POSTPONED', 'SKIPPED', 'DONE'], true)) {
                $planState = $existing['state'];
            } elseif ($existing && $existing['state'] === 'HELD' && $existing['state_reason'] !== null && str_starts_with((string) $existing['state_reason'], 'Rolled back')) {
                $planState = 'HELD';
                $holds[]   = ['code' => 'ROLLED_BACK_HERE', 'message' => (string) $existing['state_reason']];
            }
            $auto = $planState === 'PLANNED' && self::autoAllowed($mode, $severity, (string) ($inst['version'] ?: '0.0.0'), $release['version']);
            $publicId = $existing['public_id'] ?? Ulid::prefixed('pln');
            $pkg      = (new UpdateResolverService())->packageFor($release, 'server', 'any', 'zip', strtoupper((string) $inst['edition'])) ?? (new UpdateResolverService())->packageFor($release, 'server', 'any', 'zip', 'ALL');
            $doc = array_merge($doc, [
                'plan_id'      => $publicId,
                'state'        => $planState,
                'release'      => [
                    'release_id' => $release['public_id'], 'version' => $release['version'], 'build' => (int) $release['build_number'],
                    'channel' => strtolower($t['channel']['code']), 'severity' => $severity, 'release_type' => $release['release_type'],
                    'edition' => strtolower((string) ($pkg['edition'] ?? $inst['edition'])), 'package_sha256' => $pkg['sha256'] ?? null, 'package_size' => isset($pkg['size_bytes']) ? (int) $pkg['size_bytes'] : null,
                    'min_from_version' => $meta['min_from_version'] ?? null, 'migrations_reversible' => isset($meta['migrations_reversible']) ? (bool) $meta['migrations_reversible'] : null,
                    'release_manifest_url' => '/api/v1/releases/' . $release['public_id'] . '/manifest',
                ],
                'path'         => array_map(static fn ($r) => ['release_id' => $r['public_id'], 'version' => $r['version'], 'build' => (int) $r['build_number']], $t['path']),
                'ring_open'    => $isOpen,
                'ring_open_at' => $openTs === null ? null : gmdate('Y-m-d\TH:i:s\Z', $openTs),
                'earliest_at'  => $openTs === null ? null : gmdate('Y-m-d\TH:i:s\Z', max($openTs, time())),
                'latest_at'    => gmdate('Y-m-d\TH:i:s\Z', max($latest, $openTs ?? $latest)),
                'auto_install' => $auto,
                'holds'        => $holds,
            ]);
            $signed = self::sign($doc, self::PLAN_TTL);
            $json   = CanonicalJson::encode($signed);
            $rowSet = [
                'ring' => $ring, 'earliest_at' => Catalog::toDb($doc['earliest_at']), 'latest_at' => Catalog::toDb($doc['latest_at']), 'auto_install' => $auto ? 1 : 0,
                'state' => $planState, 'plan_json' => $json, 'plan_sha256' => hash('sha256', $json), 'issued_at' => Catalog::toDb($signed['issued_at']), 'expires_at' => Catalog::toDb($signed['expires_at']), 'updated_at' => Catalog::now(),
            ];
            if ($existing) {
                $reason = match ($planState) {
                    'HELD'                         => $holds[0]['message'] ?? $existing['state_reason'],
                    'POSTPONED', 'SKIPPED', 'DONE' => $existing['state_reason'],
                    default                        => null,
                };
                $db->table('server_update_plans')->where('id', $existing['id'])->update($rowSet + ['state_reason' => $reason]);
            } else {
                $db->table('server_update_plans')->insert($rowSet + ['public_id' => $publicId, 'installation_id' => $inst['id'], 'release_id' => $release['id'], 'state_reason' => $holds[0]['message'] ?? null, 'created_at' => Catalog::now()]);
            }

            return $signed;
        }

        return self::sign($doc, self::PLAN_TTL);
    }

    /** Plans of this installation cancelled in the last 30 days (withdrawn releases), newest first. */
    private function cancelledFor(array $inst): array
    {
        $rows = db_connect()->table('server_update_plans p')->select('p.*, r.public_id AS release_public_id, r.version, r.withdrawn_at')->join('releases r', 'r.id = p.release_id')
            ->where('p.installation_id', $inst['id'])->where('p.state', 'CANCELLED')->where('p.cancelled_at >=', gmdate('Y-m-d H:i:s', time() - 30 * 86400))->orderBy('p.cancelled_at', 'DESC')->get()->getResultArray();

        return array_map(static fn ($p) => [
            'plan_id' => $p['public_id'], 'release_id' => $p['release_public_id'], 'version' => $p['version'], 'reason' => $p['state_reason'],
            'cancelled_at' => Catalog::iso($p['cancelled_at']), 'withdrawn_at' => Catalog::iso($p['withdrawn_at']), 'rollback_requested' => (bool) $p['rollback_requested'],
        ], $rows);
    }

    /**
     * FR-DL-WD-01 / WF-SU-08 / AC-SU-14: withdrawing cancels every open plan for the release,
     * so the next plan or update check (signed, ≤ 15 min validity) shows it withdrawn.
     */
    public function onWithdrawn(array $release, string $reason, bool $rollback): int
    {
        $db  = db_connect();
        $now = Catalog::now();
        $db->table('server_update_plans')->where('release_id', $release['id'])->whereIn('state', ['PLANNED', 'POSTPONED', 'HELD'])
            ->update(['state' => 'CANCELLED', 'state_reason' => 'Release withdrawn: ' . $reason, 'cancelled_at' => $now, 'updated_at' => $now]);
        $n = $db->affectedRows();
        if ($rollback) {
            // Installations already on the release keep a cancelled marker that asks them to roll back.
            foreach ($db->table('server_installations')->where('version', $release['version'])->get()->getResultArray() as $inst) {
                $p = $db->table('server_update_plans')->where('installation_id', $inst['id'])->where('release_id', $release['id'])->get()->getRowArray();
                if ($p) {
                    $db->table('server_update_plans')->where('id', $p['id'])->update(['state' => 'CANCELLED', 'rollback_requested' => 1, 'state_reason' => 'Release withdrawn: ' . $reason, 'cancelled_at' => $now, 'updated_at' => $now]);
                } else {
                    $db->table('server_update_plans')->insert(['public_id' => Ulid::prefixed('pln'), 'installation_id' => $inst['id'], 'release_id' => $release['id'], 'ring' => 2, 'state' => 'CANCELLED', 'rollback_requested' => 1, 'state_reason' => 'Release withdrawn: ' . $reason, 'cancelled_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
        $db->table('release_rollouts')->where('release_id', $release['id'])->where('mode', 'RINGS')->update(['status' => 'DONE', 'completed_at' => $now]);
        service('audit')->record('server.plans_cancelled', 'release', $release['public_id'], null, ['cancelled' => $n, 'rollback_requested' => $rollback], $reason);

        return $n;
    }

    /** Ring paused: hold plans that have not run yet (all rings when $ring is null, else that ring and later). */
    public function holdPlans(array $release, ?int $ring, string $reason): void
    {
        $q = db_connect()->table('server_update_plans')->where('release_id', $release['id'])->where('state', 'PLANNED');
        if ($ring !== null) {
            $q->where('ring >=', $ring);
        }
        $q->update(['state' => 'HELD', 'state_reason' => substr($reason, 0, 255), 'updated_at' => Catalog::now()]);
    }

    public function releaseHeldPlans(array $release): void
    {
        db_connect()->table('server_update_plans')->where('release_id', $release['id'])->where('state', 'HELD')->groupStart()->where('state_reason IS NULL')->orNotLike('state_reason', 'Rolled back', 'after')->groupEnd()
            ->update(['state' => 'PLANNED', 'state_reason' => null, 'updated_at' => Catalog::now()]);
    }

    /** Run events update the installation's plan for that release (update-events). */
    public function onEvent(array $inst, ?array $release, string $type, ?string $detail = null): void
    {
        if ($release === null) {
            return;
        }
        $state = match ($type) {
            'completed'   => 'DONE',
            'rolled_back' => 'HELD',
            'postponed'   => 'POSTPONED',
            'skipped'     => 'SKIPPED',
            'scheduled'   => 'PLANNED',
            default       => null,
        };
        if ($state === null) {
            return;
        }
        $db  = db_connect();
        $row = $db->table('server_update_plans')->where('installation_id', $inst['id'])->where('release_id', $release['id'])->get()->getRowArray();
        $set = ['state' => $state, 'state_reason' => $type === 'rolled_back' ? 'Rolled back on this installation' . ($detail ? " ({$detail})" : '') : ($detail ?: null), 'updated_at' => Catalog::now()];
        if ($row) {
            if ($row['state'] === 'CANCELLED') {
                return;
            }
            $db->table('server_update_plans')->where('id', $row['id'])->update($set);
        } else {
            $policy = $this->policyRow((int) $inst['id']);
            $db->table('server_update_plans')->insert($set + ['public_id' => Ulid::prefixed('pln'), 'installation_id' => $inst['id'], 'release_id' => $release['id'], 'ring' => ServerRingService::ringOf($inst, $policy['ring'] ?? null), 'created_at' => Catalog::now()]);
        }
    }

    /**
     * DL-3 "What installations will do" for a release.
     *
     * @param list<array<string, mixed>> $installs
     */
    public function outcomes(array $release, array $installs): array
    {
        $db       = db_connect();
        $plans    = array_column($db->table('server_update_plans')->select('installation_id, state, auto_install')->where('release_id', $release['id'])->get()->getResultArray(), null, 'installation_id');
        $policies = array_column($db->table('server_auto_update_policies')->select('installation_id, mode')->get()->getResultArray(), 'mode', 'installation_id');
        $severity = ServerRingService::severity($release);
        $channel  = Catalog::channelById((int) $release['channel_id'])['code'];
        $out      = ['updated' => 0, 'auto_scheduled' => 0, 'waiting_for_admin' => 0, 'postponed' => 0, 'offline' => 0, 'held' => 0, 'skipped' => 0];
        foreach ($installs as $i) {
            if (strtoupper((string) $i['channel']) !== $channel) {
                continue;
            }
            try {
                if ($i['version'] && Semver::gte($i['version'], $release['version'])) {
                    $out['updated']++;

                    continue;
                }
            } catch (\Throwable) {
            }
            $plan = $plans[$i['id']] ?? null;
            if (! empty($i['offline_mode']) || $i['mode'] === 'AIR_GAPPED') {
                $out['offline']++;
            } elseif ($plan && $plan['state'] === 'POSTPONED') {
                $out['postponed']++;
            } elseif ($plan && $plan['state'] === 'SKIPPED') {
                $out['skipped']++;
            } elseif ($plan && $plan['state'] === 'HELD') {
                $out['held']++;
            } elseif (($plan && $plan['auto_install']) || (! $plan && self::autoAllowed(strtoupper((string) ($policies[$i['id']] ?? $i['policy_mode'] ?? self::defaultMode($i))), $severity, (string) ($i['version'] ?: '0.0.0'), $release['version']))) {
                $out['auto_scheduled']++;
            } else {
                $out['waiting_for_admin']++;
            }
        }
        return $out;
    }
}

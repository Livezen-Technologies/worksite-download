<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\Base64Url;
use App\Libraries\Ulid;

/**
 * WorkSuite Server licences — the Download Center is the authority.
 *
 * Staff generate a key (shown once; only its SHA-256 and last four characters
 * are kept), with an edition, an expiry, an update-eligibility date and a limit
 * on activated servers, and can renew, suspend, resume or revoke it. A server
 * activates the key (POST /server/licenses/activate), which registers or links
 * the installation and returns its installation key, and revalidates it
 * periodically (POST /server/licenses/validate). Both answers carry a licence
 * entitlement document signed with the update-response key, which the server
 * caches for the grace period when the Download Center cannot be reached.
 */
final class LicenseService
{
    public const EDITIONS = ['FREE', 'BUSINESS', 'ENTERPRISE'];

    public const EDITION_ORDER = ['FREE' => 1, 'BUSINESS' => 2, 'ENTERPRISE' => 3];

    /** Days a server keeps working on its last signed entitlement when it cannot revalidate, or after expiry. */
    public const GRACE_DAYS = 14;

    /** Validity of one signed entitlement document; the server revalidates well before. */
    public const ENTITLEMENT_TTL = 7 * 86400;

    private const KEY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    // ---- Keys --------------------------------------------------------------------

    /** WS-XXXX-XXXX-XXXX-XXXX-XXXX (20 random characters, no 0/O/1/I). */
    public static function generateKey(): string
    {
        $groups = [];
        for ($g = 0; $g < 5; $g++) {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= self::KEY_ALPHABET[random_int(0, strlen(self::KEY_ALPHABET) - 1)];
            }
            $groups[] = $s;
        }

        return 'WS-' . implode('-', $groups);
    }

    /** Case and separator insensitive: "ws-abcd abcd…" and "WS-ABCD-ABCD-…" are the same key. */
    public static function normalizeKey(string $key): string
    {
        $k = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key) ?? '');
        if (str_starts_with($k, 'WS')) {
            $k = substr($k, 2);
        }

        return 'WS-' . implode('-', str_split($k, 4));
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', self::normalizeKey($key));
    }

    public static function maskKey(string $last4): string
    {
        return 'WS-****-****-****-****-' . $last4;
    }

    // ---- Staff -------------------------------------------------------------------

    /** @return array{0: array<string, mixed>, 1: string} [presented licence, the key — shown once] */
    public function create(array $in, ?string $createdBy): array
    {
        $set = $this->validated($in, true);
        $key = self::generateKey();
        $now = Catalog::now();
        $db  = db_connect();
        $db->table('licenses')->insert($set + [
            'public_id' => Ulid::prefixed('lic'), 'key_hash' => self::hashKey($key), 'key_last4' => substr($key, -4), 'key_sealed' => \App\Libraries\SecretStore::seal($key),
            'status' => 'ACTIVE', 'created_by' => $createdBy, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $db->table('licenses')->where('id', $db->insertID())->get()->getRowArray();
        service('audit')->record('license.created', 'license', $row['public_id'], null, $this->auditView($row));

        return [$this->present($row), $key];
    }

    public function update(string $publicId, array $in): array
    {
        $row = $this->find($publicId);
        if ($row['status'] === 'REVOKED') {
            throw new ApiException('LICENSE_REVOKED', 'A revoked licence cannot be changed', 409);
        }
        $set = $this->validated($in, false);
        if ($set === []) {
            return $this->present($row);
        }
        db_connect()->table('licenses')->where('id', $row['id'])->update($set + ['updated_at' => Catalog::now()]);
        $after = $this->find($publicId);
        service('audit')->record('license.updated', 'license', $publicId, $this->auditView($row), $this->auditView($after));

        return $this->present($after);
    }

    /** Renew: a new expiry (and update eligibility) on the same key. */
    public function renew(string $publicId, array $in): array
    {
        if (empty($in['expires_at'])) {
            throw ApiException::validation(['expires_at' => 'The new expiry date is required']);
        }

        return $this->update($publicId, ['expires_at' => $in['expires_at'], 'updates_until' => $in['updates_until'] ?? $in['expires_at']]);
    }

    public function setStatus(string $publicId, string $status, ?string $reason): array
    {
        $row = $this->find($publicId);
        if ($row['status'] === 'REVOKED') {
            throw new ApiException('LICENSE_REVOKED', 'A revoked licence cannot be changed', 409);
        }
        if (in_array($status, ['SUSPENDED', 'REVOKED'], true) && trim((string) $reason) === '') {
            throw ApiException::validation(['reason' => 'A reason is required']);
        }
        db_connect()->table('licenses')->where('id', $row['id'])->update(['status' => $status, 'status_reason' => $reason ?: null, 'updated_at' => Catalog::now()]);
        service('audit')->record('license.' . strtolower($status === 'ACTIVE' ? 'resumed' : $status), 'license', $publicId, ['status' => $row['status']], ['status' => $status], $reason);

        return $this->present($this->find($publicId));
    }

    /** Frees an activation slot; that server must activate again. */
    public function deactivate(string $publicId, int $activationId, ?string $reason): array
    {
        $row = $this->find($publicId);
        $db  = db_connect();
        $act = $db->table('license_activations')->where('id', $activationId)->where('license_id', $row['id'])->get()->getRowArray();
        if ($act === null) {
            throw new ApiException('NOT_FOUND', 'Activation not found', 404);
        }
        $db->table('license_activations')->where('id', $act['id'])->update(['deactivated_at' => Catalog::now(), 'deactivate_reason' => $reason ?: 'Deactivated by staff', 'updated_at' => Catalog::now()]);
        service('audit')->record('license.deactivated', 'license', $publicId, null, ['installation_id' => $act['installation_id']], $reason);

        return $this->present($this->find($publicId));
    }

    public function list(array $q, int $limit, int $offset): array
    {
        $b = db_connect()->table('licenses');
        if (! empty($q['status'])) {
            $b->where('status', strtoupper((string) $q['status']));
        }
        if (! empty($q['edition'])) {
            $b->where('edition', strtoupper((string) $q['edition']));
        }
        if (! empty($q['q'])) {
            $term = trim((string) $q['q']);
            $b->groupStart()->like('organization', $term)->orLike('contact_email', $term)->orLike('public_id', $term)->orWhere('key_last4', strtoupper(substr($term, -4)))->groupEnd();
        }
        $total = $b->countAllResults(false);
        $rows  = $b->orderBy('id', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return ['data' => array_map(fn ($r) => $this->present($r), $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    public function detail(string $publicId): array
    {
        $row  = $this->find($publicId);
        $acts = db_connect()->table('license_activations')->where('license_id', $row['id'])->orderBy('id', 'DESC')->get()->getResultArray();

        return $this->present($row) + ['activations' => array_map(static fn ($a) => [
            'id' => (int) $a['id'], 'installation_id' => $a['installation_id'], 'hostname' => $a['hostname'], 'server_url' => $a['server_url'],
            'fingerprint' => $a['fingerprint'], 'activated_at' => Catalog::iso($a['activated_at']), 'last_validated_at' => Catalog::iso($a['last_validated_at']),
            'deactivated_at' => Catalog::iso($a['deactivated_at']), 'deactivate_reason' => $a['deactivate_reason'], 'active' => $a['deactivated_at'] === null,
        ], $acts)];
    }

    // ---- Servers -----------------------------------------------------------------

    /**
     * POST /server/licenses/activate — licence key in; installation key and signed entitlement out.
     * A server already activated on this key (same installation_id or fingerprint) re-uses its slot.
     *
     * @return array{0: array<string, mixed>, 1: bool} signed response, created
     */
    public function activate(array $in): array
    {
        $key = trim((string) ($in['license_key'] ?? ''));
        if ($key === '') {
            throw ApiException::validation(['license_key' => 'The licence key is required']);
        }
        $lic = db_connect()->table('licenses')->where('key_hash', self::hashKey($key))->get()->getRowArray();
        if ($lic === null) {
            throw new ApiException('LICENSE_KEY_INVALID', 'This licence key is not valid. Check it, or get one from the WorkSuite Download Center.', 404);
        }
        $state = self::state($lic);
        if (in_array($state, ['SUSPENDED', 'REVOKED', 'EXPIRED', 'NOT_STARTED'], true)) {
            throw new ApiException('LICENSE_' . $state, self::stateMessage($state), 403, ['status' => $state, 'license_expires_at' => Catalog::iso($lic['expires_at'])]);
        }
        $fingerprint = isset($in['fingerprint']) ? substr(trim((string) $in['fingerprint']), 0, 128) : null;
        $hostname    = isset($in['hostname']) ? substr(trim((string) $in['hostname']), 0, 191) : null;
        $serverUrl   = isset($in['server_url']) ? substr(trim((string) $in['server_url']), 0, 512) : null;
        $askedId     = isset($in['installation_id']) && preg_match('/^[A-Za-z0-9_.:-]{6,64}$/', (string) $in['installation_id']) ? (string) $in['installation_id'] : null;

        $db     = db_connect();
        $now    = Catalog::now();
        $type   = self::installationType($in);
        $slot   = $this->slotFor($lic, $type, $askedId, $fingerprint);
        $installationId = $slot['installation_id'] ?? $askedId ?? Ulid::prefixed('inst');

        // Register or link the installation (the same row the update API uses).
        $instKey = ServerAutoUpdateService::KEY_PREFIX . Base64Url::encode(random_bytes(32));
        [$reqSecret, $reqRef] = RequestSignature::issue();
        $inst    = $db->table('server_installations')->where('installation_id', $installationId)->get()->getRowArray();
        $instSet = [
            'edition' => $lic['edition'], 'license_id' => $lic['id'], 'key_hash' => hash('sha256', $instKey), 'key_issued_at' => $now, 'updated_at' => $now,
            'request_secret_ref' => $reqRef,
        ];
        foreach (['name' => 191, 'version' => 64] as $k => $len) {
            if (isset($in[$k]) && is_string($in[$k]) && trim($in[$k]) !== '') {
                $instSet[$k] = substr(trim($in[$k]), 0, $len);
            }
        }
        if (! isset($instSet['name']) && $inst === null) {
            $instSet['name'] = $hostname ?: $lic['organization'];
        }
        if (isset($in['build'])) {
            $instSet['build'] = (int) $in['build'];
        }
        if ($inst === null) {
            $db->table('server_installations')->insert($instSet + ['installation_id' => $installationId, 'build' => $instSet['build'] ?? 0, 'registered_at' => $now, 'created_at' => $now]);
        } else {
            $db->table('server_installations')->where('id', $inst['id'])->update($instSet + ($inst['registered_at'] ? [] : ['registered_at' => $now]));
        }
        $created    = $slot === null;
        $components = self::components($in);
        // Activating again with the licence key is the controlled transfer: the server's new fingerprint is registered.
        $fp = ['fingerprint_components' => $components !== [] ? json_encode($components) : null, 'fingerprint_confidence' => $components !== [] ? 100 : null, 'transfer_state' => null];
        if ($created) {
            $db->table('license_activations')->insert([
                'license_id' => $lic['id'], 'installation_id' => $installationId, 'fingerprint' => $fingerprint, 'hostname' => $hostname, 'server_url' => $serverUrl,
                'installation_type' => $type, 'activated_at' => $now, 'last_validated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ] + $fp);
        } else {
            if ($slot['transfer_state'] ?? null) {
                service('audit')->record('license.transferred', 'license', $lic['public_id'], ['fingerprint_confidence' => $slot['fingerprint_confidence'] ?? null], ['installation_id' => $installationId, 'hostname' => $hostname]);
            }
            $db->table('license_activations')->where('id', $slot['id'])->update(['fingerprint' => $fingerprint ?: $slot['fingerprint'], 'hostname' => $hostname ?: $slot['hostname'], 'server_url' => $serverUrl ?: $slot['server_url'], 'installation_type' => $type, 'offline' => 0, 'last_validated_at' => $now, 'updated_at' => $now]
                + ($components !== [] ? $fp : ['transfer_state' => null]));
        }
        $act = $db->table('license_activations')->where('license_id', $lic['id'])->where('installation_id', $installationId)->where('deactivated_at', null)->get()->getRowArray();
        service('audit')->record($created ? 'license.activated' : 'license.reactivated', 'license', $lic['public_id'], null,
            ['installation_id' => $installationId, 'hostname' => $hostname], null, ['type' => 'INSTALLATION', 'id' => $installationId, 'label' => $hostname ?: $installationId]);
        (new LicenseRiskService())->evaluate($lic, $act);

        return [ServerAutoUpdateService::sign([
            'type'             => 'license_activation',
            'installation_id'  => $installationId,
            'installation_key' => $instKey,
            'request_secret'   => $reqSecret,
            'key_fingerprint'  => ServerAutoUpdateService::fingerprint($instKey),
            'entitlement'      => $this->entitlement($lic, $act),
        ], self::ENTITLEMENT_TTL), $created];
    }

    /**
     * POST /server/licenses/inspect — what a key would grant, without activating it: the
     * preview Admin Center shows before Change key / Change edition. Signed like every answer.
     */
    public function inspect(array $in): array
    {
        $key = trim((string) ($in['license_key'] ?? ''));
        if ($key === '') {
            throw ApiException::validation(['license_key' => 'The licence key is required']);
        }
        $lic = db_connect()->table('licenses')->where('key_hash', self::hashKey($key))->get()->getRowArray();
        if ($lic === null) {
            throw new ApiException('LICENSE_KEY_INVALID', 'This licence key is not valid. Check it, or get one from the WorkSuite Download Center.', 404);
        }
        $active = db_connect()->table('license_activations')->where('license_id', $lic['id'])->where('deactivated_at', null)->countAllResults();

        return ServerAutoUpdateService::sign(['type' => 'license_preview'] + $this->entitlement($lic, null) + ['active_activations' => $active], 300);
    }

    /** POST /server/licenses/validate — the installation's current licence state, signed. */
    public function validate(array $inst, array $in): array
    {
        $db  = db_connect();
        $lic = $inst['license_id'] ? $db->table('licenses')->where('id', $inst['license_id'])->get()->getRowArray() : null;
        if ($lic === null) {
            return ServerAutoUpdateService::sign(['type' => 'license_entitlement', 'installation_id' => $inst['installation_id'], 'status' => 'UNLICENSED',
                'message' => self::stateMessage('UNLICENSED'), 'grace_days' => self::GRACE_DAYS], self::ENTITLEMENT_TTL);
        }
        $act = $db->table('license_activations')->where('license_id', $lic['id'])->where('installation_id', $inst['installation_id'])->orderBy('id', 'DESC')->get()->getRowArray();
        if ($act !== null && $act['deactivated_at'] === null) {
            $set  = ['last_validated_at' => Catalog::now(), 'updated_at' => Catalog::now()];
            $mine = self::components($in);
            $reg  = json_decode((string) ($act['fingerprint_components'] ?? ''), true);
            if ($mine !== [] && is_array($reg) && $reg !== []) {
                $score                         = self::confidence($reg, $mine);
                $set['fingerprint_confidence'] = $score;
                if ($score < 60 && ($act['transfer_state'] ?? null) === null) {
                    $set['transfer_state'] = 'TRANSFER_REQUIRED';
                    service('audit')->record('license.transfer_required', 'license', $lic['public_id'], null, ['installation_id' => $inst['installation_id'], 'confidence' => $score], null,
                        ['type' => 'INSTALLATION', 'id' => $inst['installation_id'], 'label' => $inst['name'] ?: $inst['installation_id']]);
                } elseif ($score >= 80) {
                    // Small drift (a new disk, a cloud move with the same machine id) is folded in.
                    $set['fingerprint_components'] = json_encode($mine);
                    $set['transfer_state']         = null;
                }
            }
            if (isset($in['integrity_status']) && in_array($in['integrity_status'], ['HEALTHY', 'MODIFIED', 'COMPROMISED', 'UNVERIFIED'], true)) {
                $set['integrity_status'] = $in['integrity_status'];
                $set['integrity_at']     = Catalog::now();
            }
            $db->table('license_activations')->where('id', $act['id'])->update($set);
            $act = $db->table('license_activations')->where('id', $act['id'])->get()->getRowArray();
            (new LicenseRiskService())->evaluate($lic, $act);
        }
        $set = ['last_check_at' => Catalog::now()];
        foreach (['version' => 64] as $k => $len) {
            if (isset($in[$k]) && is_string($in[$k]) && trim($in[$k]) !== '') {
                $set[$k] = substr(trim($in[$k]), 0, $len);
            }
        }
        $db->table('server_installations')->where('id', $inst['id'])->update($set);

        return ServerAutoUpdateService::sign(['type' => 'license_entitlement'] + $this->entitlement($lic, $act), self::ENTITLEMENT_TTL);
    }

    /**
     * Server-side update gate: an installation activated with a licence key gets updates only
     * while that licence is active and update-eligible. Nothing on the customer's server can
     * change this answer. Installations linked the older way (licence token, no key) pass.
     */
    public function assertUpdatesAllowed(array $inst): void
    {
        if (empty($inst['license_id'])) {
            return;
        }
        $db  = db_connect();
        $lic = $db->table('licenses')->where('id', $inst['license_id'])->get()->getRowArray();
        $act = $lic ? $db->table('license_activations')->where('license_id', $lic['id'])->where('installation_id', $inst['installation_id'])->orderBy('id', 'DESC')->get()->getRowArray() : null;
        $ent = $lic ? $this->entitlement($lic, $act) : null;
        if ($ent === null || ! $ent['update_eligible']) {
            throw new ApiException('LICENSE_UPDATE_NOT_ELIGIBLE', 'Your WorkSuite licence is expired or not eligible for updates.', 402, ['status' => $ent['status'] ?? 'UNLICENSED', 'renew_url' => $ent['renew_url'] ?? null]);
        }
    }

    /**
     * POST /server/install/package — the one-command installer's download (IMPL-LIC-01 §7): a licence key
     * in; the newest published WorkSuite Server package for that licence's edition on the channel, a
     * short-lived download URL, and the release manifest as signed (verified by the installer against the
     * pinned release key before anything is unpacked). Nothing is activated here; the installed server
     * activates the key itself.
     */
    public function installPackage(array $in): array
    {
        $key = trim((string) ($in['license_key'] ?? ''));
        $lic = $key === '' ? null : db_connect()->table('licenses')->where('key_hash', self::hashKey($key))->get()->getRowArray();
        if ($lic === null) {
            throw new ApiException('LICENSE_KEY_INVALID', 'This licence key is not valid. Check it, or get one from the WorkSuite Download Center.', 404);
        }
        $state = self::state($lic);
        if ($state !== 'ACTIVE') {
            throw new ApiException('LICENSE_' . $state, self::stateMessage($state), 403);
        }
        $ent = $this->entitlement($lic, null);
        if (! $ent['update_eligible']) {
            throw new ApiException('LICENSE_UPDATE_NOT_ELIGIBLE', 'This licence is not eligible for downloads. Renew it in the WorkSuite Download Center.', 402);
        }
        $channel = strtoupper(trim((string) ($in['channel'] ?? 'STABLE')));
        if (! in_array($channel, ['STABLE', 'BETA', 'LTS'], true)) {
            throw ApiException::validation(['channel' => 'stable, beta or lts']);
        }
        if ($channel === 'LTS' && ($ent['entitlements']['updates.channel.lts'] ?? false) !== true) {
            throw new ApiException('ENTITLEMENT_REQUIRED', 'This licence does not include the LTS channel.', 403);
        }
        $db  = db_connect();
        $app = (new ServerUpdateService())->serverApp();
        $ch  = $db->table('release_channels')->where('code', $channel)->groupStart()->where('application_id', $app['id'])->orWhere('application_id', null)->groupEnd()->orderBy('application_id', 'DESC')->get()->getRowArray();
        if ($ch === null) {
            throw ApiException::notFound('CHANNEL_NOT_FOUND', 'That channel is not available for WorkSuite Server.');
        }
        $q = $db->table('releases r')
            ->select('r.*, p.public_id AS pkg_public_id, p.id AS pkg_id')
            ->join('release_packages p', 'p.release_id = r.id')
            ->where('r.application_id', $app['id'])->where('r.channel_id', $ch['id'])->where('r.status', 'PUBLISHED')->where('r.support_state !=', 'BLOCKED')
            ->where('p.platform', 'server')->where('p.package_type', 'zip')->where('p.status', 'READY')->where('p.edition', $lic['edition']);
        if (! empty($in['version'])) {
            $q->where('r.version', (string) $in['version']);
        }
        $rel = $q->orderBy('r.version_sort', 'DESC')->orderBy('r.build_number', 'DESC')->limit(1)->get()->getRowArray();
        if ($rel === null) {
            throw ApiException::notFound('NO_PACKAGE', 'No published WorkSuite Server ' . ucfirst(strtolower($lic['edition'])) . ' package on the ' . strtolower($channel) . ' channel yet.');
        }
        $man = (new ManifestService())->latestSigned((int) $rel['id']);
        if ($man === null) {
            throw ApiException::notFound('NO_MANIFEST', 'That release has no signed manifest yet.');
        }
        $rev = $db->table('release_package_revisions')->where('release_package_id', $rel['pkg_id'])->whereIn('status', ['READY'])->orderBy('id', 'DESC')->get()->getRowArray();
        if ($rev === null) {
            throw ApiException::notFound('NO_PACKAGE', 'The package has no ready file.');
        }
        $resolver = new UpdateResolverService();
        $grant    = $resolver->issueGrant(['public_id' => $rel['pkg_public_id'], 'rev_public_id' => $rev['public_id']], 'install:' . $lic['public_id'], 3600);
        $dl       = $resolver->redeemGrant($grant['grant']);
        service('audit')->record('license.install_download', 'license', $lic['public_id'], null, ['version' => $rel['version'], 'build' => (int) $rel['build_number'], 'channel' => $channel]);

        return [
            'version'      => $rel['version'],
            'build'        => (int) $rel['build_number'],
            'edition'      => strtolower($lic['edition']),
            'channel'      => strtolower($channel),
            'file_name'    => $rev['file_name'],
            'size'         => (int) $rev['size_bytes'],
            'sha256'       => $rev['sha256'],
            'download_url' => $dl['download_url'],
            'expires_at'   => $dl['expires_at'],
            'manifest'     => json_decode((string) $man['manifest_json'], true),
        ];
    }

    /**
     * The revocation manifest: revoked licences, installations whose activation was deactivated
     * (their credentials are void), and revoked signing keys. Signed with the update-response key,
     * valid a day; a server that finds itself here stops trusting its cached entitlement at once.
     */
    public function revocations(): array
    {
        $db   = db_connect();
        $lics = array_column($db->table('licenses')->select('public_id')->where('status', 'REVOKED')->orderBy('id')->get()->getResultArray(), 'public_id');
        $inst = array_values(array_unique(array_column($db->table('license_activations')->select('installation_id')->where('deactivated_at IS NOT NULL', null, false)
            ->where('installation_id NOT IN (SELECT installation_id FROM license_activations WHERE deactivated_at IS NULL)', null, false)->get()->getResultArray(), 'installation_id')));
        $keys = $db->tableExists('signing_keys') ? array_column($db->table('signing_keys')->select('key_id')->where('status', 'REVOKED')->get()->getResultArray(), 'key_id') : [];

        return ServerAutoUpdateService::sign(['type' => 'revocations', 'schema' => 1, 'revoked_licenses' => $lics, 'revoked_installations' => $inst, 'revoked_key_ids' => $keys], 86400);
    }

    public const INSTALLATION_TYPES = ['PRODUCTION', 'STAGING', 'DR'];

    public static function installationType(array $in): string
    {
        $t = strtoupper(trim((string) ($in['installation_type'] ?? 'PRODUCTION')));
        if (! in_array($t, self::INSTALLATION_TYPES, true)) {
            throw ApiException::validation(['installation_type' => 'PRODUCTION, STAGING or DR']);
        }

        return $t;
    }

    /**
     * The activation this server re-uses (same installation id, or same fingerprint), or null when a new
     * slot is free. Production servers count against max_activations; staging and disaster-recovery
     * servers against their own entitlements (license.staging.max, license.dr.max).
     */
    private function slotFor(array $lic, string $type, ?string $installationId, ?string $fingerprint): ?array
    {
        $active = db_connect()->table('license_activations')->where('license_id', $lic['id'])->where('deactivated_at', null)->get()->getResultArray();
        foreach ($active as $a) {
            if (($installationId !== null && $a['installation_id'] === $installationId) || ($fingerprint !== null && $fingerprint !== '' && $a['fingerprint'] === $fingerprint)) {
                return $a;
            }
        }
        $same = count(array_filter($active, static fn ($a) => ($a['installation_type'] ?? 'PRODUCTION') === $type));
        if ($type === 'PRODUCTION') {
            $max = max(1, (int) $lic['max_activations']);
        } else {
            $ent = (new EntitlementCatalogService())->resolve($lic);
            $v   = $ent[$type === 'STAGING' ? 'license.staging.max' : 'license.dr.max'] ?? 0;
            $max = $v === null ? PHP_INT_MAX : (int) $v;
        }
        if ($same >= $max) {
            $what = ['PRODUCTION' => 'server(s)', 'STAGING' => 'staging server(s)', 'DR' => 'disaster-recovery server(s)'][$type];
            throw new ApiException('LICENSE_ACTIVATION_LIMIT', $max === 0
                ? 'This licence does not include ' . strtolower(str_replace('(s)', 's', $what)) . '.'
                : 'This licence is already activated on ' . $same . ' ' . $what . ', its limit. Deactivate one in the WorkSuite Download Center, or use another key.', 409);
        }

        return null;
    }

    /** Longest an offline licence file stays valid without a new one. */
    public const OFFLINE_MAX_DAYS = 365;

    /**
     * POST /server/licenses/offline — offline activation (IMPL-LIC-01 §7): the licence key and the
     * server's request file (.wsr) in; a licence file (.wsl) out, signed, bound to that installation and
     * that request's nonce, valid until the licence expires or a year, whichever is first. Needs the
     * `offline.license.enabled` entitlement. The server never talks to the Download Center.
     */
    public function offline(array $in): array
    {
        $key = trim((string) ($in['license_key'] ?? ''));
        $req = is_array($in['request'] ?? null) ? $in['request'] : json_decode((string) ($in['request'] ?? ''), true);
        $lic = $key === '' ? null : db_connect()->table('licenses')->where('key_hash', self::hashKey($key))->get()->getRowArray();
        if ($lic === null) {
            throw new ApiException('LICENSE_KEY_INVALID', 'This licence key is not valid.', 404);
        }

        return $this->offlineFor($lic, is_array($req) ? $req : null);
    }

    /** Offline activation for a known licence (the key above, or the Customer Portal's signed-in customer). */
    public function offlineFor(array $lic, ?array $req): array
    {
        if (! is_array($req) || ($req['type'] ?? '') !== 'license_request' || (int) ($req['schema'] ?? 0) !== 1
            || ! preg_match('/^[A-Za-z0-9_.:-]{6,64}$/', (string) ($req['installation_id'] ?? '')) || ! preg_match('/^[A-Za-z0-9_-]{16,64}$/', (string) ($req['nonce'] ?? ''))) {
            throw ApiException::validation(['request' => 'Not a WorkSuite licence request file (.wsr)']);
        }
        $state = self::state($lic);
        if ($state !== 'ACTIVE') {
            throw new ApiException('LICENSE_' . $state, self::stateMessage($state), 403);
        }
        if (((new EntitlementCatalogService())->resolve($lic)['offline.license.enabled'] ?? false) !== true) {
            throw new ApiException('ENTITLEMENT_REQUIRED', 'This licence does not include offline activation (an Enterprise feature).', 403);
        }
        $db    = db_connect();
        $now   = Catalog::now();
        $type  = self::installationType($req);
        $inst  = (string) $req['installation_id'];
        $fp    = isset($req['fingerprint']) ? substr((string) $req['fingerprint'], 0, 128) : null;
        $slot  = $this->slotFor($lic, $type, $inst, $fp);
        $until = min(strtotime(($lic['expires_at'] ?? '2999-12-31') . ' UTC') ?: PHP_INT_MAX, time() + self::OFFLINE_MAX_DAYS * 86400);
        $comps = self::components($req);
        $set   = [
            'fingerprint' => $fp, 'hostname' => isset($req['hostname']) ? substr((string) $req['hostname'], 0, 191) : null, 'installation_type' => $type,
            'offline' => 1, 'offline_request_nonce' => (string) $req['nonce'], 'offline_valid_until' => gmdate('Y-m-d H:i:s', $until),
            'fingerprint_components' => $comps !== [] ? json_encode($comps) : null, 'fingerprint_confidence' => $comps !== [] ? 100 : null, 'transfer_state' => null,
            'last_validated_at' => $now, 'updated_at' => $now,
        ];
        if ($slot === null) {
            $db->table('license_activations')->insert($set + ['license_id' => $lic['id'], 'installation_id' => $inst, 'activated_at' => $now, 'created_at' => $now]);
        } else {
            $db->table('license_activations')->where('id', $slot['id'])->update($set);
        }
        if ($db->table('server_installations')->where('installation_id', $inst)->countAllResults() === 0) {
            $db->table('server_installations')->insert(['installation_id' => $inst, 'edition' => $lic['edition'], 'license_id' => $lic['id'], 'mode' => 'AIR_GAPPED', 'offline_mode' => 1,
                'name' => $set['hostname'] ?: $lic['organization'], 'build' => 0, 'registered_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $db->table('server_installations')->where('installation_id', $inst)->update(['license_id' => $lic['id'], 'edition' => $lic['edition'], 'offline_mode' => 1, 'updated_at' => $now]);
        }
        $act = $db->table('license_activations')->where('license_id', $lic['id'])->where('installation_id', $inst)->where('deactivated_at', null)->get()->getRowArray();
        service('audit')->record('license.offline_activated', 'license', $lic['public_id'], null, ['installation_id' => $inst, 'valid_until' => gmdate('Y-m-d', $until), 'type' => $type]);

        return ServerAutoUpdateService::sign([
            'type'                => 'license_offline',
            'installation_id'     => $inst,
            'request_nonce'       => (string) $req['nonce'],
            'offline_valid_until' => gmdate('Y-m-d\TH:i:s\Z', $until),
        ] + $this->entitlement($lic, $act), max(60, $until - time()));
    }

    /** POST /server/licenses/deactivate — the server frees its own slot (decommission, or before a move). */
    public function deactivateSelf(array $inst, array $in): array
    {
        $db  = db_connect();
        $act = $inst['license_id'] ? $db->table('license_activations')->where('license_id', $inst['license_id'])->where('installation_id', $inst['installation_id'])->where('deactivated_at', null)->get()->getRowArray() : null;
        if ($act === null) {
            throw new ApiException('NOT_ACTIVATED', 'This server has no active licence activation.', 409);
        }
        $reason = trim((string) ($in['reason'] ?? '')) ?: 'Deactivated from the server';
        $db->table('license_activations')->where('id', $act['id'])->update(['deactivated_at' => Catalog::now(), 'deactivate_reason' => substr($reason, 0, 255), 'updated_at' => Catalog::now()]);
        $db->table('server_installations')->where('id', $inst['id'])->update(['request_secret_ref' => null, 'key_hash' => hash('sha256', random_bytes(32)), 'updated_at' => Catalog::now()]);
        $lic = $db->table('licenses')->where('id', $inst['license_id'])->get()->getRowArray();
        service('audit')->record('license.deactivated', 'license', $lic['public_id'] ?? null, null, ['installation_id' => $inst['installation_id']], $reason,
            ['type' => 'INSTALLATION', 'id' => $inst['installation_id'], 'label' => $inst['name'] ?: $inst['installation_id']]);

        return ServerAutoUpdateService::sign(['type' => 'license_deactivation', 'installation_id' => $inst['installation_id'], 'deactivated' => true], 300);
    }

    /** Weights of each fingerprint component (IMPL-LIC-01 §3.5). */
    public const FP_WEIGHTS = ['uuid' => 35, 'secret' => 25, 'machine' => 15, 'dmi' => 10, 'cloud' => 10, 'disk' => 5];

    /** The per-component hashes a server sent (64 hex each); unknown components are ignored. @return array<string,string> */
    public static function components(array $in): array
    {
        $out = [];
        foreach ((array) ($in['fingerprint_components'] ?? []) as $k => $v) {
            if (isset(self::FP_WEIGHTS[$k]) && is_string($v) && preg_match('/^[a-f0-9]{64}$/', $v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** 0–100: the weight of matching components over the weight of components both sides have. */
    public static function confidence(array $registered, array $now): int
    {
        $both = $match = 0;
        foreach (self::FP_WEIGHTS as $k => $w) {
            if (isset($registered[$k], $now[$k])) {
                $both += $w;
                if (hash_equals((string) $registered[$k], (string) $now[$k])) {
                    $match += $w;
                }
            }
        }

        return $both === 0 ? 100 : (int) round(100 * $match / $both);
    }

    // ---- Shared ------------------------------------------------------------------

    /** ACTIVE | EXPIRED | SUSPENDED | REVOKED | NOT_STARTED, at this moment. */
    public static function state(array $lic, ?array $activation = null): string
    {
        if ($lic['status'] === 'REVOKED' || $lic['status'] === 'SUSPENDED') {
            return $lic['status'];
        }
        if ($activation !== null && $activation['deactivated_at'] !== null) {
            return 'DEACTIVATED';
        }
        if ($activation !== null && ($activation['transfer_state'] ?? null) === 'TRANSFER_REQUIRED') {
            return 'TRANSFER_REQUIRED';
        }
        $now = time();
        if ($lic['starts_at'] && strtotime($lic['starts_at'] . ' UTC') > $now) {
            return 'NOT_STARTED';
        }
        if ($lic['expires_at'] && strtotime($lic['expires_at'] . ' UTC') < $now) {
            return 'EXPIRED';
        }

        return 'ACTIVE';
    }

    public static function stateMessage(string $state): string
    {
        return match ($state) {
            'ACTIVE'      => 'The licence is valid and active.',
            'EXPIRED'     => 'Your WorkSuite licence has expired. Renew it to keep licensed services and updates.',
            'SUSPENDED'   => 'Your WorkSuite licence is suspended. Contact WorkSuite or renew it to restore licensed services.',
            'REVOKED'     => 'This WorkSuite licence key has been revoked. Activate a new key.',
            'DEACTIVATED' => 'This server was deactivated from its licence. Activate the licence again.',
            'NOT_STARTED' => 'This licence is not valid yet.',
            'TRANSFER_REQUIRED' => 'This server no longer looks like the one the licence was activated on. Activate the licence again here to transfer it, or deactivate the old server in the WorkSuite Download Center.',
            default       => 'This server has no WorkSuite licence. Activate a licence key.',
        };
    }

    /** Modules an edition includes: its own and every lower edition's (edition_modules). */
    public static function editionModules(string $edition): array
    {
        $rank = self::EDITION_ORDER[strtoupper($edition)] ?? 0;
        $codes = array_keys(array_filter(self::EDITION_ORDER, static fn ($r) => $r <= $rank));
        if ($codes === []) {
            return [];
        }
        $rows = db_connect()->table('edition_modules')->select('module_slug')->whereIn('edition_code', $codes)->get()->getResultArray();
        $out  = array_values(array_unique(array_map(static fn ($r) => strtolower((string) $r['module_slug']), $rows)));
        sort($out);

        return $out;
    }

    /** The entitlement a server caches and enforces (signed by the caller). */
    private function entitlement(array $lic, ?array $activation): array
    {
        $state        = self::state($lic, $activation);
        $updatesUntil = $lic['updates_until'] ?: $lic['expires_at'];
        $entitled     = (new EntitlementCatalogService())->resolve($lic);
        $eligible     = $state === 'ACTIVE' && ($updatesUntil === null || strtotime($updatesUntil . ' UTC') >= time()) && ($entitled['updates.core'] ?? true) !== false
            && ($activation['integrity_status'] ?? null) !== 'COMPROMISED'
            && ! (new LicenseRiskService())->holdsUpdates((int) $lic['id']);

        return [
            'token_version'      => 1,
            'license_id'         => $lic['public_id'],
            'license_key_masked' => self::maskKey($lic['key_last4']),
            'status'             => $state,
            'message'            => self::stateMessage($state),
            'edition'            => strtolower($lic['edition']),
            'organization'       => $lic['organization'],
            'entitlements'       => $entitled,
            'modules'            => EntitlementCatalogService::modules($entitled),
            'installation_id'    => $activation['installation_id'] ?? null,
            'hostname'           => $activation['hostname'] ?? null,
            'fingerprint'        => (bool) $lic['server_bound'] ? ($activation['fingerprint'] ?? null) : null,
            'server_bound'       => (bool) $lic['server_bound'],
            'installation_type'  => $activation['installation_type'] ?? 'PRODUCTION',
            'fingerprint_confidence' => isset($activation['fingerprint_confidence']) ? (int) $activation['fingerprint_confidence'] : null,
            'activated_at'       => Catalog::iso($activation['activated_at'] ?? null),
            'license_starts_at'  => Catalog::iso($lic['starts_at']),
            'license_expires_at' => Catalog::iso($lic['expires_at']),
            'updates_until'      => Catalog::iso($updatesUntil),
            'update_eligible'    => $eligible,
            'max_activations'    => (int) $lic['max_activations'],
            'grace_days'         => self::GRACE_DAYS,
            'renew_url'          => rtrim(config('UpdateCenter')->publicBaseUrl, '/') . '/portal',
        ];
    }

    private function find(string $publicId): array
    {
        $row = db_connect()->table('licenses')->where('public_id', $publicId)->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('NOT_FOUND', 'Licence not found', 404);
        }

        return $row;
    }

    private function validated(array $in, bool $creating): array
    {
        $e   = [];
        $set = [];
        if ($creating || array_key_exists('organization', $in)) {
            $org = trim((string) ($in['organization'] ?? ''));
            $org === '' ? $e['organization'] = 'The licensed organisation is required' : $set['organization'] = substr($org, 0, 191);
        }
        if (array_key_exists('contact_email', $in)) {
            $mail = trim((string) $in['contact_email']);
            if ($mail !== '' && ! filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                $e['contact_email'] = 'Not a valid email address';
            }
            $set['contact_email'] = $mail === '' ? null : strtolower($mail);
        }
        if ($creating || array_key_exists('edition', $in)) {
            $ed = strtoupper(trim((string) ($in['edition'] ?? '')));
            $ed = $ed === 'PROFESSIONAL' ? 'FREE' : $ed;
            in_array($ed, self::EDITIONS, true) ? $set['edition'] = $ed : $e['edition'] = 'Free (Professional), Business or Enterprise';
        }
        if ($creating || array_key_exists('max_activations', $in)) {
            $n = (int) ($in['max_activations'] ?? 1);
            ($n >= 1 && $n <= 1000) ? $set['max_activations'] = $n : $e['max_activations'] = 'Between 1 and 1000 servers';
        }
        foreach (['starts_at', 'expires_at', 'updates_until'] as $d) {
            if (array_key_exists($d, $in)) {
                $v = trim((string) ($in[$d] ?? ''));
                if ($v === '') {
                    $set[$d] = null;
                } elseif (strtotime($v) === false) {
                    $e[$d] = 'Not a valid date';
                } else {
                    $set[$d] = gmdate('Y-m-d H:i:s', (int) strtotime(strlen($v) === 10 ? $v . ' 23:59:59 UTC' : $v));
                }
            }
        }
        if ($creating && empty($set['expires_at'])) {
            $e['expires_at'] = 'The expiry date is required';
        }
        if (array_key_exists('server_bound', $in)) {
            $set['server_bound'] = $in['server_bound'] ? 1 : 0;
        }
        if (array_key_exists('customer_ref', $in)) {
            $ref = trim((string) $in['customer_ref']);
            $set['customer_ref'] = $ref === '' ? null : substr($ref, 0, 64);
        }
        if (array_key_exists('notes', $in)) {
            $set['notes'] = trim((string) $in['notes']) ?: null;
        }
        if ($e !== []) {
            throw ApiException::validation($e);
        }

        return $set;
    }

    private function auditView(array $row): array
    {
        return array_intersect_key($row, array_flip(['organization', 'contact_email', 'edition', 'status', 'max_activations', 'starts_at', 'expires_at', 'updates_until', 'server_bound']));
    }

    public function present(array $r): array
    {
        $db     = db_connect();
        $active = $db->table('license_activations')->where('license_id', $r['id'])->where('deactivated_at', null)->countAllResults();

        return [
            'id'                 => $r['public_id'],
            'license_key_masked' => self::maskKey($r['key_last4']),
            'organization'       => $r['organization'],
            'contact_email'      => $r['contact_email'],
            'edition'            => $r['edition'],
            'status'             => $r['status'],
            'state'              => self::state($r),
            'status_reason'      => $r['status_reason'],
            'max_activations'    => (int) $r['max_activations'],
            'active_activations' => $active,
            'starts_at'          => Catalog::iso($r['starts_at']),
            'expires_at'         => Catalog::iso($r['expires_at']),
            'updates_until'      => Catalog::iso($r['updates_until'] ?: $r['expires_at']),
            'server_bound'       => (bool) $r['server_bound'],
            'notes'              => $r['notes'],
            'customer_ref'       => $r['customer_ref'] ?? null,
            'is_trial'           => (bool) ($r['is_trial'] ?? false),
            'key_revealable'     => ! empty($r['key_sealed']),
            'created_by'         => $r['created_by'],
            'created_at'         => Catalog::iso($r['created_at']),
            'updated_at'         => Catalog::iso($r['updated_at']),
        ];
    }
}

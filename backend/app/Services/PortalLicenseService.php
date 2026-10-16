<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\SecretStore;

/**
 * A customer's own WorkSuite Server licences in the Download Center portal (IMPL-LIC-01 P3).
 *
 * A licence belongs to a portal user when its contact email is that user's verified email; nothing
 * else is visible, and someone else's licence answers 404. Every action goes through LicenseService,
 * the only thing that issues keys or signs, and is audited with the customer as the actor.
 */
final class PortalLicenseService
{
    /** Trials: days, editions, servers. One trial per account. */
    public const TRIAL_DAYS = 30;

    public const TRIAL_EDITIONS = ['BUSINESS', 'ENTERPRISE'];

    public function __construct(private readonly array $user)
    {
        if (empty($user['email_verified_at']) && ($user['kind'] ?? '') === 'PORTAL') {
            throw new ApiException('EMAIL_NOT_VERIFIED', 'Verify your email address to see your licences.', 403);
        }
    }

    private function email(): string
    {
        return strtolower(trim((string) $this->user['email']));
    }

    private function actor(): array
    {
        return ['type' => 'USER', 'id' => (string) ($this->user['public_id'] ?? $this->user['id'] ?? ''), 'label' => (string) $this->user['email']];
    }

    /** The user's licence, or 404 — never someone else's. */
    private function own(string $publicId): array
    {
        $row = db_connect()->table('licenses')->where('public_id', $publicId)->get()->getRowArray();
        if ($row === null || strtolower((string) $row['contact_email']) !== $this->email()) {
            throw new ApiException('NOT_FOUND', 'Licence not found', 404);
        }

        return $row;
    }

    public function list(): array
    {
        $svc  = new LicenseService();
        $rows = db_connect()->table('licenses')->where('contact_email', $this->email())->orderBy('id', 'DESC')->get()->getResultArray();

        return ['data' => array_map(fn ($r) => $this->present($svc->present($r)), $rows), 'trial_available' => $this->trialAvailable()];
    }

    public function detail(string $id): array
    {
        $row = $this->own($id);
        $d   = (new LicenseService())->detail($id);
        $ent = (new EntitlementCatalogService())->resolve($row);

        return $this->present($d) + [
            'entitlements' => $ent,
            'modules'      => EntitlementCatalogService::modules($ent),
            'history'      => $this->history($row),
        ];
    }

    /** The full key again, for a key the Download Center sealed (made after 14 Oct 2026). */
    public function reveal(string $id): array
    {
        $row = $this->own($id);
        $key = SecretStore::get((string) ($row['key_sealed'] ?? ''));
        if ($key === null || $key === '') {
            throw new ApiException('KEY_NOT_REVEALABLE', 'This key was issued before keys could be shown again. Replace it to get a new one you can see.', 409);
        }
        service('audit')->record('license.key_revealed', 'license', $id, null, null, null, $this->actor());

        return ['license_key' => $key];
    }

    /** A new key for the same licence: activated servers keep working; the old key activates nothing more. */
    public function replaceKey(string $id): array
    {
        $row = $this->own($id);
        if ($row['status'] === 'REVOKED') {
            throw new ApiException('LICENSE_REVOKED', 'A revoked licence cannot get a new key.', 409);
        }
        $key = LicenseService::generateKey();
        db_connect()->table('licenses')->where('id', $row['id'])->update([
            'key_hash' => LicenseService::hashKey($key), 'key_last4' => substr($key, -4), 'key_sealed' => SecretStore::seal($key), 'updated_at' => Catalog::now(),
        ]);
        service('audit')->record('license.key_replaced', 'license', $id, ['key_last4' => $row['key_last4']], ['key_last4' => substr($key, -4)], 'Replaced by the customer', $this->actor());

        return ['license_key' => $key] + $this->detail($id);
    }

    /** Free a server's slot (decommissioned, moved, or lost). That server's credentials stop working. */
    public function deactivate(string $id, int $activationId, ?string $reason): array
    {
        $row = $this->own($id);
        $act = db_connect()->table('license_activations')->where('id', $activationId)->where('license_id', $row['id'])->get()->getRowArray();
        if ($act === null) {
            throw new ApiException('NOT_FOUND', 'Server not found', 404);
        }
        (new LicenseService())->deactivate($id, $activationId, trim((string) $reason) ?: 'Deactivated by the customer');
        db_connect()->table('server_installations')->where('installation_id', $act['installation_id'])->update(['request_secret_ref' => null, 'key_hash' => hash('sha256', random_bytes(32)), 'updated_at' => Catalog::now()]);

        return $this->detail($id);
    }

    /** Offline activation for the signed-in customer: the request file in, the licence file out. */
    public function offline(string $id, mixed $request): array
    {
        $req = is_array($request) ? $request : json_decode((string) $request, true);

        return (new LicenseService())->offlineFor($this->own($id), is_array($req) ? $req : null);
    }

    /** What another edition would add and take away (buying it goes through WorkSuite sales). */
    public function editionPreview(string $id, string $edition): array
    {
        $row     = $this->own($id);
        $edition = strtoupper($edition) === 'PROFESSIONAL' ? 'FREE' : strtoupper($edition);
        if (! in_array($edition, LicenseService::EDITIONS, true)) {
            throw ApiException::validation(['edition' => 'professional, business or enterprise']);
        }
        $cat  = new EntitlementCatalogService();
        $now  = $cat->resolve($row);
        $next = $cat->resolve(['edition' => $edition] + $row);
        $add  = $rm = [];
        foreach ($cat->catalog() as $d) {
            [$a, $b] = [$now[$d['key']] ?? null, $next[$d['key']] ?? null];
            if ($a === $b) {
                continue;
            }
            $entry = ['key' => $d['key'], 'label' => $d['label'], 'from' => $a, 'to' => $b];
            ($d['kind'] === 'bool' ? ($b === true) : ($b === null || ($a !== null && (int) $b > (int) $a))) ? $add[] = $entry : $rm[] = $entry;
        }

        return ['from' => $row['edition'], 'to' => $edition, 'adds' => $add, 'removes' => $rm];
    }

    public function trialAvailable(): bool
    {
        return db_connect()->table('licenses')->where('contact_email', $this->email())->where('is_trial', 1)->countAllResults() === 0;
    }

    /** A 30-day trial licence for one server; one per account. The key is shown once, and can be revealed later. */
    public function startTrial(string $edition, ?string $organization): array
    {
        $edition = strtoupper($edition);
        if (! in_array($edition, self::TRIAL_EDITIONS, true)) {
            throw ApiException::validation(['edition' => 'business or enterprise']);
        }
        if (! $this->trialAvailable()) {
            throw new ApiException('TRIAL_USED', 'This account has already had a trial. Contact WorkSuite sales to continue.', 409);
        }
        [$lic, $key] = (new LicenseService())->create([
            'organization'    => trim((string) $organization) ?: (string) ($this->user['name'] ?? $this->user['email']),
            'contact_email'   => $this->email(),
            'edition'         => $edition,
            'max_activations' => 1,
            'expires_at'      => gmdate('Y-m-d', time() + self::TRIAL_DAYS * 86400),
            'notes'           => 'Trial started in the portal',
        ], (string) $this->user['email']);
        db_connect()->table('licenses')->where('public_id', $lic['id'])->update(['is_trial' => 1]);
        service('audit')->record('license.trial_started', 'license', $lic['id'], null, ['edition' => $edition, 'days' => self::TRIAL_DAYS], null, $this->actor());

        return ['license_key' => $key] + $this->detail($lic['id']);
    }

    /** What happened to a licence, for its customer: no staff names, no internal notes. */
    private function history(array $row): array
    {
        $labels = [
            'license.created' => 'Licence issued', 'license.updated' => 'Licence changed', 'license.renewed' => 'Renewed', 'license.suspended' => 'Suspended',
            'license.resumed' => 'Resumed', 'license.revoked' => 'Revoked', 'license.activated' => 'Activated on a server', 'license.reactivated' => 'Activated again',
            'license.transferred' => 'Moved to another server', 'license.deactivated' => 'Server deactivated', 'license.offline_activated' => 'Offline licence file issued',
            'license.key_replaced' => 'Key replaced', 'license.key_revealed' => 'Key shown', 'license.trial_started' => 'Trial started', 'license.entitlements_changed' => 'Add-ons changed',
        ];
        $rows = db_connect()->table('audit_logs')->select('action, created_at')->where('entity_type', 'license')->where('entity_id', $row['public_id'])
            ->whereIn('action', array_keys($labels))->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();

        return array_map(static fn ($r) => ['event' => $labels[$r['action']], 'at' => Catalog::iso($r['created_at'])], $rows);
    }

    /** Staff-only fields stay out of the portal. */
    private function present(array $l): array
    {
        unset($l['notes'], $l['created_by'], $l['status_reason'], $l['customer_ref']);
        if (isset($l['activations'])) {
            $l['activations'] = array_map(static fn ($a) => array_diff_key($a, ['fingerprint' => 1]), $l['activations']);
        }

        return $l;
    }
}

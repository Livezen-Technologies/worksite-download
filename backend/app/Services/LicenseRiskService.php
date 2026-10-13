<?php

namespace App\Services;

use App\Exceptions\ApiException;

/**
 * The licence risk engine (IMPL-LIC-01 §4.5): rules over what installations report and do, a
 * severity, and a flag for staff. One open flag per licence, installation and rule; repeats count
 * up. A HIGH or CRITICAL open flag holds the licence's updates until staff resolve it; nothing else
 * changes for the customer, whose messages stay generic.
 */
final class LicenseRiskService
{
    public const SEVERITY_RANK = ['LOW' => 1, 'MEDIUM' => 2, 'HIGH' => 3, 'CRITICAL' => 4];

    public const RULES = [
        'integrity_compromised' => ['HIGH', 'The installation reports protected WorkSuite files that differ from the signed release.'],
        'clone_suspected'       => ['HIGH', 'An installation no longer matches the server it was activated on (another machine running the same installation).'],
        'activation_churn'      => ['MEDIUM', 'More than five activations of this licence in 24 hours.'],
        'deactivation_cycle'    => ['MEDIUM', 'More than three deactivations of this licence in seven days.'],
    ];

    /** Run the rules after an activation or validation. @return list<string> rules raised */
    public function evaluate(array $licence, ?array $activation): array
    {
        $db    = db_connect();
        $raise = [];
        if ($activation !== null && ($activation['integrity_status'] ?? null) === 'COMPROMISED') {
            $raise['integrity_compromised'] = ['integrity_at' => $activation['integrity_at'] ?? null];
        }
        if ($activation !== null && ($activation['transfer_state'] ?? null) === 'TRANSFER_REQUIRED') {
            $raise['clone_suspected'] = ['confidence' => $activation['fingerprint_confidence'] ?? null];
        }
        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        $acts  = $db->table('audit_logs')->where('entity_type', 'license')->where('entity_id', $licence['public_id'])
            ->whereIn('action', ['license.activated', 'license.reactivated', 'license.transferred'])->where('created_at >=', $since)->countAllResults();
        if ($acts > 5) {
            $raise['activation_churn'] = ['activations_24h' => $acts];
        }
        $deacts = $db->table('audit_logs')->where('entity_type', 'license')->where('entity_id', $licence['public_id'])
            ->where('action', 'license.deactivated')->where('created_at >=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))->countAllResults();
        if ($deacts > 3) {
            $raise['deactivation_cycle'] = ['deactivations_7d' => $deacts];
        }
        foreach ($raise as $rule => $detail) {
            $this->raise($licence, $activation['installation_id'] ?? null, $rule, $detail);
        }
        // A condition that has cleared closes its own flag (a transfer by re-activation, a reinstalled release).
        if ($activation !== null) {
            foreach (['integrity_compromised' => 'The installation reports its protected files healthy again.', 'clone_suspected' => 'The licence was activated again on this server (transfer).'] as $rule => $why) {
                if (! isset($raise[$rule])) {
                    $this->clear($licence, (string) $activation['installation_id'], $rule, $why);
                }
            }
        }

        return array_keys($raise);
    }

    /** True when an open HIGH or CRITICAL flag holds this licence's updates. */
    public function holdsUpdates(int $licenseId): bool
    {
        return db_connect()->table('license_risk_flags')->where('license_id', $licenseId)->where('status', 'OPEN')->whereIn('severity', ['HIGH', 'CRITICAL'])->countAllResults() > 0;
    }

    private function raise(array $licence, ?string $installationId, string $rule, array $detail): void
    {
        $db  = db_connect();
        $now = Catalog::now();
        $q   = $db->table('license_risk_flags')->where('license_id', $licence['id'])->where('rule', $rule)->whereIn('status', ['OPEN', 'ACKNOWLEDGED']);
        $installationId === null ? $q->where('installation_id', null) : $q->where('installation_id', $installationId);
        $row = $q->get()->getRowArray();
        if ($row) {
            $db->table('license_risk_flags')->where('id', $row['id'])->update(['occurrences' => (int) $row['occurrences'] + 1, 'last_seen_at' => $now, 'detail' => json_encode($detail), 'updated_at' => $now]);

            return;
        }
        [$severity] = self::RULES[$rule];
        $db->table('license_risk_flags')->insert([
            'license_id' => $licence['id'], 'installation_id' => $installationId, 'rule' => $rule, 'severity' => $severity, 'detail' => json_encode($detail),
            'status' => 'OPEN', 'occurrences' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        service('audit')->record('license.risk_flagged', 'license', $licence['public_id'], null, ['rule' => $rule, 'severity' => $severity, 'installation_id' => $installationId] + $detail);
    }

    private function clear(array $licence, string $installationId, string $rule, string $why): void
    {
        $db   = db_connect();
        $open = $db->table('license_risk_flags')->where('license_id', $licence['id'])->where('installation_id', $installationId)->where('rule', $rule)->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])->get()->getResultArray();
        foreach ($open as $f) {
            $db->table('license_risk_flags')->where('id', $f['id'])->update(['status' => 'RESOLVED', 'resolution' => $why, 'resolved_by' => 'risk engine', 'updated_at' => Catalog::now()]);
            service('audit')->record('license.risk_resolved', 'license', $licence['public_id'], ['status' => $f['status']], ['status' => 'RESOLVED', 'rule' => $rule], $why);
        }
    }

    // ---- Staff -------------------------------------------------------------------

    public function list(array $q, int $limit, int $offset): array
    {
        $b = db_connect()->table('license_risk_flags f')->select('f.*, l.public_id AS license_public_id, l.organization, l.key_last4')->join('licenses l', 'l.id = f.license_id');
        $status = strtoupper((string) ($q['status'] ?? 'OPEN'));
        if ($status !== 'ALL') {
            $b->where('f.status', $status);
        }
        if (! empty($q['license'])) {
            $b->where('l.public_id', (string) $q['license']);
        }
        $total = $b->countAllResults(false);
        $rows  = $b->orderBy('f.last_seen_at', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return ['data' => array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'license_id' => $r['license_public_id'], 'organization' => $r['organization'], 'license_key_masked' => LicenseService::maskKey($r['key_last4']),
            'installation_id' => $r['installation_id'], 'rule' => $r['rule'], 'label' => self::RULES[$r['rule']][1] ?? $r['rule'], 'severity' => $r['severity'],
            'detail' => json_decode((string) $r['detail'], true), 'status' => $r['status'], 'occurrences' => (int) $r['occurrences'],
            'first_seen_at' => Catalog::iso($r['first_seen_at']), 'last_seen_at' => Catalog::iso($r['last_seen_at']), 'resolved_by' => $r['resolved_by'], 'resolution' => $r['resolution'],
        ], $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    public function setStatus(int $id, string $status, ?string $note, ?string $by): array
    {
        $db  = db_connect();
        $row = $db->table('license_risk_flags')->where('id', $id)->get()->getRowArray() ?? throw new ApiException('NOT_FOUND', 'Risk flag not found', 404);
        if ($status === 'RESOLVED' && trim((string) $note) === '') {
            throw ApiException::validation(['resolution' => 'Say how it was resolved']);
        }
        $db->table('license_risk_flags')->where('id', $id)->update(['status' => $status, 'resolution' => $note ?: $row['resolution'], 'resolved_by' => $by, 'updated_at' => Catalog::now()]);
        $lic = $db->table('licenses')->where('id', $row['license_id'])->get()->getRowArray();
        service('audit')->record('license.risk_' . strtolower($status), 'license', $lic['public_id'] ?? null, ['status' => $row['status']], ['status' => $status, 'rule' => $row['rule']], $note);

        return ['id' => $id, 'status' => $status];
    }
}

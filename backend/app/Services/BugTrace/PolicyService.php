<?php

namespace App\Services\BugTrace;

use App\Libraries\BugTrace\Bt;
use App\Libraries\Trust\TokenException;

/**
 * Effective reporting policy (§18.9, FR-BT-SDK-10, FR-BT-PRV-01..03).
 *
 * Precedence: the `bugtrace` claim of a valid Tenant Policy Token → the
 * Update Center mirror row in bt_tenant_policies for the TPT's tenant → the
 * edition default (FREE → CRASHES_ONLY, BUSINESS/ENTERPRISE/hosted →
 * CRASHES_AND_ERRORS). Requests without (or with an invalid) TPT get the
 * anonymous default and no tenant attribution.
 */
class PolicyService
{
    public const POLICIES = ['OFF', 'CRASHES_ONLY', 'CRASHES_AND_ERRORS'];

    /** Default for clients that present no TPT (dev builds, public site). */
    public const ANONYMOUS_DEFAULT = 'CRASHES_AND_ERRORS';

    /** Client cache lifetime for GET /bugtrace/policy (§18.12: 24 h). */
    public const CLIENT_TTL = 86400;

    /**
     * Resolve the request context from the TPT header/field.
     *
     * @return array{tenant_ref: ?string, installation_id: ?string, edition: ?string, device_ref: ?string, tpt_valid: bool, tpt_error: ?string, policy: array<string, mixed>}
     */
    public function context(?string $tpt): array
    {
        $ctx = ['tenant_ref' => null, 'installation_id' => null, 'edition' => null, 'device_ref' => null, 'tpt_valid' => false, 'tpt_error' => null];
        $claim = null;
        if ($tpt !== null && trim($tpt) !== '') {
            try {
                $payload                = service('trustChain')->verifyTpt(trim($tpt));
                $ctx['tpt_valid']       = true;
                $ctx['tenant_ref']      = isset($payload['tenant_id']) ? (string) $payload['tenant_id'] : null;
                $ctx['installation_id'] = isset($payload['_installation_id']) ? (string) $payload['_installation_id'] : null;
                $ctx['edition']         = isset($payload['_edition']) ? strtoupper((string) $payload['_edition']) : (isset($payload['edition']) ? strtoupper((string) $payload['edition']) : null);
                $ctx['device_ref']      = isset($payload['device_ref']) ? (string) $payload['device_ref'] : null;
                $claim                  = is_array($payload['bugtrace'] ?? null) ? $payload['bugtrace'] : null;
            } catch (TokenException $e) {
                $ctx['tpt_error'] = $e->getMessage();
            } catch (\Throwable $e) {
                $ctx['tpt_error'] = 'Tenant policy token could not be verified';
            }
        }
        $ctx['policy'] = $this->effective($ctx['tenant_ref'], $ctx['edition'], $claim, $ctx['tpt_valid']);

        return $ctx;
    }

    /**
     * @param array<string, mixed>|null $claim
     *
     * @return array{policy: string, user_reports: bool, screenshots: bool, logs: bool, server_reporting: bool, user_opt_out_allowed: bool, source: string}
     */
    public function effective(?string $tenantRef, ?string $edition, ?array $claim, bool $hasTpt = true): array
    {
        $row = $tenantRef !== null ? Bt::db()->table('bt_tenant_policies')->where('tenant_ref', $tenantRef)->get()->getRowArray() : null;
        if ($claim !== null && in_array(strtoupper((string) ($claim['policy'] ?? '')), self::POLICIES, true)) {
            $p = [
                'policy'           => strtoupper((string) $claim['policy']),
                'user_reports'     => (bool) ($claim['user_reports'] ?? true),
                'screenshots'      => (bool) ($claim['screenshots'] ?? true),
                'logs'             => (bool) ($claim['logs'] ?? true),
                'server_reporting' => (bool) ($claim['server_reporting'] ?? true),
                'source'           => 'tpt',
            ];
        } elseif ($row) {
            $p = [
                'policy'           => (string) $row['policy'],
                'user_reports'     => (bool) $row['user_reports'],
                'screenshots'      => (bool) $row['screenshots'],
                'logs'             => (bool) $row['logs'],
                'server_reporting' => (bool) $row['server_reporting'],
                'source'           => 'tenant_policy',
            ];
        } else {
            $policy = ! $hasTpt ? self::ANONYMOUS_DEFAULT : ($edition === 'FREE' ? 'CRASHES_ONLY' : 'CRASHES_AND_ERRORS');
            $p      = ['policy' => $policy, 'user_reports' => true, 'screenshots' => true, 'logs' => true, 'server_reporting' => true, 'source' => $hasTpt ? 'edition_default' : 'default'];
        }
        $p['user_opt_out_allowed'] = ! ($row && (int) ($row['required_by_contract'] ?? 0) === 1) && ! (bool) ($claim['required_by_contract'] ?? false);

        return $p;
    }

    /** May an automatic event of this type be ingested under the policy? */
    public static function allows(array $policy, string $type, string $projectPlatform): bool
    {
        if ($projectPlatform === 'server' && ! $policy['server_reporting']) {
            return false;
        }

        return match ($policy['policy']) {
            'OFF'          => false,
            'CRASHES_ONLY' => in_array($type, ['crash', 'hang'], true),
            default        => true,
        };
    }

    // ---------------------------------------------------------------- admin

    /** @return array<string, mixed> */
    public static function formatRow(array $r, ?string $tenantName = null): array
    {
        return [
            'tenant_ref'           => $r['tenant_ref'],
            'tenant_name'          => $tenantName,
            'policy'               => $r['policy'],
            'user_reports'         => (bool) $r['user_reports'],
            'screenshots'          => (bool) $r['screenshots'],
            'logs'                 => (bool) $r['logs'],
            'server_reporting'     => (bool) $r['server_reporting'],
            'required_by_contract' => (bool) $r['required_by_contract'],
            'updated_by'           => $r['updated_by'],
            'updated_at'           => Bt::iso($r['updated_at']),
        ];
    }

    /** @return array<string, mixed> */
    public function list(): array
    {
        $db    = Bt::db();
        $rows  = $db->table('bt_tenant_policies')->orderBy('tenant_ref')->get()->getResultArray();
        $names = array_column($db->table('tenants')->select('tenant_ref, name')->get()->getResultArray(), 'name', 'tenant_ref');
        $data  = array_map(static fn ($r) => self::formatRow($r, $names[$r['tenant_ref']] ?? null), $rows);
        // Tenants without an explicit row inherit the default.
        foreach ($names as $ref => $name) {
            if (! in_array($ref, array_column($rows, 'tenant_ref'), true)) {
                $data[] = ['tenant_ref' => $ref, 'tenant_name' => $name, 'policy' => null, 'user_reports' => null, 'screenshots' => null, 'logs' => null, 'server_reporting' => null, 'required_by_contract' => false, 'updated_by' => null, 'updated_at' => null, 'inherits_default' => true];
            }
        }

        return [
            'data'     => $data,
            'total'    => count($data),
            'defaults' => [
                'anonymous'   => self::ANONYMOUS_DEFAULT,
                'hosted'      => 'CRASHES_AND_ERRORS',
                'self_hosted' => ['FREE' => 'CRASHES_ONLY', 'BUSINESS' => 'CRASHES_AND_ERRORS', 'ENTERPRISE' => 'CRASHES_AND_ERRORS'],
                'air_gapped'  => 'OFF',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{before: ?array, after: array}
     */
    public function put(string $tenantRef, array $body, ?string $actor): array
    {
        $policy = strtoupper((string) ($body['policy'] ?? ''));
        if (! in_array($policy, self::POLICIES, true)) {
            throw \App\Exceptions\ApiException::validation(['policy' => 'Must be one of ' . implode(', ', self::POLICIES)]);
        }
        if (! preg_match('/^[A-Za-z0-9_:-]{3,64}$/', $tenantRef)) {
            throw \App\Exceptions\ApiException::validation(['tenant_ref' => 'Invalid tenant reference']);
        }
        $db     = Bt::db();
        $before = $db->table('bt_tenant_policies')->where('tenant_ref', $tenantRef)->get()->getRowArray();
        $row    = [
            'policy'               => $policy,
            'user_reports'         => (int) (bool) ($body['user_reports'] ?? $before['user_reports'] ?? true),
            'screenshots'          => (int) (bool) ($body['screenshots'] ?? $before['screenshots'] ?? true),
            'logs'                 => (int) (bool) ($body['logs'] ?? $before['logs'] ?? true),
            'server_reporting'     => (int) (bool) ($body['server_reporting'] ?? $before['server_reporting'] ?? true),
            'required_by_contract' => (int) (bool) ($body['required_by_contract'] ?? $before['required_by_contract'] ?? false),
            'updated_by'           => $actor,
            'updated_at'           => Bt::now(),
        ];
        if ($before) {
            $db->table('bt_tenant_policies')->where('id', $before['id'])->update($row);
        } else {
            $db->table('bt_tenant_policies')->insert($row + ['tenant_ref' => $tenantRef, 'created_at' => Bt::now()]);
        }
        $after = $db->table('bt_tenant_policies')->where('tenant_ref', $tenantRef)->get()->getRowArray();

        return ['before' => $before ? self::formatRow($before) : null, 'after' => self::formatRow($after)];
    }
}

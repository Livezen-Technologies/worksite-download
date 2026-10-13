<?php

namespace App\Services;

use App\Exceptions\ApiException;

/**
 * The entitlement catalog (IMPL-LIC-01 §5): keys, each edition's values, and per-licence
 * add-ons. A licence's entitlements are its edition's values with its add-ons applied; that
 * map is what the server receives, signed, and the only thing it grants from.
 */
final class EntitlementCatalogService
{
    public const KINDS = ['bool', 'limit', 'value'];

    public const CATEGORIES = ['apps', 'limits', 'updates', 'services', 'support'];

    /** @return list<array<string, mixed>> */
    public function catalog(): array
    {
        $rows = db_connect()->table('entitlements')->orderBy('sort_order')->orderBy('key')->get()->getResultArray();

        return array_map(static fn ($r) => [
            'key' => $r['key'], 'label' => $r['label'], 'kind' => $r['kind'], 'category' => $r['category'],
            'module' => $r['module_slug'], 'description' => $r['description'],
        ], $rows);
    }

    /** @return array<string, mixed> key => value for one edition */
    public function editionValues(string $edition): array
    {
        $out = [];
        foreach (db_connect()->table('edition_entitlements')->where('edition_code', strtoupper($edition))->get()->getResultArray() as $r) {
            $out[$r['entitlement_key']] = json_decode((string) $r['value'], true);
        }

        return $out;
    }

    /** Catalog + every edition's values, for the staff matrix and the public catalog. */
    public function matrix(): array
    {
        $editions = [];
        foreach (LicenseService::EDITIONS as $e) {
            $editions[$e] = $this->editionValues($e);
        }

        return ['entitlements' => $this->catalog(), 'editions' => $editions];
    }

    /** @return array<string, mixed> the licence's add-ons / overrides */
    public function licenseAddons(int $licenseId): array
    {
        $out = [];
        foreach (db_connect()->table('license_entitlements')->where('license_id', $licenseId)->get()->getResultArray() as $r) {
            $out[$r['entitlement_key']] = json_decode((string) $r['value'], true);
        }

        return $out;
    }

    /** Edition values with the licence's add-ons applied; keys not in the catalog are dropped. */
    public function resolve(array $licence): array
    {
        $known = array_flip(array_column($this->catalog(), 'key'));
        $map   = array_intersect_key($this->editionValues((string) $licence['edition']) + array_fill_keys(array_keys($known), false), $known);
        foreach ($this->licenseAddons((int) $licence['id']) as $k => $v) {
            if (isset($known[$k])) {
                $map[$k] = $v;
            }
        }
        ksort($map);

        return $map;
    }

    /** Module slugs a resolved map enables (`<module>.enabled` = true). */
    public static function modules(array $resolved): array
    {
        $out = [];
        foreach ($resolved as $k => $v) {
            if ($v === true && str_ends_with($k, '.enabled') && substr_count($k, '.') === 1) {
                $out[] = substr($k, 0, -8);
            }
        }
        sort($out);

        return $out;
    }

    // ---- Staff -------------------------------------------------------------------

    public function define(array $in): array
    {
        $key = strtolower(trim((string) ($in['key'] ?? '')));
        $e   = [];
        if (! preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $key)) {
            $e['key'] = 'Dotted lowercase, e.g. repairmate.enabled or core.users.max';
        }
        $kind = (string) ($in['kind'] ?? 'bool');
        if (! in_array($kind, self::KINDS, true)) {
            $e['kind'] = 'bool, limit or value';
        }
        $cat = (string) ($in['category'] ?? 'apps');
        if (! in_array($cat, self::CATEGORIES, true)) {
            $e['category'] = implode(', ', self::CATEGORIES);
        }
        $label = trim((string) ($in['label'] ?? ''));
        if ($label === '') {
            $e['label'] = 'A label is required';
        }
        if ($e !== []) {
            throw ApiException::validation($e);
        }
        $db = db_connect();
        if ($db->table('entitlements')->where('key', $key)->countAllResults() > 0) {
            throw ApiException::validation(['key' => 'That key already exists']);
        }
        $now = Catalog::now();
        $db->table('entitlements')->insert([
            'key' => $key, 'label' => substr($label, 0, 191), 'kind' => $kind, 'category' => $cat,
            'module_slug' => str_ends_with($key, '.enabled') && substr_count($key, '.') === 1 ? substr($key, 0, -8) : null,
            'description' => trim((string) ($in['description'] ?? '')) ?: null,
            'sort_order' => (int) ($db->table('entitlements')->selectMax('sort_order')->get()->getRow('sort_order') ?? 0) + 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach (LicenseService::EDITIONS as $ed) {
            $db->table('edition_entitlements')->insert(['edition_code' => $ed, 'entitlement_key' => $key, 'value' => json_encode($kind === 'bool' ? false : null), 'created_at' => $now, 'updated_at' => $now]);
        }
        service('audit')->record('entitlement.defined', 'entitlement', $key, null, ['kind' => $kind, 'category' => $cat, 'label' => $label]);

        return $this->matrix();
    }

    /** PUT one edition's values: {values: {key: value}}. */
    public function setEditionValues(string $edition, array $values): array
    {
        $edition = strtoupper($edition);
        if (! in_array($edition, LicenseService::EDITIONS, true)) {
            throw ApiException::validation(['edition' => 'FREE, BUSINESS or ENTERPRISE']);
        }
        $kinds  = array_column($this->catalog(), 'kind', 'key');
        $before = $this->editionValues($edition);
        $db     = db_connect();
        $now    = Catalog::now();
        $e      = [];
        foreach ($values as $k => $v) {
            if (! isset($kinds[$k])) {
                $e[$k] = 'Not in the catalog';

                continue;
            }
            $v = self::coerce($kinds[$k], $v, $k, $e);
            if (isset($e[$k])) {
                continue;
            }
            $set = ['value' => json_encode($v), 'updated_at' => $now];
            if ($db->table('edition_entitlements')->where('edition_code', $edition)->where('entitlement_key', $k)->countAllResults()) {
                $db->table('edition_entitlements')->where('edition_code', $edition)->where('entitlement_key', $k)->update($set);
            } else {
                $db->table('edition_entitlements')->insert($set + ['edition_code' => $edition, 'entitlement_key' => $k, 'created_at' => $now]);
            }
        }
        if ($e !== []) {
            throw ApiException::validation($e);
        }
        $after = $this->editionValues($edition);
        service('audit')->record('entitlement.edition_changed', 'edition', $edition, array_diff_assoc(array_map('json_encode', $before), array_map('json_encode', $after)) ?: null, array_diff_assoc(array_map('json_encode', $after), array_map('json_encode', $before)) ?: null);

        return $this->matrix();
    }

    /** PUT a licence's add-ons: {values: {key: value}, remove: [key]} (null = unlimited for a limit). */
    public function setLicenseAddons(array $licence, array $values, array $remove, ?string $by): array
    {
        $kinds = array_column($this->catalog(), 'kind', 'key');
        $db    = db_connect();
        $now   = Catalog::now();
        $e     = [];
        foreach ($remove as $k) {
            $db->table('license_entitlements')->where('license_id', $licence['id'])->where('entitlement_key', (string) $k)->delete();
        }
        foreach ($values as $k => $v) {
            if (! isset($kinds[$k])) {
                $e[$k] = 'Not in the catalog';

                continue;
            }
            $v = self::coerce($kinds[$k], $v, $k, $e);
            if (isset($e[$k])) {
                continue;
            }
            $exists = $db->table('license_entitlements')->where('license_id', $licence['id'])->where('entitlement_key', $k)->countAllResults() > 0;
            if ($exists) {
                $db->table('license_entitlements')->where('license_id', $licence['id'])->where('entitlement_key', $k)->update(['value' => json_encode($v), 'created_by' => $by, 'updated_at' => $now]);
            } else {
                $db->table('license_entitlements')->insert(['license_id' => $licence['id'], 'entitlement_key' => $k, 'value' => json_encode($v), 'created_by' => $by, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        if ($e !== []) {
            throw ApiException::validation($e);
        }
        service('audit')->record('license.entitlements_changed', 'license', $licence['public_id'], null, ['set' => $values, 'removed' => array_values($remove)]);

        return $this->licenseAddons((int) $licence['id']);
    }

    private static function coerce(string $kind, mixed $v, string $k, array &$e): mixed
    {
        if ($kind === 'bool') {
            if (! is_bool($v)) {
                $e[$k] = 'true or false';
            }

            return (bool) $v;
        }
        if ($kind === 'limit') {
            if ($v === null || $v === '') {
                return null; // unlimited
            }
            if (! is_numeric($v) || (int) $v < 0) {
                $e[$k] = 'A whole number, or empty for unlimited';
            }

            return (int) $v;
        }
        if (! is_scalar($v) && $v !== null) {
            $e[$k] = 'A single value';
        }

        return $v === null ? null : substr((string) $v, 0, 64);
    }
}

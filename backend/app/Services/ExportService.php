<?php

namespace App\Services;

use App\Libraries\Storage\StorageFactory;
use RuntimeException;

/**
 * Daily export of configuration, changelogs, manifests and audit logs to
 * write-once storage (§13.3, FR-UC-OPS-04). Complements the database backups:
 * these documents stay readable without a database restore.
 *
 * Objects go to `exports/YYYY-MM-DD/<name>.json.gz` on the provider named by
 * `updatecenter.exportProvider` (a bucket with S3 Object Lock / R2 bucket lock
 * in compliance mode). Keys are never overwritten: an existing object is left
 * alone and reported. Audit logs are exported incrementally after the last
 * exported id. Secrets are never exported (only references exist in the DB,
 * and settings that look secret are skipped).
 */
class ExportService
{
    /** table => columns ('*' = all) */
    private const CONFIG_TABLES = [
        'applications', 'application_platforms', 'application_channels', 'channels', 'editions', 'edition_modules',
        'minimum_version_policies', 'release_rollout_rules', 'roles', 'permissions', 'role_permissions',
        'storage_providers', 'signing_keys', 'tenants', 'tenant_webhooks',
    ];

    private const SECRET_LIKE = '/secret|password|passwd|token|private|credential|api[_-]?key/i';

    /** @return array{date:string, objects:list<array{key:string,rows:int,sha256:string,status:string}>} */
    public function run(?string $date = null): array
    {
        $date = $date ?? gmdate('Y-m-d');
        $db   = db_connect();
        $docs = [];

        $config = [];
        foreach (self::CONFIG_TABLES as $t) {
            if (! $db->tableExists($t)) {
                continue;
            }
            $config[$t] = array_map(fn ($r) => $this->redact($t, $r), $db->table($t)->get()->getResultArray());
        }
        $settings = [];
        foreach ($db->tableExists('settings') ? $db->table('settings')->get()->getResultArray() : [] as $s) {
            if (! preg_match(self::SECRET_LIKE, (string) $s['key'])) {
                $settings[] = $s;
            }
        }
        $config['settings'] = $settings;
        $docs['configuration'] = $config;

        $docs['changelogs'] = [
            'release_changelogs'         => $db->tableExists('release_changelogs') ? $db->table('release_changelogs')->get()->getResultArray() : [],
            'release_changelog_versions' => $db->tableExists('release_changelog_versions') ? $db->table('release_changelog_versions')->get()->getResultArray() : [],
        ];
        $docs['manifests'] = ['release_manifests' => $db->tableExists('release_manifests') ? $db->table('release_manifests')->get()->getResultArray() : []];

        $after = (int) (cache()->get('uc_export_audit_after') ?? $this->lastAuditFromSettings());
        $audit = $db->table('audit_logs')->where('id >', $after)->orderBy('id', 'ASC')->get()->getResultArray();
        $docs['audit_logs'] = ['after_id' => $after, 'rows' => $audit];

        $objects = [];
        $tmp     = tempnam(sys_get_temp_dir(), 'ucexp');
        try {
            foreach ($docs as $name => $doc) {
                $json = json_encode(['export' => $name, 'date' => $date, 'generated_at' => gmdate('c'), 'data' => $doc], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $gz   = gzencode($json, 9);
                file_put_contents($tmp, $gz);
                $key  = "exports/{$date}/{$name}.json.gz";
                $rows = $name === 'audit_logs' ? count($audit) : array_sum(array_map('count', $doc));
                $objects[] = ['key' => $key, 'rows' => $rows, 'sha256' => hash('sha256', $gz), 'status' => $this->putOnce($key, $tmp)];
            }
        } finally {
            @unlink($tmp);
        }

        if ($audit !== []) {
            $last = (int) end($audit)['id'];
            cache()->save('uc_export_audit_after', $last, 0);
            $this->saveSetting('export.audit_after_id', (string) $last);
        }
        $this->saveSetting('export.last_run', json_encode(['date' => $date, 'objects' => $objects]));
        service('audit')->record('ops.export', 'export', $date, null, ['objects' => count($objects), 'audit_rows' => count($audit)], null, ['type' => 'system', 'id' => null, 'label' => 'Export scheduler']);

        return ['date' => $date, 'objects' => $objects];
    }

    private function redact(string $table, array $row): array
    {
        foreach ($row as $k => $v) {
            // *_ref columns hold secret-service references (not secrets) and are kept.
            if (! str_ends_with($k, '_ref') && preg_match(self::SECRET_LIKE, $k) && $k !== 'public_key') {
                $row[$k] = '[redacted]';
            }
        }

        return $row;
    }

    private function putOnce(string $key, string $file): string
    {
        $name = (string) config('UpdateCenter')->exportProvider;
        if ($name === '') {
            $path = WRITEPATH . 'exports/' . substr($key, strlen('exports/'));
            if (is_file($path)) {
                return 'exists';
            }
            @mkdir(dirname($path), 0750, true);
            if (! copy($file, $path)) {
                throw new RuntimeException('Cannot write ' . $path);
            }
            @chmod($path, 0440);

            return 'written';
        }
        $row = db_connect()->table('storage_providers')->where('name', $name)->get()->getRowArray();
        if (! $row) {
            throw new RuntimeException("Export storage provider '{$name}' not found");
        }
        $provider = StorageFactory::fromRow($row);
        if ($provider->head($key) !== null) {
            return 'exists';
        }
        $provider->putFile($key, $file, 'application/gzip');

        return 'written';
    }

    private function lastAuditFromSettings(): int
    {
        $db = db_connect();

        return $db->tableExists('settings') ? (int) ($db->table('settings')->where('key', 'export.audit_after_id')->get()->getRow('value') ?? 0) : 0;
    }

    private function saveSetting(string $key, string $value): void
    {
        $db = db_connect();
        if (! $db->tableExists('settings')) {
            return;
        }
        $db->table('settings')->where('key', $key)->countAllResults() > 0
            ? $db->table('settings')->where('key', $key)->update(['value' => $value])
            : $db->table('settings')->insert(['key' => $key, 'value' => $value]);
    }
}

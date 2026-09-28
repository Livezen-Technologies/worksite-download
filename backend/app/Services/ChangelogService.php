<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\Markdown\SafeMarkdown;

/**
 * Categorised Markdown changelogs per release and locale (FR-UC-CHG-01..05).
 * Edits are versioned; the signed manifest references notes by digest only.
 */
class ChangelogService
{
    public const SECTIONS = [
        'new_features' => 'New Features',
        'improvements' => 'Improvements',
        'bug_fixes'    => 'Bug Fixes',
        'performance'  => 'Performance',
        'security'     => 'Security',
        'known_issues' => 'Known Issues',
        'deprecated'   => 'Deprecated',
        'removed'      => 'Removed',
    ];

    public const LOCALES = ['en', 'si', 'ta'];

    /** Parse "## New Features\n- item" Markdown into sections. */
    public static function parseMarkdown(string $markdown): array
    {
        $sections = array_fill_keys(array_keys(self::SECTIONS), []);
        $aliases  = [];
        foreach (self::SECTIONS as $k => $label) {
            $aliases[strtolower($label)] = $k;
        }
        $aliases += ['features' => 'new_features', 'new' => 'new_features', 'added' => 'new_features', 'fixed' => 'bug_fixes', 'fixes' => 'bug_fixes', 'changed' => 'improvements'];
        $current = null;
        foreach (preg_split('/\R/', $markdown) as $line) {
            if (preg_match('/^#{1,4}\s+(.+?)\s*$/', $line, $m)) {
                $current = $aliases[strtolower(trim($m[1]))] ?? null;

                continue;
            }
            if ($current !== null && preg_match('/^\s*[-*+]\s+(.+)$/', $line, $m)) {
                $sections[$current][] = trim($m[1]);
            }
        }

        return $sections;
    }

    public static function toMarkdown(array $sections): string
    {
        $out = [];
        foreach (self::SECTIONS as $k => $label) {
            if (! empty($sections[$k])) {
                $out[] = "## {$label}\n" . implode("\n", array_map(static fn ($i) => '- ' . $i, $sections[$k]));
            }
        }

        return implode("\n\n", $out);
    }

    public function get(int $releaseId, string $locale = 'en'): ?array
    {
        $db  = db_connect();
        $row = $db->table('release_changelogs')->where('release_id', $releaseId)->where('locale', $locale)->get()->getRowArray();
        if ($row === null && $locale !== 'en') {
            $row = $db->table('release_changelogs')->where('release_id', $releaseId)->where('locale', 'en')->get()->getRowArray();
        }

        return $row;
    }

    public static function present(?array $row): array
    {
        $sections = [];
        foreach (array_keys(self::SECTIONS) as $k) {
            $sections[$k] = $row ? (json_decode((string) ($row[$k] ?? '[]'), true) ?: []) : [];
        }
        $md = $row['markdown_content'] ?? '';
        if (($md === '' || $md === null) && $row) {
            $md = self::toMarkdown($sections);
        }

        return [
            'locale'     => $row['locale'] ?? 'en',
            'summary'    => $row['summary'] ?? null,
            'highlights' => $row ? (json_decode((string) $row['highlights'], true) ?: []) : [],
            'sections'   => $sections,
            'markdown'   => (string) $md,
            'html'       => SafeMarkdown::toHtml((string) $md),
            'version'    => (int) ($row['version'] ?? 0),
            'updated_at' => Catalog::iso($row['updated_at'] ?? null),
        ];
    }

    /** SHA-256 over the canonical English notes (manifest `notes_digest`). */
    public function digest(int $releaseId): string
    {
        $p = self::present($this->get($releaseId));
        unset($p['html'], $p['version'], $p['updated_at']);

        return hash('sha256', \App\Libraries\CanonicalJson::encode($p));
    }

    public function save(array $release, array $input, ?int $userId): array
    {
        $locale = (string) ($input['locale'] ?? 'en');
        if (! in_array($locale, self::LOCALES, true)) {
            throw ApiException::validation(['locale' => 'Supported locales: ' . implode(', ', self::LOCALES)]);
        }
        $summary = trim((string) ($input['summary'] ?? ''));
        if (mb_strlen($summary) > 280) {
            throw ApiException::validation(['summary' => 'Summary must be at most 280 characters']);
        }
        $highlights = array_values(array_filter(array_map('trim', (array) ($input['highlights'] ?? [])), 'strlen'));
        if (count($highlights) > 5) {
            throw ApiException::validation(['highlights' => 'At most 5 highlights']);
        }
        $sections = $input['sections'] ?? null;
        $markdown = isset($input['markdown']) ? (string) $input['markdown'] : null;
        if ($sections === null && $markdown !== null) {
            $sections = self::parseMarkdown($markdown);
        }
        $sections = (array) $sections;
        $clean    = [];
        foreach (array_keys(self::SECTIONS) as $k) {
            $clean[$k] = array_values(array_filter(array_map(static fn ($s) => trim((string) $s), (array) ($sections[$k] ?? [])), 'strlen'));
        }
        $markdown ??= self::toMarkdown($clean);

        $db       = db_connect();
        $existing = $db->table('release_changelogs')->where('release_id', $release['id'])->where('locale', $locale)->get()->getRowArray();
        $data     = ['summary' => $summary === '' ? null : $summary, 'highlights' => json_encode($highlights), 'markdown_content' => $markdown, 'updated_by' => $userId, 'updated_at' => Catalog::now()];
        foreach ($clean as $k => $v) {
            $data[$k] = json_encode($v);
        }
        if ($existing) {
            $data['version'] = (int) $existing['version'] + 1;
            $db->table('release_changelogs')->where('id', $existing['id'])->update($data);
        } else {
            $data += ['release_id' => $release['id'], 'locale' => $locale, 'version' => 1, 'created_at' => Catalog::now()];
            $db->table('release_changelogs')->insert($data);
        }
        $row = $db->table('release_changelogs')->where('release_id', $release['id'])->where('locale', $locale)->get()->getRowArray();
        $db->table('release_changelog_versions')->insert(['release_id' => $release['id'], 'locale' => $locale, 'version' => $row['version'], 'snapshot' => json_encode(self::present($row)), 'updated_by' => $userId, 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);

        $after = self::present($row);
        unset($after['html']);
        service('audit')->record('changelog.changed', 'release', $release['public_id'], $existing ? ['version' => (int) $existing['version'], 'summary' => $existing['summary']] : null, ['version' => $after['version'], 'locale' => $locale, 'summary' => $after['summary']]);

        return self::present($row);
    }

    /** Checklist rule: summary + at least one category. */
    public function isComplete(int $releaseId): bool
    {
        $p = self::present($this->get($releaseId));
        if (($p['summary'] ?? '') === '') {
            return false;
        }
        foreach ($p['sections'] as $items) {
            if ($items !== []) {
                return true;
            }
        }

        return false;
    }
}

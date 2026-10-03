<?php

namespace App\Services\BugTrace;

use App\Exceptions\ApiException;
use App\Libraries\BugTrace\BreakpadSymbols;
use App\Libraries\BugTrace\Bt;
use App\Libraries\BugTrace\SourceMap;
use App\Libraries\BugTrace\Symbolicator;
use App\Libraries\BugTrace\Vault;
use App\Services\AuthContext;

/**
 * Debug symbols uploaded by CI per release (WF-C37, FR-BT-ING-04, AC-C75):
 * source maps, Hermes maps, dSYM, PDB, Breakpad and R8 mappings. Stored
 * encrypted under bugtrace/symbols/ (a prefix the dev CDN also refuses) and
 * never served to anyone — there is no download endpoint.
 */
class SymbolService
{
    public const KINDS = ['sourcemap', 'hermes', 'dsym', 'pdb', 'breakpad', 'r8'];

    /**
     * @param array<string, mixed> $f form fields: release_id|release+app, project, kind, platform, debug_id, artifact
     *
     * @return array<string, mixed>
     */
    public function upload(string $path, string $fileName, array $f, AuthContext $auth): array
    {
        $db   = Bt::db();
        $kind = strtolower((string) ($f['kind'] ?? (str_ends_with($fileName, '.map') ? 'sourcemap' : '')));
        if (! in_array($kind, self::KINDS, true)) {
            throw ApiException::validation(['kind' => 'One of ' . implode(', ', self::KINDS)]);
        }
        $release = null;
        if (! empty($f['release_id'])) {
            $rid     = (string) $f['release_id'];
            $release = $db->table('releases')->where(str_starts_with($rid, 'rel_') ? 'public_id' : 'id', $rid)->get()->getRowArray();
            if (! $release) {
                throw ApiException::validation(['release_id' => 'Unknown release']);
            }
        }
        $project = ! empty($f['project']) ? ProjectService::resolve((string) $f['project']) : null;
        if (! empty($f['project']) && $project === null) {
            throw ApiException::validation(['project' => 'Unknown project']);
        }
        if ($project === null && $release !== null) {
            $platform = (string) ($f['platform'] ?? ($kind === 'sourcemap' ? 'web' : ''));
            $b        = $db->table('bt_projects')->where('application_id', $release['application_id']);
            if ($platform !== '' && in_array($platform, ['web', 'desktop', 'mobile', 'server'], true)) {
                $b->where('platform', $platform);
            }
            $project = $b->orderBy('id')->get()->getRowArray() ?: null;
        }
        $releaseStr = $release !== null ? $release['version'] . ((int) $release['build_number'] > 0 ? '+' . $release['build_number'] : '') : trim((string) ($f['release'] ?? ''));
        if ($releaseStr === '') {
            throw ApiException::validation(['release_id' => 'release_id (or release) is required']);
        }
        $size = filesize($path) ?: 0;
        if ($size === 0 || $size > 512 * 1024 * 1024) {
            throw new ApiException('UPLOAD_REJECTED', 'Symbol file is empty or too large', 422, ['sub_code' => 'SIZE']);
        }
        $artifact = isset($f['artifact']) ? (string) $f['artifact'] : null;
        $debugId  = isset($f['debug_id']) ? strtolower((string) $f['debug_id']) : null;
        if (in_array($kind, ['sourcemap', 'hermes'], true)) {
            try {
                $map = SourceMap::parse((string) file_get_contents($path));
            } catch (\Throwable $e) {
                throw new ApiException('UPLOAD_REJECTED', 'Not a valid source map: ' . $e->getMessage(), 422, ['sub_code' => 'SOURCEMAP_INVALID']);
            }
            $artifact ??= $map->file ?: preg_replace('/\.map$/', '', basename($fileName));
            $debugId  ??= $map->debugId !== null ? strtolower($map->debugId) : null;
        }
        if ($kind === 'breakpad') {
            // MODULE <os> <arch> <debug_id> <name> — the debug id is the lookup key for native frames.
            $head = (string) fgets(fopen($path, 'rb') ?: throw new ApiException('UPLOAD_REJECTED', 'Unreadable', 422));
            if (! preg_match('/^MODULE \S+ \S+ ([0-9A-Fa-f]+) (.+)$/', trim($head), $mm)) {
                throw new ApiException('UPLOAD_REJECTED', 'Not a Breakpad symbol file (convert PDB / dSYM / ELF with dump_syms)', 422, ['sub_code' => 'SYMBOLS_INVALID']);
            }
            $debugId  = BreakpadSymbols::normalizeDebugId($mm[1]);
            $artifact = preg_replace('/\.(pdb|dbg|debug)$/i', '', trim($mm[2]));
        } elseif ($kind === 'r8' && ! preg_match('/^\S+ -> \S+:$/m', (string) file_get_contents($path, false, null, 0, 1 << 20))) {
            throw new ApiException('UPLOAD_REJECTED', 'Not an R8 / ProGuard mapping file', 422, ['sub_code' => 'SYMBOLS_INVALID']);
        }
        $sha = hash_file('sha256', $path);
        $key = 'bugtrace/symbols/' . ($release['id'] ?? preg_replace('/[^A-Za-z0-9.+_-]/', '_', $releaseStr)) . '/' . $kind . '/' . substr($sha, 0, 16) . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($fileName));
        Vault::putFile($key, $path);
        $db->table('bt_symbols')->insert([
            'release_id'  => $release['id'] ?? null,
            'release'     => mb_substr($releaseStr, 0, 80),
            'project_id'  => $project['id'] ?? null,
            'platform'    => mb_substr((string) ($f['platform'] ?? ($project['platform'] ?? 'web')), 0, 16),
            'kind'        => $kind,
            'debug_id'    => $debugId,
            'file_name'   => mb_substr(basename($fileName), 0, 255),
            'artifact'    => $artifact !== null ? mb_substr($artifact, 0, 255) : null,
            'storage_key' => $key,
            'size'        => $size,
            'sha256'      => $sha,
            'uploaded_by' => $auth->actorLabel(),
            'uploaded_at' => Bt::now(),
            'created_at'  => Bt::now(),
            'updated_at'  => Bt::now(),
        ]);
        $id = (int) $db->insertID();
        service('audit')->record('bugtrace.symbols.uploaded', 'bt_symbol', 'sym_' . $id, null, ['release' => $releaseStr, 'kind' => $kind, 'file' => basename($fileName), 'sha256' => $sha]);
        Symbolicator::flushCache();
        \App\Libraries\Queue::push(\App\Jobs\BugTraceResymbolicateJob::class, ['release_id' => $release['id'] ?? null, 'release' => $releaseStr], 'bugtrace');

        return self::format($db->table('bt_symbols')->where('id', $id)->get()->getRowArray());
    }

    /** @return array<string, mixed> metadata only — never content or storage keys */
    public static function format(array $s): array
    {
        $rel = $s['release_id'] ? Bt::db()->table('releases')->select('public_id')->where('id', $s['release_id'])->get()->getRow('public_id') : null;

        return [
            'id'          => 'sym_' . $s['id'],
            'release'     => $s['release'],
            'release_id'  => $rel,
            'kind'        => $s['kind'],
            'platform'    => $s['platform'],
            'artifact'    => $s['artifact'],
            'debug_id'    => $s['debug_id'],
            'file_name'   => $s['file_name'],
            'size'        => (int) $s['size'],
            'sha256'      => $s['sha256'],
            'uploaded_by' => $s['uploaded_by'],
            'uploaded_at' => Bt::iso($s['uploaded_at']),
        ];
    }

    /** @return array{data: list<array<string, mixed>>, total: int, missing: bool} */
    public function list(array $q): array
    {
        $db = Bt::db();
        $b  = $db->table('bt_symbols');
        if (! empty($q['release_id'])) {
            $rid = (string) $q['release_id'];
            $b->where('release_id', (int) ($db->table('releases')->select('id')->where(str_starts_with($rid, 'rel_') ? 'public_id' : 'id', $rid)->get()->getRow('id') ?? 0));
        }
        if (! empty($q['release'])) {
            $b->where('release', (string) $q['release']);
        }
        $rows = $b->orderBy('id', 'DESC')->limit(500)->get()->getResultArray();

        return ['data' => array_map([self::class, 'format'], $rows), 'total' => count($rows), 'missing' => $rows === []];
    }

    /**
     * Re-symbolicate recent events of a release whose maps arrived late
     * (status missing/partial). Grouping is not changed.
     */
    public function resymbolicate(?int $releaseId, ?string $release, int $limit = 1000): int
    {
        if ($releaseId === null && ($release === null || $release === '')) {
            return 0;
        }
        $db = Bt::db();
        $b  = $db->table('bt_events')->whereIn('symbolication', ['missing', 'partial', 'pending']);
        $b->groupStart();
        if ($releaseId !== null) {
            $b->where('release_id', $releaseId);
        }
        if ($release !== null && $release !== '') {
            $b->orWhere('release', $release);
        }
        $b->groupEnd();
        $n = 0;
        foreach ($b->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray() as $e) {
            $project = EventProcessor::project((int) $e['project_id']);
            $ex      = Bt::decode($e['exception'], []);
            $event   = ['event_id' => $e['event_id'], 'type' => $e['type'], 'release' => $e['release'], 'exception' => $ex['values'] ?? []];
            $res     = (new Symbolicator())->symbolicate($event, $project, $e['release_id'] !== null ? (int) $e['release_id'] : null);
            if ($res['status'] !== $e['symbolication']) {
                $db->table('bt_events')->where('id', $e['id'])->update(['symbolicated_frames' => Bt::json($res['frames']), 'symbolication' => $res['status']]);
                $n++;
            }
        }

        return $n;
    }

    /** For the release checklist (§7.4): does the release have symbols uploaded? */
    public static function hasSymbols(int $releaseId): bool
    {
        return Bt::db()->table('bt_symbols')->where('release_id', $releaseId)->countAllResults() > 0;
    }
}

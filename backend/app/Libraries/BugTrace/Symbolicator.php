<?php

namespace App\Libraries\BugTrace;

/**
 * Symbolication of event stack traces (FR-BT-ING-04).
 *
 * - JavaScript (web, Electron renderer, React Native/Hermes JS): hidden
 *   source maps uploaded by CI (bt_symbols kind sourcemap|hermes), matched by
 *   debug id or by the minified artifact name within the event's release.
 * - Native (minidumps, dSYM, PDB, Breakpad, R8/NDK): stored and marked
 *   `pending`; a native symbolication worker (e.g. symbolicator, §16.1 Q12)
 *   can consume bt_symbols and update the event later. Documented stub.
 */
final class Symbolicator
{
    /** @var array<int, SourceMap|false> symbol id → parsed map (per process) */
    private static array $maps = [];

    /**
     * @param array<string, mixed> $event   scrubbed event
     * @param array<string, mixed> $project bt_projects row
     *
     * @return array{frames: list<array<string, mixed>>, status: string}
     */
    public function symbolicate(array $event, array $project, ?int $releaseId): array
    {
        $ex     = Fingerprinter::primaryException($event);
        $frames = is_array($ex['stacktrace']['frames'] ?? null) ? $ex['stacktrace']['frames'] : [];
        if (($ex['mechanism']['type'] ?? '') === 'minidump') {
            return ['frames' => $frames, 'status' => 'pending'];
        }
        $jsFrames = array_filter($frames, static fn ($f) => preg_match('/\.(?:m?js|cjs|bundle|hbc|jsbundle)$/i', (string) ($f['file'] ?? '')) === 1);
        if ($frames === [] || $jsFrames === []) {
            return ['frames' => $frames, 'status' => $frames === [] ? 'n/a' : (in_array($project['platform'], ['desktop', 'mobile'], true) && ($event['type'] ?? '') === 'crash' && $jsFrames === [] && self::looksNative($frames) ? 'pending' : 'n/a')];
        }

        $symbols = $this->candidateSymbols($project, $releaseId, (string) ($event['release'] ?? ''));
        $debugIds = [];
        foreach ($event['debug_meta']['images'] ?? [] as $img) {
            if (! empty($img['code_file']) && ! empty($img['debug_id'])) {
                $debugIds[basename((string) $img['code_file'])] = strtolower((string) $img['debug_id']);
            }
        }

        $out   = [];
        $total = 0;
        $done  = 0;
        foreach (array_values($frames) as $i => $f) {
            $isJs = preg_match('/\.(?:m?js|cjs|bundle|hbc|jsbundle)$/i', (string) ($f['file'] ?? '')) === 1;
            $res  = null;
            if ($isJs && isset($f['line'])) {
                $total++;
                $map = $this->mapFor($symbols, (string) $f['file'], $f['debug_id'] ?? ($debugIds[basename((string) $f['file'])] ?? null));
                if ($map !== null) {
                    $res = $map->resolve((int) $f['line'], (int) ($f['col'] ?? 1));
                }
            }
            if ($res === null) {
                $out[] = $f + ['symbolicated' => false];

                continue;
            }
            $done++;
            $src = self::cleanSource($res['source']);
            $fn  = null;
            // The caller's call-site token names the function we are in (frames: outermost → innermost).
            $caller = $i > 0 ? $out[$i - 1]['_callee_name'] ?? null : null;
            $fn     = $caller ?? (strlen((string) ($f['function'] ?? '')) > 2 ? $f['function'] : ($res['name'] ?? ($f['function'] ?? null)));
            $frame  = [
                'file'         => $src,
                'function'     => $fn,
                'line'         => $res['line'],
                'col'          => $res['column'],
                'in_app'       => ! str_contains($src, 'node_modules/') && ($f['in_app'] ?? true) !== false,
                'symbolicated' => true,
                'raw'          => array_intersect_key($f, array_flip(['file', 'function', 'line', 'col'])),
                '_callee_name' => $res['name'],
            ];
            if ($res['context'] !== null) {
                $frame['context'] = $res['context'];
            }
            $out[] = $frame;
        }
        foreach ($out as &$f) {
            unset($f['_callee_name']);
        }
        unset($f);

        $status = $total === 0 ? 'n/a' : ($done === 0 ? 'missing' : ($done < $total ? 'partial' : 'ok'));

        return ['frames' => $out, 'status' => $status];
    }

    private static function looksNative(array $frames): bool
    {
        foreach ($frames as $f) {
            if (isset($f['instruction_addr'])) {
                return true;
            }
        }

        return false;
    }

    public static function cleanSource(string $src): string
    {
        $src = preg_replace('~^(?:webpack|vite|rollup)://+[^/]*/~i', '', $src);
        $src = preg_replace('~^(?:\.\./|\./)+~', '', $src);
        $src = preg_replace('~^/?(?:@fs/)?~', '', $src);

        return $src;
    }

    /** @return list<array<string, mixed>> */
    private function candidateSymbols(array $project, ?int $releaseId, string $release): array
    {
        $b = db_connect()->table('bt_symbols')->whereIn('kind', ['sourcemap', 'hermes'])
            ->groupStart()->where('project_id', $project['id'])->orWhere('project_id', null)->groupEnd();
        $b->groupStart();
        if ($releaseId !== null) {
            $b->where('release_id', $releaseId)->orWhere('release', $release);
        } else {
            $b->where('release', $release);
        }
        $b->groupEnd();

        return $b->orderBy('id', 'DESC')->limit(500)->get()->getResultArray();
    }

    private function mapFor(array $symbols, string $file, ?string $debugId): ?SourceMap
    {
        $pick = null;
        if ($debugId !== null) {
            foreach ($symbols as $s) {
                if ($s['debug_id'] !== null && strtolower((string) $s['debug_id']) === strtolower($debugId)) {
                    $pick = $s;
                    break;
                }
            }
        }
        if ($pick === null) {
            $path = substr($file, 0, strcspn($file, '?#'));
            $path = preg_replace('~^[a-z][a-z0-9+.-]*://[^/]+~i', '', $path);
            $base = basename($path);
            $best = -1;
            foreach ($symbols as $s) {
                $artifact = (string) ($s['artifact'] ?: preg_replace('/\.map$/', '', (string) $s['file_name']));
                if ($artifact === '' || basename($artifact) !== $base) {
                    continue;
                }
                $score = str_ends_with($path, ltrim($artifact, '~/')) ? strlen($artifact) : 0;
                if ($score > $best) {
                    $best = $score;
                    $pick = $s;
                }
            }
        }
        if ($pick === null) {
            return null;
        }
        $id = (int) $pick['id'];
        if (! array_key_exists($id, self::$maps)) {
            if (count(self::$maps) > 32) {
                self::$maps = [];
            }
            try {
                self::$maps[$id] = SourceMap::parse(Vault::getBytes((string) $pick['storage_key']));
            } catch (\Throwable $e) {
                log_message('warning', 'bugtrace: cannot load source map {id}: {msg}', ['id' => $id, 'msg' => $e->getMessage()]);
                self::$maps[$id] = false;
            }
        }

        return self::$maps[$id] ?: null;
    }

    public static function flushCache(): void
    {
        self::$maps = [];
    }
}

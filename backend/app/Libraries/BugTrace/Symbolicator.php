<?php

namespace App\Libraries\BugTrace;

/**
 * Symbolication of event stack traces (FR-BT-ING-04).
 *
 * - JavaScript (web, Electron renderer, React Native/Hermes JS): hidden
 *   source maps uploaded by CI (bt_symbols kind sourcemap|hermes), matched by
 *   debug id or by the minified artifact name within the event's release.
 * - Native frames (Windows/macOS/Linux desktop, iOS, Android NDK): Breakpad
 *   .sym files (kind breakpad — CI converts PDB / dSYM / ELF with dump_syms),
 *   matched by module debug id, looked up by module offset.
 * - Minidumps: walked by `minidump-stackwalk` (updatecenter.minidumpStackwalk,
 *   read-only parser) into native frames, then symbolicated as above. Without
 *   the tool configured they stay `pending`.
 * - Android Java/Kotlin frames: R8/ProGuard mapping.txt (kind r8) of the release.
 *
 * Status: ok | partial | missing (no symbols yet — re-run when they arrive) |
 * pending (minidump not walked yet) | n/a.
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
        if (($ex['mechanism']['type'] ?? '') === 'minidump' && ! self::looksNative($frames)) {
            $walked = $this->walkMinidump($event, $project);
            if ($walked === null) {
                return ['frames' => $frames, 'status' => 'pending'];
            }
            $frames = $walked;
        }
        if (self::looksNative($frames) || self::looksJava($frames, $project)) {
            return $this->symbolicateNative($frames, $project, $releaseId, (string) ($event['release'] ?? ''));
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
            if (isset($f['instruction_addr']) || isset($f['module_offset'])) {
                return true;
            }
        }

        return false;
    }

    /** Android JVM frames: a dotted class in `module`, a method name, no JS file. */
    private static function looksJava(array $frames, array $project): bool
    {
        if (! in_array($project['platform'], ['mobile', 'android'], true)) {
            return false;
        }
        foreach ($frames as $f) {
            if (isset($f['module'], $f['function']) && str_contains((string) $f['module'], '.') && ! isset($f['file'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Native (Breakpad) and JVM (R8) frames.
     *
     * @return array{frames: list<array<string, mixed>>, status: string}
     */
    private function symbolicateNative(array $frames, array $project, ?int $releaseId, string $release): array
    {
        $db      = db_connect();
        $mapping = null;
        $out     = [];
        $total   = 0;
        $done    = 0;
        foreach (array_values($frames) as $f) {
            $offset = self::offset($f);
            if ($offset !== null) {
                $total++;
                $sym = $this->breakpadFor($project, $releaseId, $release, (string) ($f['debug_id'] ?? ''), (string) ($f['module'] ?? ''));
                $res = $sym?->lookup($offset);
                if ($res === null) {
                    $out[] = $f + ['symbolicated' => false];

                    continue;
                }
                $done++;
                $out[] = array_filter([
                    'module'       => $f['module'] ?? $sym->module,
                    'function'     => $res['function'],
                    'file'         => $res['file'],
                    'line'         => $res['line'],
                    'in_app'       => $f['in_app'] ?? true,
                    'symbolicated' => true,
                    'raw'          => array_intersect_key($f, array_flip(['module', 'debug_id', 'module_offset', 'instruction_addr'])),
                ], static fn ($v) => $v !== null);

                continue;
            }
            if (isset($f['module'], $f['function']) && str_contains((string) $f['module'], '.') && ! isset($f['file'])) {
                $total++;
                $mapping ??= $this->r8For($project, $releaseId, $release) ?? false;
                $hits = $mapping ? $mapping->retrace((string) $f['module'], (string) $f['function'], isset($f['line']) ? (int) $f['line'] : null) : [];
                if ($hits === []) {
                    $out[] = $f + ['symbolicated' => false];

                    continue;
                }
                $done++;
                // Inlined methods expand into several frames; innermost last (outermost → innermost order).
                foreach (array_reverse($hits) as $h) {
                    $out[] = ['module' => $h['class'], 'function' => $h['function'], 'line' => $h['line'], 'in_app' => $f['in_app'] ?? ! preg_match('/^(java|javax|android|androidx|kotlin|com\.android)\./', $h['class']), 'symbolicated' => true, 'raw' => array_intersect_key($f, array_flip(['module', 'function', 'line']))];
                }

                continue;
            }
            $out[] = $f;
        }

        return ['frames' => $out, 'status' => $total === 0 ? 'n/a' : ($done === 0 ? 'missing' : ($done < $total ? 'partial' : 'ok'))];
    }

    private static function offset(array $f): ?int
    {
        if (isset($f['module_offset']) && preg_match('/^(0x)?[0-9a-f]+$/i', (string) $f['module_offset'])) {
            return (int) hexdec(preg_replace('/^0x/i', '', (string) $f['module_offset']));
        }
        if (isset($f['instruction_addr'], $f['image_addr'])) {
            return (int) (hexdec(preg_replace('/^0x/i', '', (string) $f['instruction_addr'])) - hexdec(preg_replace('/^0x/i', '', (string) $f['image_addr'])));
        }

        return null;
    }

    /** @var array<string, BreakpadSymbols> */
    private static array $breakpad = [];

    private function breakpadFor(array $project, ?int $releaseId, string $release, string $debugId, string $module): ?BreakpadSymbols
    {
        $id  = BreakpadSymbols::normalizeDebugId($debugId);
        $key = $id !== '' ? 'id:' . $id : 'mod:' . $release . ':' . basename($module);
        // Misses are not cached: symbols uploaded later must be found by long-running workers.
        if (! isset(self::$breakpad[$key])) {
            $b = db_connect()->table('bt_symbols')->where('kind', 'breakpad')->groupStart()->where('project_id', $project['id'])->orWhere('project_id', null)->groupEnd();
            if ($id !== '') {
                $b->where('debug_id', $id); // debug ids are globally unique per build
            } else {
                $name = preg_replace('/\.(pdb|dbg|debug)$/i', '', basename($module));
                $b->where('artifact', $name);
                $releaseId !== null ? $b->groupStart()->where('release_id', $releaseId)->orWhere('release', $release)->groupEnd() : $b->where('release', $release);
            }
            $row = $b->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
            if (count(self::$breakpad) > 64) {
                self::$breakpad = [];
            }
            if (! $row) {
                return null;
            }
            try {
                self::$breakpad[$key] = BreakpadSymbols::parse(Vault::getBytes((string) $row['storage_key']));
            } catch (\Throwable $e) {
                log_message('warning', 'bugtrace: cannot load breakpad symbols: ' . $e->getMessage());

                return null;
            }
        }

        return self::$breakpad[$key];
    }

    /** @var array<string, R8Mapping> */
    private static array $r8 = [];

    private function r8For(array $project, ?int $releaseId, string $release): ?R8Mapping
    {
        $key = $project['id'] . ':' . ($releaseId ?? $release);
        if (! isset(self::$r8[$key])) {
            $b = db_connect()->table('bt_symbols')->where('kind', 'r8')->groupStart()->where('project_id', $project['id'])->orWhere('project_id', null)->groupEnd();
            $releaseId !== null ? $b->groupStart()->where('release_id', $releaseId)->orWhere('release', $release)->groupEnd() : $b->where('release', $release);
            $row = $b->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
            if (! $row) {
                return null;
            }
            if (count(self::$r8) > 16) {
                self::$r8 = [];
            }
            try {
                self::$r8[$key] = R8Mapping::parse(Vault::getBytes((string) $row['storage_key']));
            } catch (\Throwable $e) {
                return null;
            }
        }

        return self::$r8[$key];
    }

    /**
     * Walks the event's minidump with minidump-stackwalk (JSON output) into
     * native frames of the crashing thread, outermost → innermost.
     *
     * @return list<array<string, mixed>>|null null = cannot walk (tool missing, no dump)
     */
    private function walkMinidump(array $event, array $project): ?array
    {
        $tool = (string) config('UpdateCenter')->minidumpStackwalk;
        if ($tool === '' || ! is_executable($tool)) {
            return null;
        }
        $ids = [];
        foreach ((array) ($event['attachments'] ?? []) as $a) {
            if (($a['kind'] ?? '') === 'minidump' && ! empty($a['upload_id'])) {
                $ids[] = (string) $a['upload_id'];
            }
        }
        $q = db_connect()->table('bt_attachments')->where('kind', 'minidump')->where('project_id', $project['id']);
        if ($ids !== []) {
            $q->whereIn('public_id', $ids);
        } elseif (! empty($event['event_id'])) {
            $q->where('owner_type', 'event')->where('owner_id', (string) $event['event_id']);
        } else {
            return null;
        }
        $att = $q->get()->getRowArray();
        if (! $att) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'btdmp');
        try {
            file_put_contents($tmp, Vault::getBytes((string) $att['storage_key']));
            $json = self::run([$tool, '--json', $tmp], 60);
        } finally {
            @unlink($tmp);
        }
        $doc = $json !== null ? json_decode($json, true) : null;
        if (! is_array($doc)) {
            return null;
        }

        return self::framesFromStackwalk($doc);
    }

    /**
     * @param array<string, mixed> $doc minidump-stackwalk --json output
     *
     * @return list<array<string, mixed>>
     */
    public static function framesFromStackwalk(array $doc): array
    {
        $ids = [];
        foreach ((array) ($doc['modules'] ?? []) as $m) {
            if (! empty($m['filename'])) {
                $ids[basename(str_replace('\\', '/', (string) $m['filename']))] = (string) ($m['debug_id'] ?? '');
            }
        }
        $thread = $doc['crashing_thread'] ?? null;
        if (! is_array($thread)) {
            $n      = (int) ($doc['crash_info']['crashing_thread'] ?? 0);
            $thread = $doc['threads'][$n] ?? [];
        }
        $frames = [];
        foreach (array_slice((array) ($thread['frames'] ?? []), 0, 100) as $f) {
            $module = isset($f['module']) ? basename(str_replace('\\', '/', (string) $f['module'])) : null;
            $frame  = ['instruction_addr' => (string) ($f['offset'] ?? '')];
            if ($module !== null && isset($f['module_offset'])) {
                $frame['module']        = $module;
                $frame['module_offset'] = (string) $f['module_offset'];
                $frame['debug_id']      = $ids[$module] ?? '';
            }
            if (! empty($f['function'])) {
                $frame['function'] = (string) $f['function'];
            }
            $frames[] = $frame;
        }

        return array_reverse($frames); // stackwalk lists the crashing frame first
    }

    /** @param list<string> $cmd */
    private static function run(array $cmd, int $timeout): ?string
    {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($p)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        $out      = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $st = proc_get_status($p);
            if (! $st['running'] || microtime(true) > $deadline || strlen($out) > 64 * 1024 * 1024) {
                break;
            }
            usleep(20000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $st   = proc_get_status($p);
        if ($st['running']) {
            proc_terminate($p);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);

        return ($st['running'] || ($st['exitcode'] ?? $code) !== 0) && $out === '' ? null : $out;
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
        self::$maps     = [];
        self::$breakpad = [];
        self::$r8       = [];
    }
}

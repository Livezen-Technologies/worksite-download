<?php

namespace App\Libraries\BugTrace;

use InvalidArgumentException;

/**
 * Breakpad text symbol file (.sym) reader (FR-BT-ING-04).
 *
 * One format for every native platform: CI converts Windows PDBs, macOS/iOS
 * dSYMs, Linux ELF debug info and Android NDK .so files with `dump_syms`
 * and uploads the result as kind `breakpad`. Records used:
 *
 *   MODULE <os> <arch> <debug_id> <name>
 *   FILE <n> <path>
 *   FUNC [m] <addr> <size> <param_size> <name>
 *   <addr> <size> <line> <file n>          (line record, belongs to the FUNC above)
 *   PUBLIC [m] <addr> <param_size> <name>
 *
 * Addresses are hex module-relative offsets. Lookup is a binary search.
 */
final class BreakpadSymbols
{
    public string $os = '';
    public string $arch = '';
    public string $debugId = '';
    public string $module = '';

    /** @var array<int, string> */
    private array $files = [];

    /** @var list<array{0:int,1:int,2:string,3:list<array{0:int,1:int,2:int,3:int}>}> addr, size, name, lines (sorted by addr) */
    private array $funcs = [];

    /** @var list<array{0:int,1:string}> addr, name (sorted) */
    private array $publics = [];

    public static function parse(string $text): self
    {
        $s     = new self();
        $cur   = null;
        $lines = preg_split('/\r?\n/', $text) ?: [];
        if (! str_starts_with($lines[0] ?? '', 'MODULE ')) {
            throw new InvalidArgumentException('Not a Breakpad symbol file (missing MODULE record)');
        }
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $c = $line[0];
            if ($c >= '0' && $c <= '9' || $c >= 'a' && $c <= 'f') {
                if ($cur !== null) {
                    $p = explode(' ', $line, 4);
                    if (count($p) === 4) {
                        $s->funcs[$cur][3][] = [hexdec($p[0]), hexdec($p[1]), (int) $p[2], (int) $p[3]];
                    }
                }

                continue;
            }
            if (str_starts_with($line, 'FUNC ')) {
                $p = explode(' ', $line);
                $i = $p[1] === 'm' ? 2 : 1;
                if (count($p) < $i + 4) {
                    $cur = null;

                    continue;
                }
                $s->funcs[] = [hexdec($p[$i]), hexdec($p[$i + 1]), implode(' ', array_slice($p, $i + 3)), []];
                $cur        = count($s->funcs) - 1;
            } elseif (str_starts_with($line, 'PUBLIC ')) {
                $p = explode(' ', $line);
                $i = $p[1] === 'm' ? 2 : 1;
                if (count($p) >= $i + 3) {
                    $s->publics[] = [hexdec($p[$i]), implode(' ', array_slice($p, $i + 2))];
                }
                $cur = null;
            } elseif (str_starts_with($line, 'FILE ')) {
                $p = explode(' ', $line, 3);
                $s->files[(int) $p[1]] = $p[2] ?? '';
            } elseif (str_starts_with($line, 'MODULE ')) {
                $p          = explode(' ', $line, 5);
                $s->os      = $p[1] ?? '';
                $s->arch    = $p[2] ?? '';
                $s->debugId = strtoupper($p[3] ?? '');
                $s->module  = $p[4] ?? '';
            } else {
                $cur = null; // INFO, STACK CFI/WIN, INLINE … not needed for frame lookup
            }
        }
        usort($s->funcs, static fn ($a, $b) => $a[0] <=> $b[0]);
        usort($s->publics, static fn ($a, $b) => $a[0] <=> $b[0]);
        foreach ($s->funcs as &$f) {
            usort($f[3], static fn ($a, $b) => $a[0] <=> $b[0]);
        }

        return $s;
    }

    /**
     * Symbol for a module-relative offset.
     *
     * @return array{function: string, file: ?string, line: ?int, offset: int}|null
     */
    public function lookup(int $offset): ?array
    {
        $i = self::floor($this->funcs, $offset);
        if ($i !== null) {
            [$addr, $size, $name, $lines] = $this->funcs[$i];
            if ($offset < $addr + max(1, $size)) {
                $file = null;
                $line = null;
                $j    = self::floor($lines, $offset);
                if ($j !== null && $offset < $lines[$j][0] + max(1, $lines[$j][1])) {
                    $line = $lines[$j][2];
                    $file = $this->files[$lines[$j][3]] ?? null;
                }

                return ['function' => $name, 'file' => $file, 'line' => $line, 'offset' => $offset - $addr];
            }
        }
        $k = self::floor($this->publics, $offset);
        if ($k !== null) {
            return ['function' => $this->publics[$k][1], 'file' => null, 'line' => null, 'offset' => $offset - $this->publics[$k][0]];
        }

        return null;
    }

    /** Normalised Breakpad debug identifier: uppercase hex, no dashes (GUID + age). */
    public static function normalizeDebugId(string $id): string
    {
        return strtoupper(str_replace(['-', '{', '}'], '', trim($id)));
    }

    /**
     * Index of the last element whose [0] <= $x.
     *
     * @param list<array<int, mixed>> $rows sorted by [0]
     */
    private static function floor(array $rows, int $x): ?int
    {
        $lo  = 0;
        $hi  = count($rows) - 1;
        $ans = null;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($rows[$mid][0] <= $x) {
                $ans = $mid;
                $lo  = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $ans;
    }
}

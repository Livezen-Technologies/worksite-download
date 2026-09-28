<?php

namespace App\Libraries\BugTrace;

use InvalidArgumentException;

/**
 * Source Map revision 3 consumer (https://tc39.es/ecma426/) used to
 * symbolicate minified JavaScript frames with hidden source maps
 * (FR-BT-ING-04). Decodes the Base64 VLQ `mappings`, supports index maps
 * (`sections`), and returns original source/line/column/name plus source
 * context lines from `sourcesContent`.
 *
 * Coordinates: lookup() takes a 1-based generated line and a 1-based
 * generated column (as reported by V8/SpiderMonkey stack traces) and returns
 * a 1-based original line and 1-based column.
 */
final class SourceMap
{
    private const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /** @var array<string, int> */
    private static array $b64 = [];

    /** @var list<string> */
    public array $sources = [];

    /** @var list<?string> */
    public array $sourcesContent = [];

    /** @var list<string> */
    public array $names = [];

    public ?string $file = null;

    public ?string $debugId = null;

    /**
     * Decoded segments per generated line (0-based):
     * [genCol, srcIdx, srcLine, srcCol, nameIdx|-1] (all 0-based), sorted by genCol.
     *
     * @var array<int, list<array{0:int,1:int,2:int,3:int,4:int}>>
     */
    private array $lines = [];

    /** @var list<array{line:int, col:int, map:self}> for index maps */
    private array $sections = [];

    /** @var array<int, list<string>> */
    private array $contentLines = [];

    public static function parse(string|array $json): self
    {
        $doc = is_array($json) ? $json : json_decode(self::stripXssi($json), true);
        if (! is_array($doc) || (int) ($doc['version'] ?? 0) !== 3) {
            throw new InvalidArgumentException('Not a version 3 source map');
        }
        $map          = new self();
        $map->file    = isset($doc['file']) ? (string) $doc['file'] : null;
        $map->debugId = isset($doc['debugId']) ? (string) $doc['debugId'] : (isset($doc['debug_id']) ? (string) $doc['debug_id'] : null);

        if (isset($doc['sections']) && is_array($doc['sections'])) {
            foreach ($doc['sections'] as $sec) {
                if (! isset($sec['map']) || ! is_array($sec['map'])) {
                    continue; // "url" sections are not supported (not produced by our bundlers)
                }
                $map->sections[] = ['line' => (int) ($sec['offset']['line'] ?? 0), 'col' => (int) ($sec['offset']['column'] ?? 0), 'map' => self::parse($sec['map'])];
            }
            usort($map->sections, static fn ($a, $b) => [$a['line'], $a['col']] <=> [$b['line'], $b['col']]);

            return $map;
        }

        $root = (string) ($doc['sourceRoot'] ?? '');
        foreach ((array) ($doc['sources'] ?? []) as $src) {
            $src             = (string) $src;
            $map->sources[]  = $root !== '' && ! preg_match('~^[a-z]+:~i', $src) ? rtrim($root, '/') . '/' . $src : $src;
        }
        $map->sourcesContent = array_map(static fn ($c) => $c === null ? null : (string) $c, (array) ($doc['sourcesContent'] ?? []));
        $map->names          = array_map('strval', (array) ($doc['names'] ?? []));
        $map->decodeMappings((string) ($doc['mappings'] ?? ''));

        return $map;
    }

    private static function stripXssi(string $json): string
    {
        return preg_replace('/^\)\]\}\'[^\n]*\n/', '', ltrim($json));
    }

    /** @return list<int> */
    public static function decodeVlq(string $segment): array
    {
        if (self::$b64 === []) {
            self::$b64 = array_flip(str_split(self::B64));
        }
        $out   = [];
        $value = 0;
        $shift = 0;
        $len   = strlen($segment);
        for ($i = 0; $i < $len; $i++) {
            $ch = $segment[$i];
            if (! isset(self::$b64[$ch])) {
                throw new InvalidArgumentException("Invalid base64 VLQ character '{$ch}'");
            }
            $digit = self::$b64[$ch];
            $value += ($digit & 31) << $shift;
            if ($digit & 32) {
                $shift += 5;

                continue;
            }
            $neg   = $value & 1;
            $value >>= 1;
            $out[] = $neg ? -$value : $value;
            $value = 0;
            $shift = 0;
        }
        if ($shift !== 0) {
            throw new InvalidArgumentException('Truncated base64 VLQ');
        }

        return $out;
    }

    public static function encodeVlq(int ...$values): string
    {
        $s = '';
        foreach ($values as $v) {
            $vlq = $v < 0 ? ((-$v) << 1) | 1 : $v << 1;
            do {
                $digit = $vlq & 31;
                $vlq >>= 5;
                if ($vlq > 0) {
                    $digit |= 32;
                }
                $s .= self::B64[$digit];
            } while ($vlq > 0);
        }

        return $s;
    }

    private function decodeMappings(string $mappings): void
    {
        $srcIdx  = 0;
        $srcLine = 0;
        $srcCol  = 0;
        $nameIdx = 0;
        foreach (explode(';', $mappings) as $lineNo => $line) {
            if ($line === '') {
                continue;
            }
            $genCol = 0;
            $segs   = [];
            foreach (explode(',', $line) as $seg) {
                if ($seg === '') {
                    continue;
                }
                $v = self::decodeVlq($seg);
                $genCol += $v[0];
                if (count($v) >= 4) {
                    $srcIdx  += $v[1];
                    $srcLine += $v[2];
                    $srcCol  += $v[3];
                    $name    = -1;
                    if (count($v) >= 5) {
                        $nameIdx += $v[4];
                        $name    = $nameIdx;
                    }
                    $segs[] = [$genCol, $srcIdx, $srcLine, $srcCol, $name];
                } else {
                    $segs[] = [$genCol, -1, -1, -1, -1];
                }
            }
            usort($segs, static fn ($a, $b) => $a[0] <=> $b[0]);
            $this->lines[$lineNo] = $segs;
        }
    }

    /**
     * @param int $line   1-based generated line
     * @param int $column 1-based generated column
     *
     * @return array{source: string, line: int, column: int, name: ?string, source_index: int}|null
     */
    public function lookup(int $line, int $column): ?array
    {
        if ($this->sections !== []) {
            $g = [$line - 1, max(0, $column - 1)];
            $hit = null;
            foreach ($this->sections as $sec) {
                if ([$sec['line'], $sec['col']] <= $g) {
                    $hit = $sec;
                }
            }
            if ($hit === null) {
                return null;
            }
            $l = $g[0] - $hit['line'];
            $c = $l === 0 ? $g[1] - $hit['col'] : $g[1];

            return $hit['map']->lookup($l + 1, $c + 1);
        }

        $segs = $this->lines[$line - 1] ?? null;
        if ($segs === null || $segs === []) {
            return null;
        }
        $col = max(0, $column - 1);
        // Greatest segment with genCol <= col (binary search).
        $lo  = 0;
        $hi  = count($segs) - 1;
        $idx = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($segs[$mid][0] <= $col) {
                $idx = $mid;
                $lo  = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        if ($idx < 0) {
            return null;
        }
        $s = $segs[$idx];
        if ($s[1] < 0 || ! isset($this->sources[$s[1]])) {
            return null;
        }

        return [
            'source'       => $this->sources[$s[1]],
            'line'         => $s[2] + 1,
            'column'       => $s[3] + 1,
            'name'         => $s[4] >= 0 ? ($this->names[$s[4]] ?? null) : null,
            'source_index' => $s[1],
        ];
    }

    /**
     * Source context for an original position.
     *
     * @return array{pre: list<string>, line: string, post: list<string>}|null
     */
    public function context(int $sourceIndex, int $line, int $around = 3): ?array
    {
        $content = $this->sourcesContent[$sourceIndex] ?? null;
        if ($content === null) {
            return null;
        }
        $lines = $this->contentLines[$sourceIndex] ??= preg_split('/\r\n|\r|\n/', $content);
        $i     = $line - 1;
        if (! isset($lines[$i])) {
            return null;
        }
        $clip = static fn (string $l) => mb_substr($l, 0, 300);

        return [
            'pre'  => array_map($clip, array_slice($lines, max(0, $i - $around), min($around, $i))),
            'line' => $clip($lines[$i]),
            'post' => array_map($clip, array_slice($lines, $i + 1, $around)),
        ];
    }

    /** Same lookup for index maps: context must come from the section's map. */
    public function resolve(int $line, int $column, int $around = 3): ?array
    {
        if ($this->sections !== []) {
            $g   = [$line - 1, max(0, $column - 1)];
            $hit = null;
            foreach ($this->sections as $sec) {
                if ([$sec['line'], $sec['col']] <= $g) {
                    $hit = $sec;
                }
            }
            if ($hit === null) {
                return null;
            }
            $l = $g[0] - $hit['line'];
            $c = $l === 0 ? $g[1] - $hit['col'] : $g[1];

            return $hit['map']->resolve($l + 1, $c + 1, $around);
        }
        $pos = $this->lookup($line, $column);
        if ($pos === null) {
            return null;
        }
        $pos['context'] = $this->context($pos['source_index'], $pos['line'], $around);

        return $pos;
    }
}

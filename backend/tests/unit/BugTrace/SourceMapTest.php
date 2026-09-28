<?php

namespace Tests\Unit\BugTrace;

use App\Libraries\BugTrace\SourceMap;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Source Map v3 VLQ decoding and position lookup (FR-BT-ING-04, AC-C59).
 */
final class SourceMapTest extends CIUnitTestCase
{
    /** A realistic hidden map for assets/mail-8c1.js (one long minified line). */
    public static function mailMap(): array
    {
        $src = [];
        for ($i = 1; $i <= 150; $i++) {
            $src[] = "// line {$i}";
        }
        $src[139] = 'const current = folders.find((f) => f.id === activeId);';
        $src[140] = 'const unread = counts[current.id] ?? 0;';
        $src[141] = 'const parent = byId[current.parent].folderId;';
        $src[142] = 'return <FolderRow folder={current} />;';
        // Generated line 1: col 0 → (0,0); col 40100 → line 140 col 2 "renderFolder"; col 40210 → line 142 col 20 "folderId".
        $line1 = implode(',', [
            SourceMap::encodeVlq(0, 0, 0, 0),
            SourceMap::encodeVlq(40100, 0, 139, 2, 0),
            SourceMap::encodeVlq(110, 0, 2, 18, 1),
        ]);
        // Generated line 2: col 5 → source 1 (utils.ts) line 10 col 4 "formatDate".
        $line2 = SourceMap::encodeVlq(5, 1, -132, -16, 1);

        return [
            'version'        => 3,
            'file'           => 'mail-8c1.js',
            'sourceRoot'     => '',
            'sources'        => ['webpack://worksuite/./src/pages/mail/MailFolderList.tsx', 'webpack://worksuite/./src/lib/utils.ts'],
            'sourcesContent' => [implode("\n", $src), null],
            'names'          => ['renderFolder', 'folderId'],
            'mappings'       => $line1 . ';' . $line2,
            'debugId'        => '85314830-023f-4cf1-a267-535f4e37bb17',
        ];
    }

    public function testVlqKnownValues(): void
    {
        $this->assertSame([0, 0, 0, 0], SourceMap::decodeVlq('AAAA'));
        $this->assertSame([1], SourceMap::decodeVlq('C'));
        $this->assertSame([-1], SourceMap::decodeVlq('D'));
        $this->assertSame([16], SourceMap::decodeVlq('gB'));
        $this->assertSame([0, 0, 16, 1], SourceMap::decodeVlq('AAgBC'));
        $this->assertSame([-2147483647], SourceMap::decodeVlq('//////D'));
        foreach ([0, 1, -1, 15, 16, -16, 1000, -40210, 123456789] as $v) {
            $this->assertSame([$v], SourceMap::decodeVlq(SourceMap::encodeVlq($v)));
        }
    }

    public function testLookupAndContext(): void
    {
        $map = SourceMap::parse(json_encode(self::mailMap()));
        $pos = $map->resolve(1, 40211);
        $this->assertSame('webpack://worksuite/./src/pages/mail/MailFolderList.tsx', $pos['source']);
        $this->assertSame(142, $pos['line']);
        $this->assertSame(21, $pos['column']);
        $this->assertSame('folderId', $pos['name']);
        $this->assertSame('const parent = byId[current.parent].folderId;', $pos['context']['line']);
        $this->assertSame(['const current = folders.find((f) => f.id === activeId);', 'const unread = counts[current.id] ?? 0;'], array_slice($pos['context']['pre'], -2));
        $this->assertCount(3, $pos['context']['pre']);
        $this->assertSame('return <FolderRow folder={current} />;', $pos['context']['post'][0]);

        // Columns between segments map to the preceding segment.
        $this->assertSame(140, $map->lookup(1, 40150)['line']);
        $this->assertSame('renderFolder', $map->lookup(1, 40150)['name']);
        // Second generated line, second source, no sourcesContent.
        $p2 = $map->resolve(2, 9);
        $this->assertSame('webpack://worksuite/./src/lib/utils.ts', $p2['source']);
        $this->assertSame(10, $p2['line']);
        $this->assertSame(5, $p2['column']);
        $this->assertNull($p2['context']);
        // Unmapped line.
        $this->assertNull($map->lookup(3, 1));
        $this->assertSame('85314830-023f-4cf1-a267-535f4e37bb17', $map->debugId);
    }

    public function testIndexMapSections(): void
    {
        $index = ['version' => 3, 'sections' => [
            ['offset' => ['line' => 0, 'column' => 0], 'map' => ['version' => 3, 'sources' => ['a.ts'], 'names' => [], 'mappings' => 'AAAA', 'sourcesContent' => ['first']]],
            ['offset' => ['line' => 5, 'column' => 0], 'map' => self::mailMap()],
        ]];
        $map = SourceMap::parse($index);
        $this->assertSame('a.ts', $map->resolve(1, 1)['source']);
        $pos = $map->resolve(6, 40211);
        $this->assertSame(142, $pos['line']);
        $this->assertSame('folderId', $pos['name']);
    }

    public function testRejectsInvalidMaps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SourceMap::parse('{"version":2,"mappings":""}');
    }

    public function testXssiPrefixIsStripped(): void
    {
        $map = SourceMap::parse(")]}'\n" . json_encode(self::mailMap()));
        $this->assertSame(142, $map->lookup(1, 40211)['line']);
    }
}

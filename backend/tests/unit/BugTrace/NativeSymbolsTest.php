<?php

namespace Tests\Unit\BugTrace;

use App\Libraries\BugTrace\BreakpadSymbols;
use App\Libraries\BugTrace\R8Mapping;
use App\Libraries\BugTrace\Symbolicator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * FR-BT-ING-04 native symbolication: Breakpad .sym lookup, R8 retrace,
 * minidump-stackwalk output conversion.
 */
final class NativeSymbolsTest extends CIUnitTestCase
{
    public const SYM = <<<'SYM'
        MODULE windows x86_64 5A9832E5287241C1838ED98914E9B7FF1 WorkSuite.pdb
        INFO CODE_ID 6510A2F1A3000 WorkSuite.exe
        FILE 0 C:\src\worksuite\desktop\native\mail_index.cc
        FILE 1 C:\src\worksuite\desktop\native\crypto.cc
        FUNC 1000 40 0 MailIndex::Rebuild(int)
        1000 10 120 0
        1010 30 124 0
        FUNC 2000 20 8 Crypto::Decrypt(unsigned char const*,unsigned long)
        2000 20 57 1
        PUBLIC 3000 0 worksuite_start
        STACK CFI INIT 1000 40 .cfa: $rsp 8 + .ra: .cfa -8 + ^
        SYM;

    public const MAPPING = <<<'MAP'
        # compiler: R8
        com.worksuite.mail.FolderList -> a.b.c:
            java.lang.String title -> a
            1:4:void open(int):40:43 -> b
            5:5:void com.worksuite.mail.Folder.load():88:88 -> b
            5:5:void open(int):44 -> b
            void close() -> c
        com.worksuite.App -> a.a:
        MAP;

    public function testBreakpadLookup(): void
    {
        $s = BreakpadSymbols::parse(self::SYM);
        $this->assertSame('5A9832E5287241C1838ED98914E9B7FF1', $s->debugId);
        $this->assertSame('WorkSuite.pdb', $s->module);
        $this->assertSame(['function' => 'MailIndex::Rebuild(int)', 'file' => 'C:\src\worksuite\desktop\native\mail_index.cc', 'line' => 124, 'offset' => 0x18], $s->lookup(0x1018));
        $this->assertSame(57, $s->lookup(0x2005)['line']);
        $this->assertSame('crypto.cc', basename(str_replace('\\', '/', (string) $s->lookup(0x2005)['file'])));
        $this->assertSame('worksuite_start', $s->lookup(0x3010)['function'], 'falls back to PUBLIC symbols');
        $this->assertNull($s->lookup(0x10));
        $this->assertSame('5A9832E5287241C1838ED98914E9B7FF1', BreakpadSymbols::normalizeDebugId('5a9832e5-2872-41c1-838e-d98914e9b7ff1'));
    }

    public function testRejectsNonBreakpad(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BreakpadSymbols::parse("not a sym file\n");
    }

    public function testR8Retrace(): void
    {
        $m = R8Mapping::parse(self::MAPPING);
        $this->assertSame('com.worksuite.mail.FolderList', $m->originalClass('a.b.c'));
        $this->assertSame([['class' => 'com.worksuite.mail.FolderList', 'function' => 'open', 'line' => 42]], $m->retrace('a.b.c', 'b', 3));
        // Inlined: Folder.load() was inlined into open() at line 44.
        $this->assertSame([
            ['class' => 'com.worksuite.mail.Folder', 'function' => 'load', 'line' => 88],
            ['class' => 'com.worksuite.mail.FolderList', 'function' => 'open', 'line' => 44],
        ], $m->retrace('a.b.c', 'b', 5));
        $this->assertSame([['class' => 'com.worksuite.mail.FolderList', 'function' => 'close', 'line' => null]], $m->retrace('a.b.c', 'c', null));
        $this->assertSame([['class' => 'com.worksuite.App', 'function' => 'onCreate', 'line' => 9]], $m->retrace('a.a', 'onCreate', 9));
        $this->assertSame([], $m->retrace('z.z', 'a', 1));
    }

    public function testStackwalkJsonToFrames(): void
    {
        $frames = Symbolicator::framesFromStackwalk([
            'crash_info'      => ['type' => 'EXCEPTION_ACCESS_VIOLATION_READ', 'crashing_thread' => 0],
            'modules'         => [['filename' => 'C:\\Program Files\\WorkSuite\\WorkSuite.exe', 'debug_id' => '5A9832E5287241C1838ED98914E9B7FF1']],
            'crashing_thread' => ['frames' => [
                ['frame' => 0, 'module' => 'WorkSuite.exe', 'module_offset' => '0x00001018', 'offset' => '0x7ff6a0001018'],
                ['frame' => 1, 'module' => 'WorkSuite.exe', 'module_offset' => '0x00002005', 'offset' => '0x7ff6a0002005'],
                ['frame' => 2, 'offset' => '0x00000000'],
            ]],
        ]);
        $this->assertCount(3, $frames);
        $this->assertSame('0x00001018', $frames[2]['module_offset'], 'crashing frame is last (outermost → innermost)');
        $this->assertSame('5A9832E5287241C1838ED98914E9B7FF1', $frames[2]['debug_id']);
        $this->assertArrayNotHasKey('module', $frames[0]);
    }
}

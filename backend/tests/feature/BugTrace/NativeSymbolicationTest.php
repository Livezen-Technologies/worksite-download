<?php

namespace Tests\Feature\BugTrace;

require_once __DIR__ . '/BugTraceTestCase.php';
require_once __DIR__ . '/../../unit/BugTrace/NativeSymbolsTest.php';

use App\Libraries\Ulid;
use Tests\Unit\BugTrace\NativeSymbolsTest;

/**
 * AC-C61 / AC-C62: native desktop crashes (Breakpad symbols from PDB/dSYM/ELF)
 * and Android JVM crashes (R8 mapping) are symbolicated; symbols that arrive
 * late re-symbolicate stored events.
 */
final class NativeSymbolicationTest extends BugTraceTestCase
{
    private const MOBILE_KEY = 'pk_mobile_dev_000000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        \App\Libraries\BugTrace\Symbolicator::flushCache();
    }

    private function uploadSymbols(array $release, string $kind, string $fileName, string $body, string $platform): array
    {
        $ci  = $this->makeClient(['bugtrace.symbols.upload']);
        $res = $this->withHeaders($ci + ['Content-Type' => 'application/octet-stream'])->withBody($body)
            ->post("api/v1/admin/bugtrace/symbols?release_id={$release['public_id']}&kind={$kind}&file_name={$fileName}&platform={$platform}");
        $this->assertSame(201, $res->response()->getStatusCode(), (string) $res->response()->getBody());

        return $this->json($res);
    }

    private function nativeEvent(string $platform, array $frames, array $o = []): array
    {
        return array_replace([
            'event_id' => str_replace('_', '', Ulid::generate()), 'type' => 'crash', 'platform' => $platform,
            'release' => '2.7.0+27001', 'environment' => 'production', 'installation_ref' => 'inst_hmac:aa01',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'exception' => [['type' => 'EXCEPTION_ACCESS_VIOLATION_READ', 'value' => 'Native crash in browser process', 'stacktrace' => ['frames' => $frames]]],
        ], $o);
    }

    private function latestIssue(): array
    {
        $admin = $this->staff(['developer']);
        $list  = $this->json($this->withHeaders($admin)->get('api/v1/admin/bugtrace/issues'));

        return $this->json($this->withHeaders($admin)->get('api/v1/admin/bugtrace/issues/' . $list['data'][0]['id']));
    }

    public function testDesktopNativeFramesSymbolicatedWithBreakpad(): void
    {
        $release = $this->release('desktop', '2.7.0', 27001);
        $sym     = $this->uploadSymbols($release, 'breakpad', 'WorkSuite.sym', NativeSymbolsTest::SYM, 'desktop');
        $this->assertSame('5a9832e5287241c1838ed98914e9b7ff1', strtolower((string) $sym['debug_id']));
        $this->assertSame('WorkSuite', $sym['artifact']);

        $frames = [
            ['module' => 'WorkSuite.exe', 'debug_id' => '5a9832e5-2872-41c1-838e-d98914e9b7ff1', 'module_offset' => '0x2005'],
            ['module' => 'WorkSuite.exe', 'debug_id' => '5a9832e5-2872-41c1-838e-d98914e9b7ff1', 'module_offset' => '0x1018'],
        ];
        $this->envelope(['events' => [$this->nativeEvent('windows', $frames)]], self::DESKTOP_KEY)->assertStatus(202);

        $d = $this->latestIssue();
        $this->assertSame('ok', $d['symbolication']);
        $this->assertSame('MailIndex::Rebuild(int)', $d['frames'][0]['function']);
        $this->assertSame(124, $d['frames'][0]['line']);
    }

    public function testLateSymbolsResymbolicateAndRejectGarbage(): void
    {
        $release = $this->release('desktop', '2.7.0', 27001);
        $frames  = [['module' => 'WorkSuite.exe', 'debug_id' => '5A9832E5287241C1838ED98914E9B7FF1', 'module_offset' => '0x1018']];
        $this->envelope(['events' => [$this->nativeEvent('windows', $frames)]], self::DESKTOP_KEY)->assertStatus(202);
        $this->assertSame('missing', $this->latestIssue()['symbolication']);

        $this->uploadSymbols($release, 'breakpad', 'WorkSuite.sym', NativeSymbolsTest::SYM, 'desktop');
        $this->assertSame('ok', db_connect()->table('bt_events')->orderBy('id', 'DESC')->get()->getRow('symbolication'));

        $ci  = $this->makeClient(['bugtrace.symbols.upload']);
        $res = $this->withHeaders($ci + ['Content-Type' => 'application/octet-stream'])->withBody('MZ garbage')
            ->post("api/v1/admin/bugtrace/symbols?release_id={$release['public_id']}&kind=breakpad&file_name=x.sym");
        $this->assertSame(422, $res->response()->getStatusCode());
    }

    public function testAndroidJvmFramesRetracedWithR8Mapping(): void
    {
        $release = $this->release('desktop', '2.7.0', 27001);
        $this->uploadSymbols($release, 'r8', 'mapping.txt', NativeSymbolsTest::MAPPING, 'mobile');
        $frames = [
            ['module' => 'a.a', 'function' => 'onCreate', 'line' => 9],
            ['module' => 'a.b.c', 'function' => 'b', 'line' => 5],
        ];
        $this->envelope(['events' => [$this->nativeEvent('android', $frames, ['exception' => [['type' => 'java.lang.NullPointerException', 'value' => 'folder was null', 'stacktrace' => ['frames' => $frames]]]])]], self::MOBILE_KEY)->assertStatus(202);

        $d = $this->latestIssue();
        $this->assertSame('ok', $d['symbolication']);
        $fns = array_map(static fn ($f) => ($f['module'] ?? '') . '.' . $f['function'], $d['frames']);
        $this->assertSame('com.worksuite.mail.Folder.load', $fns[0], 'inlined method is the innermost frame');
        $this->assertContains('com.worksuite.mail.FolderList.open', $fns);
    }
}

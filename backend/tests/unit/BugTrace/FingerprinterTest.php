<?php

namespace Tests\Unit\BugTrace;

use App\Libraries\BugTrace\Fingerprinter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * AC-C66: duplicate events group into one issue by fingerprint (exception
 * type + normalized top 5 in-app frames, no line numbers or hash suffixes).
 */
final class FingerprinterTest extends CIUnitTestCase
{
    private static function event(string $type, string $value, array $frames, string $etype = 'error'): array
    {
        return ['type' => $etype, 'exception' => [['type' => $type, 'value' => $value, 'stacktrace' => ['frames' => $frames]]]];
    }

    private static function frames(string $bundle, int $lineShift = 0, string $fnSuffix = ''): array
    {
        return [
            ['file' => "https://mail.tenant.lk/assets/vendor-react-{$bundle}.js", 'function' => 'renderWithHooks', 'line' => 3 + $lineShift, 'col' => 999, 'in_app' => false],
            ['file' => "https://mail.tenant.lk/assets/index-{$bundle}.js", 'function' => 'MailPage', 'line' => 1, 'col' => 1200 + $lineShift, 'in_app' => true],
            ['file' => "https://mail.tenant.lk/assets/mail-{$bundle}.js?v=7", 'function' => 'MailFolderList' . $fnSuffix, 'line' => 1, 'col' => 40211 + $lineShift, 'in_app' => true],
            ['file' => "/assets/mail-{$bundle}.js", 'function' => 'r', 'line' => 1, 'col' => 40001 + $lineShift, 'in_app' => true],
        ];
    }

    public function testSameErrorAcrossBuildsGroupsTogether(): void
    {
        $fp = new Fingerprinter();
        $a  = $fp->compute(self::event('TypeError', "Cannot read properties of undefined (reading 'folderId')", self::frames('8c1')));
        $b  = $fp->compute(self::event('TypeError', "Cannot read properties of undefined (reading 'parentId')", self::frames('Bx7k9aZq', 17)));
        $this->assertSame($a['fingerprint'], $b['fingerprint'], 'hash suffixes, line/col numbers and message text do not split the issue');
        $this->assertSame("TypeError: Cannot read properties of undefined (reading 'folderId')", $a['title']);
        $this->assertSame('mail.js in r', $a['culprit']);
    }

    public function testDifferentTypeOrFramesSplit(): void
    {
        $fp = new Fingerprinter();
        $a  = $fp->compute(self::event('TypeError', 'x', self::frames('8c1')));
        $this->assertNotSame($a['fingerprint'], $fp->compute(self::event('RangeError', 'x', self::frames('8c1')))['fingerprint']);
        $this->assertNotSame($a['fingerprint'], $fp->compute(self::event('TypeError', 'x', self::frames('8c1', 0, 'Row')))['fingerprint']);
    }

    public function testOnlyTopFiveInAppFramesCount(): void
    {
        $base = [];
        for ($i = 0; $i < 5; $i++) {
            $base[] = ['file' => "src/f{$i}.ts", 'function' => "fn{$i}", 'line' => $i, 'in_app' => true];
        }
        $fp = new Fingerprinter();
        $a  = $fp->compute(self::event('Error', 'e', array_merge([['file' => 'src/outer.ts', 'function' => 'outerA', 'in_app' => true]], $base)));
        $b  = $fp->compute(self::event('Error', 'e', array_merge([['file' => 'src/other.ts', 'function' => 'outerB', 'in_app' => true]], $base)));
        $this->assertSame($a['fingerprint'], $b['fingerprint'], 'frames beyond the top 5 in-app frames are ignored');
    }

    public function testMessageFallbackNormalizesVariables(): void
    {
        $fp = new Fingerprinter();
        $a  = $fp->compute(['type' => 'message', 'message' => 'Sync failed for folder 88 after 3 retries', 'exception' => []]);
        $b  = $fp->compute(['type' => 'message', 'message' => 'Sync failed for folder 1204 after 5 retries', 'exception' => []]);
        $c  = $fp->compute(['type' => 'message', 'message' => 'Upload failed', 'exception' => []]);
        $this->assertSame($a['fingerprint'], $b['fingerprint']);
        $this->assertNotSame($a['fingerprint'], $c['fingerprint']);
        $this->assertSame('Sync failed for folder 88 after 3 retries', $a['title']);
    }

    public function testNativeCrashUsesSignalAndModule(): void
    {
        $fp  = new Fingerprinter();
        $ev  = static fn (string $sig, string $mod) => ['type' => 'crash', 'tags' => ['process_type' => 'renderer'], 'exception' => [['type' => $sig, 'value' => 'Native crash', 'signal' => $sig, 'module' => $mod, 'mechanism' => ['type' => 'minidump'], 'stacktrace' => ['frames' => []]]]];
        $this->assertSame($fp->compute($ev('EXCEPTION_ACCESS_VIOLATION', 'libGLESv2.dll'))['fingerprint'], $fp->compute($ev('EXCEPTION_ACCESS_VIOLATION', 'libGLESv2.dll'))['fingerprint']);
        $this->assertNotSame($fp->compute($ev('EXCEPTION_ACCESS_VIOLATION', 'libGLESv2.dll'))['fingerprint'], $fp->compute($ev('SIGSEGV', 'libGLESv2.dll'))['fingerprint']);
    }

    public function testCustomRuleOverrides(): void
    {
        $fp = new Fingerprinter([['match_type' => 'exception_type', 'match_value' => 'ChunkLoadError', 'fingerprint' => 'chunk-load-failures', 'enabled' => 1]]);
        $a  = $fp->compute(self::event('ChunkLoadError', 'Loading chunk 12 failed', self::frames('8c1')));
        $b  = $fp->compute(self::event('ChunkLoadError', 'Loading chunk 9 failed', [['file' => 'x.js', 'function' => 'other', 'in_app' => true]]));
        $this->assertSame($a['fingerprint'], $b['fingerprint']);
        $this->assertSame(['custom:chunk-load-failures'], $a['components']);
    }

    public function testNormalizeFile(): void
    {
        $this->assertSame('assets/mail.js', Fingerprinter::normalizeFile('https://mail.tenant.lk/assets/mail-8c1.js?v=2#x'));
        $this->assertSame('static/js/main.chunk.js', Fingerprinter::normalizeFile('/static/js/main.3f2a1b9c.chunk.js'));
        $this->assertSame('assets/index.js', Fingerprinter::normalizeFile('assets/index-BxT3k9aZ.js'));
        $this->assertSame('assets/vendor-react.js', Fingerprinter::normalizeFile('assets/vendor-react.js'));
        $this->assertSame('src/pages/mail/MailFolderList.tsx', Fingerprinter::normalizeFile('webpack://worksuite/./src/pages/mail/MailFolderList.tsx'));
        $this->assertSame('assets/app.js', Fingerprinter::normalizeFile('https://cdn.x/3.4.0+34012/assets/app.js'));
        $this->assertSame('', Fingerprinter::normalizeFunction('r'));
        $this->assertSame('renderFolder', Fingerprinter::normalizeFunction('async renderFolder'));
        $this->assertSame('mz::Crash', Fingerprinter::normalizeFunction('mz::Crash+0x1a2b'));
    }
}

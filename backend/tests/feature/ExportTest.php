<?php

namespace Tests\Feature;

use App\Services\ExportService;
use Tests\Support\UcTestCase;

/**
 * §13.3 daily export to write-once storage (FR-UC-OPS-04).
 */
final class ExportTest extends UcTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = WRITEPATH . 'exports/';
        $this->rm($this->dir);
        cache()->delete('uc_export_audit_after');
    }

    protected function tearDown(): void
    {
        $this->rm($this->dir);
        parent::tearDown();
    }

    private function rm(string $d): void
    {
        if (! is_dir($d)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            @chmod($f->getPathname(), 0700);
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($d);
    }

    private function read(string $date, string $name): array
    {
        return json_decode(gzdecode((string) file_get_contents($this->dir . "{$date}/{$name}.json.gz")), true);
    }

    public function testExportsDocumentsOnceAndAuditIncrementally(): void
    {
        $db = db_connect();
        $db->table('settings')->insert(['key' => 'smtp.password', 'value' => 'hunter2']);
        $db->table('settings')->insert(['key' => 'site.title', 'value' => 'WorkSuite Downloads']);
        service('audit')->record('test.event', 'thing', '1');

        $r = (new ExportService())->run('2026-10-01');
        $this->assertSame(['written'], array_values(array_unique(array_column($r['objects'], 'status'))));
        $this->assertCount(4, $r['objects']);

        $cfg = $this->read('2026-10-01', 'configuration');
        $keys = array_column($cfg['data']['settings'], 'key');
        $this->assertContains('site.title', $keys);
        $this->assertNotContains('smtp.password', $keys, 'secret-looking settings are never exported');
        $this->assertArrayHasKey('applications', $cfg['data']);
        $this->assertStringNotContainsString('hunter2', (string) gzdecode((string) file_get_contents($this->dir . '2026-10-01/configuration.json.gz')));

        $audit = $this->read('2026-10-01', 'audit_logs');
        $this->assertContains('test.event', array_column($audit['data']['rows'], 'action'));

        // Write-once: a second run for the same day does not overwrite.
        $again = (new ExportService())->run('2026-10-01');
        $this->assertSame(['exists'], array_values(array_unique(array_column($again['objects'], 'status'))));

        // Next day only carries audit rows after the last exported id (the export's own audit entry included).
        (new ExportService())->run('2026-10-02');
        $next = $this->read('2026-10-02', 'audit_logs');
        $this->assertNotContains('test.event', array_column($next['data']['rows'], 'action'));
        $this->assertContains('ops.export', array_column($next['data']['rows'], 'action'));
        $this->assertGreaterThan(0, $next['data']['after_id']);
    }
}

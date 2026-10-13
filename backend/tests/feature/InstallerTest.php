<?php

namespace Tests\Feature;

use Tests\Support\UcTestCase;

/**
 * The one-command WorkSuite Server installer served at /install.sh, its published checksum and
 * the Server download page's installation guide.
 */
final class InstallerTest extends UcTestCase
{
    private const DIR = __DIR__ . '/../../../frontend/public/';

    public function testThePublishedChecksumIsTheInstallers(): void
    {
        $script = (string) file_get_contents(self::DIR . 'install.sh');
        $sum    = trim((string) file_get_contents(self::DIR . 'install.sh.sha256'));
        $this->assertSame(hash('sha256', $script) . '  install.sh', $sum, 'regenerate frontend/public/install.sh.sha256 after editing install.sh');
    }

    public function testTheInstallerIsValidBashAndPinsTheProductionReleaseKey(): void
    {
        exec('bash -n ' . escapeshellarg(self::DIR . 'install.sh') . ' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $script = (string) file_get_contents(self::DIR . 'install.sh');
        $this->assertStringContainsString('WS_RELEASE_KEY_ID="ws-release-1"', $script);
        $this->assertStringContainsString('/api/v1/server/install/package', $script);
        $this->assertStringContainsString('license:activate --key', $script);
    }

    public function testTheServerPageCarriesTheInstallGuide(): void
    {
        $res  = $this->get('api/v1/apps/server');
        $page = $this->json($res);
        $page = $page['download_page'] ?? $page['page'] ?? $page;
        $text = json_encode($page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('download.worksuitecloud.com/install.sh', (string) $text);
        $this->assertStringContainsString('RHEL, Rocky Linux, AlmaLinux', (string) $text);
        $this->assertStringContainsString('10–20 minutes', (string) $text, 'real characters, not escapes');
        $this->assertStringNotContainsString('\\u20', (string) $text);
    }
}

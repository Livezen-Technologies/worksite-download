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

    /** The files the installer writes agree with what the WorkSuite app, its units and its sudoers rule read. */
    public function testTheInstallerWritesWhatTheAppReads(): void
    {
        $script = (string) file_get_contents(self::DIR . 'install.sh');

        // Realtime: the app derives the gateway key; the installer never invents one.
        $this->assertStringNotContainsString('REALTIME_SECRET=$rt', $script);
        $this->assertStringNotContainsString("REALTIME_SECRET = '", $script);
        $this->assertStringContainsString('REALTIME_SECRET_FILE=$INSTALL_DIR/shared/writable/realtime/secret', $script);
        $this->assertStringContainsString('spark realtime:secret --write', $script);
        $this->assertLessThan(strpos($script, 'systemctl enable --now worksuite-realtime'), strpos($script, 'spark realtime:secret --write'));

        // WhatsApp: the unit's env file (production's path) and the key name the app reads.
        $this->assertStringContainsString('/etc/worksuite-whatsapp.env <<EOF', $script);
        $this->assertStringNotContainsString('/etc/worksuite/whatsapp.env', $script);
        $this->assertStringContainsString("whatsapp.bridge_key = '\$wa'", $script);
        $this->assertStringNotContainsString('worksuite-whatsapp-bridge', $script);

        // Monitoring follows the installer's layout and installs no worksuite-rtc.
        $this->assertStringContainsString("monitor.backup_path = '\$INSTALL_DIR/backups'", $script);
        $this->assertStringContainsString("monitor.backup_glob = 'nightly-*/db.sql.zst'", $script);
        $this->assertStringContainsString('monitor.services = ', $script);
        $this->assertStringNotContainsString('worksuite-rtc', $script);

        // spark reads `--opt value`; `--opt=value` is silently ignored.
        $this->assertDoesNotMatchRegularExpression('/spark[^\n]*\s--[a-z][a-z0-9-]*=/', $script);
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

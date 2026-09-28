<?php

namespace App\Commands;

use App\Libraries\Base64Url;
use App\Libraries\Trust\TokenIssuer;
use App\Services\AuthService;
use App\Services\Catalog;
use App\Services\ChangelogService;
use App\Services\PackageService;
use App\Services\ReleaseService;
use App\Services\RolloutService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Seeds a realistic demo environment (development only): staff users, desktop
 * releases across support states, a beta, a release in testing, server
 * edition packages, devices, downloads, installations and a tenant.
 * Everything goes through the real services (uploads, pipeline, signing).
 */
class Demo extends BaseCommand
{
    protected $group       = 'Update Center';
    protected $name        = 'uc:demo';
    protected $description = 'Seed demo data (DEV/STAGING only). Password for all users: "worksuite-demo-2026".';

    private const PASSWORD = 'worksuite-demo-2026';

    private array $users = [];

    public function run(array $params)
    {
        $cfg = config('UpdateCenter');
        if ($cfg->isProduction()) {
            CLI::error('uc:demo is disabled in production');

            return EXIT_ERROR;
        }
        $cfg->queueSync = true;
        $auth           = new AuthService();
        foreach ([['admin@livezen.lk', 'Nuwan Perera', ['super_admin']], ['rm@livezen.lk', 'Nadeesha Fernando', ['release_manager']], ['qa@livezen.lk', 'Kasun Silva', ['qa']], ['dev@livezen.lk', 'Dilini Jayasinghe', ['developer']], ['content@livezen.lk', 'Ruwan Wijesinghe', ['content_manager']], ['support@livezen.lk', 'Tharindu Bandara', ['support']], ['viewer@livezen.lk', 'Ishara Gunawardena', ['viewer']]] as [$email, $name, $roles]) {
            $u = db_connect()->table('users')->where('email', $email)->get()->getRowArray() ?? $auth->createUser($email, self::PASSWORD, $name, $roles);
            $this->users[$roles[0]] = $u;
        }
        if (! db_connect()->table('users')->where('email', 'customer@example.com')->countAllResults()) {
            $p = $auth->createUser('customer@example.com', self::PASSWORD, 'Beta Customer', ['portal_user', 'beta_tester'], 'PORTAL');
            db_connect()->table('users')->where('id', $p['id'])->update(['beta_opt_in' => 1]);
        }

        if (db_connect()->table('releases')->countAllResults() > 0) {
            CLI::write('Releases already exist — users ensured, skipping release data.', 'yellow');

            return EXIT_SUCCESS;
        }

        $desktop = [
            ['2.4.0', 24003, 'STABLE', 'RECOMMENDED', 'UNSUPPORTED', '-120 days', ['Improved startup time'], ['Fixed tray icon on Windows 11']],
            ['2.5.2', 25201, 'STABLE', 'RECOMMENDED', 'DEPRECATED', '-80 days', ['Calendar reminders from the tray'], ['Fixed Meet echo on macOS']],
            ['2.6.4', 26404, 'STABLE', 'OPTIONAL', 'DEPRECATED', '-45 days', ['Shared mailbox folders'], ['Fixed login loop behind proxies']],
            ['2.6.5', 26505, 'STABLE', 'RECOMMENDED', 'SUPPORTED', '-21 days', ['Faster search in Files'], ['Fixed screen sharing layout']],
            ['2.7.0', 27001, 'STABLE', 'RECOMMENDED', 'SUPPORTED', '-2 days', ['Desktop offline account support', 'Faster Mail folder loading', 'Meet fullscreen fixes'], ['Fixed Meet fullscreen issue', 'Fixed screen sharing layout']],
            ['2.8.0-beta.2', 28002, 'BETA', 'OPTIONAL', 'SUPPORTED', '-1 days', ['New unified inbox (beta)', 'Offline drafts'], ['Fixed crash when opening Finance folder']],
        ];
        foreach ($desktop as [$v, $b, $ch, $type, $support, $ago, $features, $fixes]) {
            $id = $this->release('desktop', $v, $b, $ch, $type, $features, $fixes, $ago);
            $this->ship($id);
            if ($support !== 'SUPPORTED') {
                (new ReleaseService($this->ctx('release_manager')))->setSupportState(Catalog::release($id), $support, 'Demo support window');
            }
            CLI::write("Desktop {$v} ({$ch}) published — {$support}", 'green');
        }
        db_connect()->table('release_rollouts')->set(['status' => 'DONE', 'percentage' => 100])->update();
        // 2.7.0 stays in a staged rollout at 50 %.
        $r270 = db_connect()->table('releases')->where('version', '2.7.0')->get()->getRowArray();
        db_connect()->table('release_rollouts')->where('release_id', $r270['id'])->update(['status' => 'ACTIVE', 'stage_no' => 3, 'percentage' => 50, 'auto_advance' => 1, 'stage_started_at' => gmdate('Y-m-d H:i:s', time() - 3600 * 5)]);
        $min = db_connect()->table('minimum_version_policies');
        $min->insert(['application_id' => $r270['application_id'], 'channel_id' => $r270['channel_id'], 'platform' => null, 'minimum_version' => '2.5.0', 'enforce_from' => gmdate('Y-m-d H:i:s', time() - 86400 * 30), 'message' => 'Please update to continue using WorkSuite.', 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);

        // A release waiting in QA.
        $testing = $this->release('desktop', '2.7.1', 27101, 'STABLE', 'RECOMMENDED', ['Accessibility improvements in Chat'], ['Fixed notification badge count'], '-0 days');
        (new ReleaseService($this->ctx('developer')))->submitForTest(Catalog::release($testing));
        (new ReleaseService($this->ctx('qa')))->startTesting(Catalog::release($testing));
        CLI::write('Desktop 2.7.1 in TESTING', 'green');

        // Other apps.
        foreach ([['quick-support', '1.1.0', 11003], ['installer', '1.3.3', 13301]] as [$slug, $v, $b]) {
            $id = $this->release($slug, $v, $b, 'STABLE', 'RECOMMENDED', ['Signed with the 2026 certificate'], ['Minor fixes'], '-10 days');
            $this->ship($id);
            CLI::write("{$slug} {$v} published", 'green');
        }

        // Server editions.
        foreach ([['3.3.2', 33204, '-40 days'], ['3.4.0', 34012, '-3 days']] as [$v, $b, $ago]) {
            $id = $this->release('server', $v, $b, 'STABLE', 'RECOMMENDED', ['Desktop catalog sync', 'System › Updates with automatic rollback'], ['Fixed IMAP idle reconnects'], $ago);
            db_connect()->table('release_server_meta')->where('release_id', Catalog::release($id)['id'])->update(['staging_test' => json_encode(['status' => 'PASSED', 'url' => 'https://ci.worksuite.lk/runs/' . $b]), 'admin_notice' => 'Expect about 2 minutes of maintenance mode.', 'downtime_estimate_s' => 120]);
            $this->ship($id);
            CLI::write("Server {$v} published (Free, Business, Enterprise)", 'green');
        }

        $this->telemetry();
        $this->installations();
        $this->tenant();
        CLI::newLine();
        CLI::write('Demo ready. Sign in at /admin with admin@livezen.lk / ' . self::PASSWORD, 'yellow');

        return EXIT_SUCCESS;
    }

    private function ctx(string $role): \App\Services\AuthContext
    {
        $ctx = service('authContext');
        $ctx->reset();
        $ctx->user = $this->users[$role];
        $rp = (new AuthService())->rolesAndPermissions((int) $ctx->user['id']);
        $ctx->roles = $rp['roles'];
        $ctx->permissions = $rp['permissions'];

        return $ctx;
    }

    private function release(string $slug, string $version, int $build, string $channel, string $type, array $features, array $fixes, string $ago): string
    {
        $svc = new ReleaseService($this->ctx('developer'));
        $r   = $svc->create(['app_id' => $slug, 'version' => $version, 'build' => $build, 'channel' => $channel, 'release_type' => $type, 'minimum_no_change' => true, 'release_date' => gmdate('c', strtotime($ago))]);
        $app = Catalog::appById((int) $r['application_id']);
        (new ChangelogService())->save($r, ['summary' => "{$app['name']} {$version}", 'highlights' => array_slice($features, 0, 3), 'sections' => ['new_features' => $features, 'bug_fixes' => $fixes, 'security' => $version === '2.7.0' ? ['Hardened update verification (manifest key rotation support)'] : []]], (int) $this->users['developer']['id']);
        $pkg = new PackageService();
        if ($app['kind'] === 'SERVER') {
            $tmp = WRITEPATH . 'uploads/demo-server.zip';
            @mkdir(dirname($tmp), 0770, true);
            $mods = ['FREE' => ['core', 'mail', 'chat', 'meet'], 'BUSINESS' => ['core', 'mail', 'chat', 'meet', 'crm', 'hr', 'finance'], 'ENTERPRISE' => ['core', 'mail', 'crm', 'hr', 'finance', 'payroll', 'fleet']];
            foreach ($mods as $edition => $m) {
                self::serverZip($tmp, $version, $build, $edition, $m);
                $this->upload($pkg, $r, ['edition' => $edition, 'file_name' => "worksuite-server-{$version}-" . strtolower($edition) . '.zip'], (string) file_get_contents($tmp));
            }
            @unlink($tmp);
        } else {
            foreach (Catalog::platforms((int) $app['id']) as $pl) {
                foreach ($pl['package_types'] as $t) {
                    $name = match ($t) {
                        'exe' => "WorkSuite-{$slug}-{$version}-{$pl['architecture']}-setup.exe",
                        'msi' => "WorkSuite-{$slug}-{$version}-{$pl['architecture']}.msi",
                        'appimage' => "WorkSuite-{$slug}-{$version}-x86_64.AppImage",
                        'deb' => "worksuite-{$slug}_{$version}_amd64.deb",
                        'rpm' => "worksuite-{$slug}-{$version}.x86_64.rpm",
                        default => "WorkSuite-{$slug}-{$version}-{$pl['architecture']}.{$t}",
                    };
                    $this->upload($pkg, $r, ['platform' => $pl['platform'], 'architecture' => $pl['architecture'], 'package_type' => $t, 'file_name' => $name], self::binary($t, $pl['architecture']));
                }
            }
        }

        return $r['public_id'];
    }

    private function upload(PackageService $svc, array $release, array $meta, string $bytes): void
    {
        $res    = $svc->createUpload(Catalog::releaseById((int) $release['id']), $meta + ['size' => strlen($bytes)], $this->ctx('developer'));
        $upload = $svc->upload($res['upload']['id']);
        $h      = fopen('php://temp', 'w+b');
        fwrite($h, $bytes);
        rewind($h);
        $svc->appendChunk($upload, 0, $h);
        fclose($h);
    }

    private function ship(string $id): void
    {
        (new ReleaseService($this->ctx('developer')))->submitForTest(Catalog::release($id));
        (new ReleaseService($this->ctx('qa')))->qa(Catalog::release($id), 'PASS', null, 'Installed on all platforms');
        (new ReleaseService($this->ctx('release_manager')))->approve(Catalog::release($id), 'Approved');
        (new ReleaseService($this->ctx('release_manager')))->publish(Catalog::release($id), 'Demo');
        (new RolloutService())->action(Catalog::release($id), 'complete', [], true);
        $r = Catalog::release($id);
        $at = db_connect()->table('releases')->select('release_date')->where('id', $r['id'])->get()->getRow('release_date');
        db_connect()->table('releases')->where('id', $r['id'])->update(['published_at' => $at ?? Catalog::now()]);
    }

    /** Header-correct placeholder binaries (~1.5 MB) — never real installers. */
    public static function binary(string $type, string $arch): string
    {
        $size = 1_500_000 + random_int(0, 400_000);
        $body = str_repeat("WorkSuite demo package — not an installer.\n", (int) ($size / 44));

        return match ($type) {
            'exe' => 'MZ' . str_repeat("\0", 0x3A) . pack('V', 0x80) . str_repeat("\0", 0x40) . "PE\0\0" . pack('v', $arch === 'arm64' ? 0xAA64 : 0x8664) . $body,
            'msi' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . ($arch === 'arm64' ? 'Arm64;1033' : 'x64;1033') . $body,
            'dmg' => $body . 'koly' . str_repeat("\0", 508),
            'pkg' => 'xar!' . $body,
            'zip' => "PK\x05\x06" . str_repeat("\0", 18) . $body,
            'appimage' => "\x7FELF\x02\x01\x01\x00AI\x02" . str_repeat("\0", 5) . pack('v', 2) . pack('v', $arch === 'arm64' ? 0xB7 : 0x3E) . $body,
            'deb' => "!<arch>\ndebian-binary   Architecture: " . ($arch === 'arm64' ? 'arm64' : 'amd64') . "\n" . $body,
            'rpm' => "\xED\xAB\xEE\xDB" . str_repeat("\0", 6) . 'worksuite-' . ($arch === 'arm64' ? 'aarch64' : 'x86_64') . $body,
            default => $body,
        };
    }

    public static function serverZip(string $path, string $version, int $build, string $edition, array $modules): void
    {
        $files = ['spark' => "#!/usr/bin/env php\n<?php\n", 'public/index.php' => "<?php\n", 'web/dist/index.html' => '<!doctype html><title>WorkSuite</title>'];
        foreach ($modules as $m) {
            $files['app/Modules/' . ucfirst($m) . '/Config/Routes.php'] = "<?php // {$m}\n" . str_repeat('// module code' . "\n", 2000);
        }
        $list = '';
        foreach ($files as $p => $c) {
            $list .= hash('sha256', $c) . '  ' . $p . "\n";
        }
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $p => $c) {
            $zip->addFromString($p, $c);
        }
        $zip->addFromString('files.sha256', $list);
        $zip->addFromString('worksuite-package.json', json_encode(['schema' => 1, 'product' => 'worksuite-server', 'version' => $version, 'build' => $build, 'edition' => strtolower($edition), 'scope' => 'full', 'min_from_version' => '3.2.0', 'channel' => 'stable', 'requires' => ['php' => '>=8.2 <8.5', 'db' => 'mariadb>=10.6|mysql>=8.0', 'ext' => ['intl', 'sodium', 'imap', 'mbstring', 'gd'], 'disk_mb' => 1200], 'modules' => $modules, 'migrations' => ['count' => 27, 'one_way' => ['2026-09-25-120000_CreateDesktop']], 'removed_files' => [], 'post_update' => ['cache:clear', 'desktop:catalog-sync'], 'desktop_compat' => '2.6.0 - 2.8.x', 'files_digest' => 'sha256:' . hash('sha256', $list)]));
        $zip->close();
    }

    private function telemetry(): void
    {
        $db   = db_connect();
        $app  = Catalog::app('desktop');
        $mix  = ['2.7.0' => 52, '2.6.5' => 32, '2.5.2' => 11, '2.4.0' => 5];
        $plats = [['windows', 'x64', 62], ['windows', 'arm64', 4], ['macos', 'arm64', 18], ['macos', 'x64', 6], ['linux', 'x64', 10]];
        $n    = 0;
        $rows = [];
        foreach ($mix as $v => $pct) {
            for ($i = 0; $i < 25 * $pct; $i++) {
                $roll = random_int(1, 100);
                $acc  = 0;
                foreach ($plats as [$pl, $ar, $w]) {
                    $acc += $w;
                    if ($roll <= $acc) {
                        break;
                    }
                }
                $rows[] = ['device_ref' => 'hmac:' . substr(hash('sha256', 'dev' . $n++), 0, 24), 'tenant_id' => 'tnt_01J6DEMO', 'application_id' => $app['id'], 'version' => $v, 'build' => 0, 'platform' => $pl, 'architecture' => $ar, 'channel' => 'STABLE', 'country_code' => ['LK', 'LK', 'LK', 'IN', 'AE', 'SG'][random_int(0, 5)], 'first_seen' => gmdate('Y-m-d H:i:s', time() - 86400 * 60), 'last_seen' => gmdate('Y-m-d H:i:s', time() - random_int(0, 6 * 86400))];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->table('device_versions')->insertBatch($chunk);
        }
        // Update events for 2.7.0.
        $rel = $db->table('releases')->where('version', '2.7.0')->get()->getRowArray();
        $ev  = [];
        foreach ([['install_succeeded', 1180, null], ['install_failed', 14, 'UPDATE_DISK_FULL'], ['install_failed', 6, 'SIGNATURE_MISMATCH'], ['rolled_back', 3, 'HEALTH_CHECK_FAILED'], ['download_completed', 1210, null], ['update_checked', 2400, null]] as [$type, $count, $code]) {
            $db->table('update_stats_hourly')->insert(['hour' => gmdate('Y-m-d H:00:00', time() - 3600), 'release_id' => $rel['id'], 'platform' => 'windows', 'type' => $type, 'error_code' => $code ?? '', 'count' => $count]);
            for ($i = 0; $i < min($count, 60); $i++) {
                $ev[] = ['event_id' => bin2hex(random_bytes(8)), 'device_ref' => 'hmac:' . bin2hex(random_bytes(6)), 'tenant_id' => 'tnt_01J6DEMO', 'application_id' => $app['id'], 'release_id' => $rel['id'], 'type' => $type, 'from_version' => '2.6.5', 'to_version' => '2.7.0', 'platform' => 'windows', 'architecture' => 'x64', 'channel' => 'STABLE', 'error_code' => $code, 'occurred_at' => gmdate('Y-m-d H:i:s', time() - random_int(0, 86400)), 'received_at' => Catalog::now()];
            }
        }
        foreach (array_chunk($ev, 500) as $chunk) {
            $db->table('update_events')->insertBatch($chunk);
        }
        // Download events over 30 days.
        $dl = [];
        foreach ($db->table('release_packages p')->select('p.*, r.version, r.application_id, r.channel_id, r.published_at')->join('releases r', 'r.id = p.release_id')->where('r.status', 'PUBLISHED')->get()->getResultArray() as $p) {
            $weight = match ($p['platform']) { 'windows' => 40, 'macos' => 14, 'linux' => 6, default => 8 } * ($p['package_type'] === 'msi' ? 0.3 : 1);
            for ($i = 0; $i < (int) $weight * 6; $i++) {
                $dl[] = ['application_id' => $p['application_id'], 'release_id' => $p['release_id'], 'package_id' => $p['id'], 'revision_id' => $p['active_revision_id'], 'channel' => Catalog::channelById((int) $p['channel_id'])['code'], 'version' => $p['version'], 'platform' => $p['platform'], 'architecture' => $p['architecture'], 'package_type' => $p['package_type'], 'country_code' => ['LK', 'LK', 'LK', 'IN', 'AE', 'GB', 'SG'][random_int(0, 6)], 'referrer_host' => ['worksuite.lk', 'google.com', null][random_int(0, 2)], 'user_agent_family' => ['Chrome', 'Edge', 'Safari', 'Firefox'][random_int(0, 3)], 'source' => 'web', 'is_bot' => random_int(1, 40) === 1 ? 1 : 0, 'redirect_ok' => 1, 'created_at' => gmdate('Y-m-d H:i:s', time() - random_int(0, 30 * 86400))];
            }
        }
        foreach (array_chunk($dl, 500) as $chunk) {
            $db->table('download_events')->insertBatch($chunk);
        }
        CLI::write(count($rows) . ' devices, ' . count($dl) . ' downloads', 'green');
    }

    private function installations(): void
    {
        $db = db_connect();
        $i  = 0;
        foreach ([['FREE', '3.4.0', 'SELF_HOSTED', 14], ['FREE', '3.3.2', 'SELF_HOSTED', 22], ['BUSINESS', '3.4.0', 'SELF_HOSTED', 9], ['BUSINESS', '3.3.2', 'SELF_HOSTED', 12], ['ENTERPRISE', '3.4.0', 'MANAGED', 5], ['ENTERPRISE', '3.3.2', 'MANAGED', 3], ['BUSINESS', '3.2.1', 'AIR_GAPPED', 2]] as [$ed, $v, $mode, $n]) {
            for ($k = 0; $k < $n; $k++) {
                $db->table('server_installations')->insert(['installation_id' => 'inst_' . strtoupper(bin2hex(random_bytes(5))), 'name' => $mode === 'MANAGED' ? 'hosted-' . (++$i) . '.worksuite.lk' : null, 'edition' => $ed, 'version' => $v, 'build' => (int) str_replace('.', '', $v) * 10, 'channel' => 'STABLE', 'mode' => $mode, 'php_version' => '8.3.12', 'db_version' => 'MariaDB 11.4', 'support_state' => $v === '3.2.1' ? 'DEPRECATED' : 'SUPPORTED', 'last_report_at' => gmdate('Y-m-d H:i:s', time() - random_int(0, 86400)), 'last_check_at' => gmdate('Y-m-d H:i:s', time() - random_int(0, 86400)), 'last_run_status' => $v === '3.4.0' ? 'COMPLETED' : null, 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
            }
        }
        $inst = $db->table('server_installations')->where('mode', 'MANAGED')->get()->getRowArray();
        $rel  = $db->table('releases')->where('version', '3.4.0')->get()->getRowArray();
        $db->table('server_update_events')->insert(['event_id' => bin2hex(random_bytes(8)), 'installation_id' => $inst['id'], 'release_id' => $rel['id'], 'type' => 'rolled_back', 'step' => 'migrate', 'error_code' => 'MIGRATION_FAILED', 'from_version' => '3.3.2', 'to_version' => '3.4.0', 'occurred_at' => gmdate('Y-m-d H:i:s', time() - 7200)]);
    }

    private function tenant(): void
    {
        $db = db_connect();
        $db->table('tenants')->insert(['public_id' => 'ten_DEMO', 'tenant_ref' => 'tnt_01J6DEMO', 'name' => 'Livezen hosted (app.worksuite.lk)', 'license_installation_id' => 'inst_HOSTED', 'status' => 'ACTIVE', 'last_catalog_sync_at' => gmdate('Y-m-d H:i:s', time() - 300), 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        unset($db);
        // Print a dev TPT so the update API can be tried by hand.
        [$rs, $rp] = TokenIssuer::keypair();
        [$is, $ip] = TokenIssuer::keypair();
        [$ps, $pp] = TokenIssuer::keypair();
        $inst = TokenIssuer::cert('INSTALLATION', 'inst-demo', $ip, 'ws-license-root-dev', $rs, ['installation_id' => 'inst_HOSTED', 'tenant_id' => 'tnt_01J6DEMO', 'edition' => 'ENTERPRISE']);
        $pol  = TokenIssuer::cert('TENANT_POLICY', 'tenant-policy-demo', $pp, 'inst-demo', $is, ['installation_id' => 'inst_HOSTED', 'tenant_id' => 'tnt_01J6DEMO']);
        $tpt  = TokenIssuer::encode(TokenIssuer::tpt(['tenant_id' => 'tnt_01J6DEMO', 'org_id' => 12, 'app_id' => 'lk.worksuite.desktop', 'channel' => 'beta', 'allowed_channels' => ['stable', 'beta'], 'org_minimum' => ['windows' => '2.6.5']], 'tenant-policy-demo', $ps, [$pol, $inst]));
        $lic  = TokenIssuer::encode(TokenIssuer::licenseToken(['apps' => ['lk.worksuite.server']], 'inst-demo', $is, $inst));
        file_put_contents(WRITEPATH . 'demo-tokens.env', "updatecenter.licenseRootKeys = '" . json_encode(['ws-license-root-dev' => Base64Url::encode($rp)]) . "'\n# X-WorkSuite-Tenant-Policy: {$tpt}\n# Authorization: License {$lic}\n");
        CLI::write('Dev license root + sample TPT/license token written to writable/demo-tokens.env', 'green');
    }
}

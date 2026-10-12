<?php

namespace App\Database\Seeds;

use App\Database\ServerChannels;
use App\Libraries\Ulid;
use CodeIgniter\Database\Seeder;

/**
 * Global channels (§4.2), seed applications (§4.1, §17.1), editions and the
 * indicative edition → module map (§17.3), a LOCAL dev storage provider and
 * Bug Trace projects. Idempotent.
 */
class CatalogSeeder extends Seeder
{
    public const EDITION_MODULES = [
        'FREE'       => ['core', 'mail', 'chat', 'meet', 'calendar', 'tasks', 'files', 'notes', 'contacts'],
        'BUSINESS'   => ['crm', 'hr', 'finance', 'projects', 'inventory', 'sales', 'expense'],
        'ENTERPRISE' => ['manufacturing', 'fleet', 'payroll', 'mailinfra', 'workforce', 'sso', 'scim', 'multinode'],
    ];

    public function run(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $channels = [
            ['STABLE', 'Stable', 'Recommended for everyone.', 1, 1, 1, ['audience' => 'anyone'], ['binaries' => 10], 1],
            ['BETA', 'Beta', 'Early access to upcoming features. Opt in from your portal account or through your organisation.', 0, 0, 1, ['audience' => 'signed_in_beta', 'tpt' => 'beta'], ['binaries' => 5], 2],
            ['DEV', 'Dev', 'Daily builds for WorkSuite developers and QA.', 0, 0, 1, ['audience' => 'staff', 'roles' => ['developer', 'qa', 'super_admin', 'release_manager'], 'tpt' => 'dev'], ['binaries' => 10], 3],
            ['INTERNAL', 'Internal', 'Livezen staff and internal tenants (QA installs).', 0, 0, 1, ['audience' => 'internal', 'tpt' => 'internal'], ['binaries' => 10], 4],
            ['CUSTOM', 'Custom', 'Named tenants / organisations only.', 0, 0, 1, ['audience' => 'allow_list', 'tenants' => []], ['binaries' => 10], 5],
        ];
        foreach ($channels as [$code, $name, $desc, $public, $default, $auto, $rule, $ret, $sort]) {
            if ($this->db->table('release_channels')->where('code', $code)->where('application_id', null)->countAllResults() === 0) {
                $this->db->table('release_channels')->insert([
                    'application_id' => null, 'code' => $code, 'name' => $name, 'description' => $desc,
                    'is_public' => $public, 'is_default' => $default, 'allow_direct_download' => $public ? 1 : 0,
                    'allow_auto_update' => $auto, 'access_rule' => json_encode($rule), 'retention' => json_encode($ret),
                    'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        $stableId = (int) $this->db->table('release_channels')->where('code', 'STABLE')->where('application_id', null)->get()->getRow('id');
        $channelIds = array_column($this->db->table('release_channels')->where('application_id', null)->get()->getResultArray(), 'id', 'code');

        $desktopPlatforms = [
            ['windows', 'x64', ['exe', 'msi'], 1, 'exe', '10.0.19045'],
            ['windows', 'arm64', ['exe'], 0, 'exe', '10.0.22621'],
            ['macos', 'arm64', ['dmg', 'pkg', 'zip'], 1, 'dmg', '12.0'],
            ['macos', 'x64', ['dmg', 'zip'], 1, 'dmg', '12.0'],
            ['linux', 'x64', ['appimage', 'deb', 'rpm'], 1, 'appimage', null],
        ];
        $apps = [
            ['WorkSuite Desktop', 'desktop', 'lk.worksuite.desktop', 'DESKTOP', 'The WorkSuite desktop app for Windows, macOS and Linux — Mail, Chat, Meet, Files and your business apps in one window, with offline support.', $desktopPlatforms, 1],
            // Updates on macOS through Sparkle, from GET /api/v1/apps/office/channels/{channel}/appcast.xml (SparkleAppcastService).
            ['WorkSuite Office', 'office', 'lk.worksuite.office', 'DESKTOP', 'Documents, spreadsheets and presentations on your computer, with your WorkSuite Drive files on its home screen.', [['macos', 'arm64', ['dmg', 'zip'], 1, 'dmg', '11.0'], ['macos', 'x64', ['dmg', 'zip'], 0, 'dmg', null], ['windows', 'x64', ['exe', 'zip'], 0, 'exe', null], ['linux', 'x64', ['deb', 'rpm'], 0, 'deb', null]], 7],
            ['WorkSuite Installer', 'installer', 'lk.worksuite.installer', 'DESKTOP', 'Guided installer for self-hosted WorkSuite servers.', [['linux', 'x64', ['appimage', 'deb', 'rpm'], 1, 'deb', null], ['windows', 'x64', ['exe'], 0, 'exe', null]], 2],
            ['WorkSuite Quick Support', 'quick-support', 'lk.worksuite.quicksupport', 'DESKTOP', 'Let Livezen support connect to your computer securely for a single session.', [['windows', 'x64', ['exe'], 1, 'exe', null], ['macos', 'arm64', ['dmg'], 1, 'dmg', null], ['macos', 'x64', ['dmg'], 0, 'dmg', null]], 3],
            ['WorkSuite Sync Agent', 'sync-agent', 'lk.worksuite.syncagent', 'DESKTOP', 'Background file sync for WorkSuite Drive.', [['windows', 'x64', ['msi'], 1, 'msi', null], ['macos', 'arm64', ['pkg'], 1, 'pkg', null], ['linux', 'x64', ['deb', 'rpm'], 1, 'deb', null]], 4],
            ['WorkSuite Server Agent', 'server-agent', 'lk.worksuite.serveragent', 'DESKTOP', 'Monitoring and update agent for WorkSuite servers.', [['linux', 'x64', ['deb', 'rpm'], 1, 'deb', null], ['linux', 'arm64', ['deb', 'rpm'], 0, 'deb', null]], 5],
            ['WorkSuite Server (Web)', 'server', 'lk.worksuite.server', 'SERVER', 'The complete WorkSuite web system — Free, Business and Enterprise editions — for self-hosted installations.', [['server', 'any', ['zip'], 1, 'zip', null]], 6],
        ];
        foreach ($apps as [$name, $slug, $ident, $kind, $desc, $platforms, $sort]) {
            if ($this->db->table('applications')->where('slug', $slug)->countAllResults() > 0) {
                continue;
            }
            $this->db->table('applications')->insert([
                'public_id' => Ulid::prefixed('app'), 'name' => $name, 'slug' => $slug, 'app_identifier' => $ident, 'kind' => $kind,
                'description' => $desc, 'status' => 'ACTIVE', 'public_download_enabled' => 1, 'private' => 0,
                'requires_license_token' => 0, 'default_channel_id' => $stableId, 'show_previous_versions' => 1,
                'manifest_settings' => json_encode(['schema' => 2, 'update_api_enabled' => true]),
                'security_settings' => json_encode(['malware_scan_required' => true, 'signatures' => ['windows' => 'authenticode', 'macos' => 'developer_id_notarized', 'linux' => 'gpg']]),
                'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $appId = (int) $this->db->insertID();
            foreach ($platforms as [$platform, $arch, $types, $required, $default, $minOs]) {
                $this->db->table('application_platforms')->insert([
                    'application_id' => $appId, 'platform' => $platform, 'architecture' => $arch, 'package_types' => json_encode($types),
                    'required_for_publish' => $required, 'default_package_type' => $default, 'min_os_version' => $minOs,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach (['STABLE', 'BETA', 'DEV', 'INTERNAL'] as $code) {
                $this->db->table('application_channels')->insert(['application_id' => $appId, 'channel_id' => $channelIds[$code], 'enabled' => 1]);
            }
            $this->db->table('download_pages')->insert([
                'application_id' => $appId, 'locale' => 'en',
                'hero' => json_encode(['title' => $name, 'subtitle' => $desc]),
                'description_md' => $desc, 'requirements' => json_encode(self::requirements($kind)),
                'install_help_md' => json_encode(self::installHelp($kind)), 'faq' => json_encode(self::faq($kind)),
                'support_url' => 'https://support.worksuite.lk', 'status' => 'PUBLISHED', 'version' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach ([['FREE', 'Free', 1, 1, 'Core collaboration suite for small teams.'], ['BUSINESS', 'Business', 2, 0, 'Adds CRM, HR, Finance, Projects, Inventory, Sales and Expenses.'], ['ENTERPRISE', 'Enterprise', 3, 0, 'Adds Manufacturing, Fleet, Payroll, Mail Infrastructure, Workforce Monitoring, SSO/SCIM and multi-node.'], ['CUSTOM', 'Custom', 4, 0, 'Customer-specific module sets (later).']] as [$code, $name, $rank, $public, $desc]) {
            if ($this->db->table('editions')->where('code', $code)->countAllResults() === 0) {
                $this->db->table('editions')->insert(['code' => $code, 'name' => $name, 'rank' => $rank, 'public_download' => $public, 'description' => $desc, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach (self::EDITION_MODULES as $edition => $modules) {
            foreach ($modules as $m) {
                if ($this->db->table('edition_modules')->where('edition_code', $edition)->where('module_slug', $m)->countAllResults() === 0) {
                    $this->db->table('edition_modules')->insert(['edition_code' => $edition, 'module_slug' => $m, 'since_version' => '3.0.0', 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }

        ServerChannels::ensureLts($this->db);

        if ($this->db->table('storage_providers')->countAllResults() === 0) {
            $this->db->table('storage_providers')->insert([
                'name' => 'Local (dev)', 'kind' => 'LOCAL', 'bucket' => 'worksuite-releases', 'region' => null, 'endpoint' => null,
                'cdn_host' => null, 'credentials_ref' => null, 'is_active' => 1, 'versioning' => 0,
                'replication' => json_encode([]), 'status' => 'UNKNOWN', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $desktopId = (int) $this->db->table('applications')->where('slug', 'desktop')->get()->getRow('id');
        $serverId  = (int) $this->db->table('applications')->where('slug', 'server')->get()->getRow('id');
        foreach ([[$serverId, 'WorkSuite Web client', 'web', 'WS-WEB', 'pk_web_dev_0000000000000001'], [$desktopId, 'WorkSuite Desktop', 'desktop', 'WS-DSK', 'pk_desktop_dev_00000000000001'], [$desktopId, 'WorkSuite Mobile', 'mobile', 'WS-MOB', 'pk_mobile_dev_000000000000001'], [$serverId, 'WorkSuite Server', 'server', 'WS-SRV', 'pk_server_dev_000000000000001']] as [$appId, $name, $platform, $prefix, $key]) {
            if ($this->db->table('bt_projects')->where('public_key', $key)->countAllResults() === 0) {
                $this->db->table('bt_projects')->insert([
                    'application_id' => $appId, 'name' => $name, 'platform' => $platform, 'issue_prefix' => $prefix,
                    'environment' => 'production', 'public_key' => $key, 'rate_limit' => 600,
                    'sample_rates' => json_encode(['crash' => 1.0, 'error' => 0.25, 'hang' => 1.0, 'message' => 1.0]),
                    'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    private static function requirements(string $kind): array
    {
        if ($kind === 'SERVER') {
            return ['server' => ['PHP 8.2 – 8.4 with intl, sodium, imap, mbstring, gd', 'MariaDB 10.6+ or MySQL 8.0+', 'Redis 7', '4 vCPU, 8 GB RAM, 20 GB free disk', 'Linux (Ubuntu 22.04/24.04, Debian 12, RHEL 9)']];
        }

        return [
            'windows' => ['Windows 10 22H2 (build 19045) or later, 64-bit', 'x64 or ARM64 processor', '4 GB RAM (8 GB recommended)', '1 GB free disk space'],
            'macos'   => ['macOS 12 Monterey or later', 'Apple Silicon or Intel', '4 GB RAM', '1 GB free disk space'],
            'linux'   => ['Ubuntu 22.04+, Debian 12+, Fedora 39+ or RHEL 9+', 'x64 processor', 'glibc 2.35+', '4 GB RAM'],
        ];
    }

    private static function installHelp(string $kind): array
    {
        if ($kind === 'SERVER') {
            return ['server' => "Existing installations update from **Admin Center › System › Updates**. For air-gapped servers, download the edition ZIP and its signed release manifest, then upload both in **System › Updates › Manual package**."];
        }

        return [
            'windows' => "Run the downloaded **.exe** and follow the prompts. IT administrators can deploy the **.msi** with Intune or Group Policy (`msiexec /i WorkSuiteDesktop.msi /qn`).",
            'macos'   => "Open the **.dmg** and drag WorkSuite to Applications. Use the **.pkg** for MDM deployment.",
            'linux'   => "**AppImage:** `chmod +x WorkSuite-*.AppImage && ./WorkSuite-*.AppImage`\n\n**Debian/Ubuntu:** `sudo apt install ./worksuite-desktop_*.deb`\n\n**Fedora/RHEL:** `sudo dnf install ./worksuite-desktop-*.rpm`",
        ];
    }

    private static function faq(string $kind): array
    {
        return [
            ['q' => 'How do I verify my download?', 'a' => 'Compare the SHA-256 of the file with the value in `checksums.txt` on the release page. Installers are also signed (Authenticode on Windows, Developer ID + notarization on macOS, GPG on Linux).'],
            ['q' => 'Which Windows download should I choose?', 'a' => 'Most PCs use **x64**. Surface Pro X, Snapdragon and other Arm laptops use **ARM64** — check *Settings › System › About › System type*.'],
            ['q' => 'Can I install an older version?', 'a' => 'Supported and deprecated versions are listed under *Previous versions*. Unsupported versions cannot be downloaded because they no longer receive security fixes.'],
        ];
    }
}

<?php

namespace App\Database\Migrations;

use App\Database\ServerInstallGuide as Guide;
use CodeIgniter\Database\Migration;

/**
 * The WorkSuite Server download page's installation guide: the one-command installer
 * (/install.sh) for Ubuntu, Debian, RHEL / Rocky / AlmaLinux 9 and CentOS 7. Written as a new
 * page version (download_page_versions keeps the previous one), so it stays editable in /admin.
 */
class ServerInstallGuide extends Migration
{

    public function up(): void
    {
        $db  = $this->db;
        $app = $db->table('applications')->where('slug', 'server')->get()->getRowArray();
        if (! $app) {
            return;
        }
        $page = $db->table('download_pages')->where('application_id', $app['id'])->where('locale', 'en')->get()->getRowArray();
        if (! $page) {
            return;
        }
        $help  = json_decode((string) $page['install_help_md'], true) ?: [];
        $req   = json_decode((string) $page['requirements'], true) ?: [];
        $help['server'] = Guide::GUIDE;
        $req['server']  = Guide::REQUIREMENTS;
        $now   = gmdate('Y-m-d H:i:s');
        $set   = ['install_help_md' => json_encode($help), 'requirements' => json_encode($req), 'version' => (int) $page['version'] + 1, 'updated_at' => $now];
        $db->table('download_pages')->where('id', $page['id'])->update($set);
        $row = $db->table('download_pages')->where('id', $page['id'])->get()->getRowArray();
        if ($db->tableExists('download_page_versions')) {
            $db->table('download_page_versions')->insert([
                'application_id' => $app['id'], 'locale' => 'en', 'version' => $row['version'],
                'snapshot' => json_encode(array_intersect_key($row, array_flip(['hero', 'description_md', 'requirements', 'install_help_md', 'faq', 'support_url']))),
                'updated_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // The previous text is in download_page_versions; restore it from /admin if needed.
    }
}

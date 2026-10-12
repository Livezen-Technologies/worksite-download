<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * The entitlement catalog (IMPL-LIC-01 §5, P0): what a WorkSuite Server licence can grant, as keys
 * (`crm.enabled`, `core.users.max`, `updates.channel.lts`), the value each edition gives each key,
 * and per-licence add-ons. Editions are entitlement packages; servers never compare edition names.
 *
 * The seed is the plan's placeholder table, for commercial sign-off: every WorkSuite Server module
 * gets `<module>.enabled`; Enterprise has them all, Business all but the add-on apps, Professional
 * the collaboration core plus CRM.
 */
class CreateEntitlementCatalog extends Migration
{
    use SchemaHelper;

    /** WorkSuite Server 3.1.0 app/Modules, less the platform (never licensed): slug => label. */
    public const MODULES = [
        'ai' => 'AI', 'amc' => 'AMC', 'analytics' => 'Analytics', 'appointments' => 'Appointments', 'asset' => 'Assets',
        'billing' => 'Billing', 'calendar' => 'Calendar', 'cms' => 'Website CMS', 'communication' => 'Chat & Communication',
        'contacts' => 'Contacts', 'crm' => 'CRM', 'customersubscription' => 'Customer Subscriptions', 'diagram' => 'Diagrams',
        'docs' => 'Docs', 'elearning' => 'eLearning', 'eurooffice' => 'Office documents', 'eventmanager' => 'Events',
        'expense' => 'Expenses', 'files' => 'Files', 'finance' => 'Finance', 'forms' => 'Forms', 'garage' => 'Garage',
        'hosting' => 'Hosting', 'hr' => 'HR', 'inventory' => 'Inventory', 'mail' => 'Mail', 'marketing' => 'Marketing',
        'meeting' => 'Meet', 'notes' => 'Notes', 'oms' => 'Orders', 'payroll' => 'Payroll', 'pdf' => 'PDF Studio',
        'pms' => 'Property management', 'pos' => 'Point of Sale', 'project' => 'Projects', 'rental' => 'Rental',
        'restaurant' => 'Restaurant', 'salon' => 'SalonMate', 'social' => 'Social', 'support' => 'Helpdesk',
        'tasks' => 'Tasks', 'techhub' => 'TechHub', 'tender' => 'Tenders', 'urldownloader' => 'URL downloader',
        'voip' => 'Phone (VoIP)', 'whatsapp' => 'WhatsApp', 'zip' => 'Archives',
    ];

    /** Professional (free): collaboration core plus CRM (plan §5: "crm only"). */
    private const PROFESSIONAL = ['calendar', 'communication', 'contacts', 'crm', 'docs', 'eurooffice', 'files', 'mail', 'meeting', 'notes', 'pdf', 'tasks', 'whatsapp', 'zip'];

    /** Add-on apps for Business; included in Enterprise (plan §5). */
    private const ADDONS = ['ai', 'cms', 'garage', 'salon', 'restaurant', 'hosting', 'pms', 'techhub'];

    public function up(): void
    {
        $this->table('entitlements', [
            'key'         => $this->str(96),
            'label'       => $this->str(191),
            'kind'        => $this->str(16, false, 'bool'), // bool | limit | value
            'category'    => $this->str(32, false, 'apps'), // apps | limits | updates | services | support
            'module_slug' => $this->str(64, true),
            'description' => $this->text(),
            'sort_order'  => $this->int(),
        ], ['category'], ['key']);

        $this->table('edition_entitlements', [
            'edition_code'    => $this->str(16),
            'entitlement_key' => $this->str(96),
            'value'           => $this->text(false), // JSON: true/false, a number, null (= unlimited), a string
        ], ['entitlement_key'], [['edition_code', 'entitlement_key']]);

        $this->table('license_entitlements', [
            'license_id'      => $this->fk(),
            'entitlement_key' => $this->str(96),
            'value'           => $this->text(false),
            'note'            => $this->str(255, true),
            'created_by'      => $this->str(191, true),
        ], ['license_id'], [['license_id', 'entitlement_key']]);

        $this->seed();
    }

    private function seed(): void
    {
        $now  = gmdate('Y-m-d H:i:s');
        $rows = [];
        $sort = 0;
        foreach (self::MODULES as $slug => $label) {
            $rows[] = ["{$slug}.enabled", $label, 'bool', 'apps', $slug, "Use {$label}.", ++$sort];
        }
        foreach ([
            ['core.users.max', 'Users', 'limit', 'limits', 'Active user accounts. Empty: unlimited.'],
            ['core.organizations.max', 'Organisations', 'limit', 'limits', 'Companies on this server. Empty: unlimited.'],
            ['storage.max_bytes', 'Storage', 'limit', 'limits', 'File storage in bytes. Empty: unlimited.'],
            ['updates.core', 'Core updates', 'bool', 'updates', 'WorkSuite Server releases.'],
            ['updates.apps', 'App updates', 'bool', 'updates', 'Module, app, theme and plugin updates.'],
            ['updates.channel.lts', 'LTS channel', 'bool', 'updates', 'Long-term support releases.'],
            ['updates.default_mode', 'Default automatic updates', 'value', 'updates', 'NOTIFY, AUTO_SECURITY, AUTO_MINOR or AUTO_ALL for a new server.'],
            ['offline.license.enabled', 'Offline licence', 'bool', 'services', 'Activate and renew without internet (.wsr / .wsl).'],
            ['backup.cloud.enabled', 'Cloud backup', 'bool', 'services', 'WorkSuite Cloud Backup.'],
            ['ai.cloud.enabled', 'AI Cloud', 'bool', 'services', 'WorkSuite AI Pro (cloud models).'],
            ['support.priority', 'Support', 'value', 'support', 'community, standard, priority or dedicated.'],
        ] as [$k, $l, $kind, $cat, $d]) {
            $rows[] = [$k, $l, $kind, $cat, null, $d, ++$sort];
        }
        foreach ($rows as [$k, $l, $kind, $cat, $mod, $d, $s]) {
            if ($this->db->table('entitlements')->where('key', $k)->countAllResults() === 0) {
                $this->db->table('entitlements')->insert(['key' => $k, 'label' => $l, 'kind' => $kind, 'category' => $cat, 'module_slug' => $mod, 'description' => $d, 'sort_order' => $s, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $values = ['FREE' => [], 'BUSINESS' => [], 'ENTERPRISE' => []];
        foreach (array_keys(self::MODULES) as $slug) {
            $values['FREE']["{$slug}.enabled"]       = in_array($slug, self::PROFESSIONAL, true);
            $values['BUSINESS']["{$slug}.enabled"]   = ! in_array($slug, self::ADDONS, true);
            $values['ENTERPRISE']["{$slug}.enabled"] = true;
        }
        $limits = [
            'core.users.max'          => [5, 50, null],
            'core.organizations.max'  => [1, 5, null],
            'storage.max_bytes'       => [2 * 1024 ** 3, null, null],
            'updates.core'            => [true, true, true],
            'updates.apps'            => [true, true, true],
            'updates.channel.lts'     => [false, false, true],
            'updates.default_mode'    => ['NOTIFY', 'AUTO_SECURITY', 'NOTIFY'],
            'offline.license.enabled' => [false, false, true],
            'backup.cloud.enabled'    => [false, true, true],
            'ai.cloud.enabled'        => [false, false, false],
            'support.priority'        => ['community', 'standard', 'priority'],
        ];
        foreach ($limits as $k => [$f, $b, $e]) {
            $values['FREE'][$k]       = $f;
            $values['BUSINESS'][$k]   = $b;
            $values['ENTERPRISE'][$k] = $e;
        }
        foreach ($values as $edition => $set) {
            foreach ($set as $k => $v) {
                if ($this->db->table('edition_entitlements')->where('edition_code', $edition)->where('entitlement_key', $k)->countAllResults() === 0) {
                    $this->db->table('edition_entitlements')->insert(['edition_code' => $edition, 'entitlement_key' => $k, 'value' => json_encode($v), 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('license_entitlements', true);
        $this->forge->dropTable('edition_entitlements', true);
        $this->forge->dropTable('entitlements', true);
    }
}

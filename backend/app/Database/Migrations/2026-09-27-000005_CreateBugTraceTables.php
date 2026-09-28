<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Bug Trace: projects, issues, events, sessions, user reports, attachments,
 * symbols, alerts, scrub rules and tenant policies (§18.13).
 */
class CreateBugTraceTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('bt_projects', [
            'application_id' => $this->fk(),
            'name'           => $this->str(150),
            'platform'       => $this->str(16), // web | desktop | mobile | server
            'issue_prefix'   => $this->str(16), // WS-WEB, WS-DSK, WS-MOB, WS-SRV
            'environment'    => $this->str(32, false, 'production'),
            'public_key'     => $this->str(64),
            'rate_limit'     => $this->int(false, 600), // events/min per installation
            'sample_rates'   => $this->text(),
            'quota_daily'    => $this->int(false, 200000),
            'status'         => $this->str(16, false, 'ACTIVE'),
        ], ['application_id'], ['public_key']);

        $this->table('bt_issues', [
            'public_id'             => $this->str(32),
            'project_id'            => $this->fk(),
            'fingerprint'           => $this->str(64),
            'type'                  => $this->str(16), // crash | error | hang | message
            'title'                 => $this->str(255),
            'culprit'               => $this->str(255, true),
            'status'                => $this->str(16, false, 'NEW'),
            'resolution'            => $this->str(16, true), // NOW | NEXT_RELEASE | VERSION
            'resolve_version'       => $this->str(64, true),
            'ignore_until'          => $this->text(), // JSON condition
            'priority'              => $this->str(4, false, 'P3'),
            'assignee_id'           => $this->fk(true),
            'merged_into_id'        => $this->fk(true),
            'first_seen'            => $this->dt(),
            'last_seen'             => $this->dt(),
            'first_release_id'      => $this->fk(true),
            'first_release'         => $this->str(80, true),
            'last_release'          => $this->str(80, true),
            'resolved_in_release_id' => $this->fk(true),
            'resolved_at'           => $this->dt(),
            'regressed_at'          => $this->dt(),
            'events_count'          => $this->bigint(),
            'users_count'           => $this->bigint(),
            'installations_count'   => $this->bigint(),
            'labels'                => $this->text(),
            'known_issue_public'    => $this->text(),
        ], [['project_id', 'status'], 'last_seen'], ['public_id', ['project_id', 'fingerprint']]);

        $this->table('bt_events', [
            'event_id'           => $this->str(64),
            'issue_id'           => $this->fk(true),
            'project_id'         => $this->fk(),
            'release_id'         => $this->fk(true),
            'release'            => $this->str(80, true),
            'release_known'      => $this->bool(true),
            'installation_ref'   => $this->str(128, true),
            'tenant_ref'         => $this->str(64, true),
            'edition'            => $this->str(16, true),
            'user_ref'           => $this->str(128, true),
            'session_id'         => $this->str(64, true),
            'type'               => $this->str(16),
            'environment'        => $this->str(32, true),
            'timestamp'          => $this->dt(),
            'platform'           => $this->str(16, true),
            'os'                 => $this->str(64, true),
            'browser'            => $this->str(64, true),
            'country_code'       => $this->str(2, true),
            'device'             => $this->text(),
            'exception'          => $this->longtext(),
            'contexts'           => $this->longtext(),
            'breadcrumbs'        => $this->longtext(),
            'tags'               => $this->text(),
            'symbolicated_frames' => $this->longtext(),
            'scrub_count'        => $this->int(),
            'processed_at'       => $this->dt(),
        ], [['issue_id', 'timestamp'], ['project_id', 'timestamp'], 'user_ref', 'installation_ref'], ['event_id'], false);

        $this->table('bt_issue_users', [
            'issue_id' => $this->fk(),
            'ref'      => $this->str(128),
            'kind'     => $this->str(8), // user | inst
        ], [], [['issue_id', 'kind', 'ref']], false);

        $this->table('bt_issue_releases', [
            'issue_id'   => $this->fk(),
            'release'    => $this->str(80),
            'events'     => $this->bigint(),
            'first_seen' => $this->dt(),
            'last_seen'  => $this->dt(),
        ], [], [['issue_id', 'release']], false);

        $this->table('bt_issue_stats_hourly', [
            'issue_id'      => $this->fk(),
            'hour'          => $this->dt(false),
            'events'        => $this->bigint(),
            'users'         => $this->bigint(),
            'installations' => $this->bigint(),
        ], [], [['issue_id', 'hour']], false);

        $this->table('bt_sessions_daily', [
            'project_id'    => $this->fk(),
            'release'       => $this->str(80),
            'release_id'    => $this->fk(true),
            'date'          => ['type' => 'DATE', 'null' => false],
            'sessions'      => $this->bigint(),
            'crashed'       => $this->bigint(),
            'abnormal'      => $this->bigint(),
            'users'         => $this->bigint(),
            'crashed_users' => $this->bigint(),
        ], [], [['project_id', 'release', 'date']], false);

        $this->table('bt_session_users', [
            'project_id' => $this->fk(),
            'release'    => $this->str(80),
            'date'       => ['type' => 'DATE', 'null' => false],
            'user_ref'   => $this->str(128),
            'crashed'    => $this->bool(),
        ], [], [['project_id', 'release', 'date', 'user_ref']], false);

        $this->table('bt_reports', [
            'reference'          => $this->str(32),
            'client_report_id'   => $this->str(64, true),
            'project_id'         => $this->fk(),
            'release_id'         => $this->fk(true),
            'release'            => $this->str(80, true),
            'title'              => $this->str(255),
            'description'        => $this->text(),
            'steps'              => $this->text(),
            'expected'           => $this->text(),
            'severity'           => $this->str(20),
            'category'           => $this->str(20),
            'status'             => $this->str(20, false, 'UNTRIAGED'),
            'issue_id'           => $this->fk(true),
            'suggested_issue_id' => $this->fk(true),
            'related_event_id'   => $this->str(64, true),
            'context'            => $this->longtext(),
            'contact_consent'    => $this->bool(),
            'contact_email_enc'  => $this->text(),
            'user_ref'           => $this->str(128, true),
            'portal_user_id'     => $this->fk(true),
            'installation_ref'   => $this->str(128, true),
            'tenant_ref'         => $this->str(64, true),
            'source'             => $this->str(16, false, 'app'), // app | public
            'status_token_hash'  => $this->str(64),
            'closed_at'          => $this->dt(),
            'close_reason'       => $this->text(),
        ], ['status', 'issue_id', 'user_ref'], ['reference', ['project_id', 'client_report_id']]);

        $this->table('bt_report_updates', [
            'report_id' => $this->fk(),
            'status'    => $this->str(20),
            'message'   => $this->text(),
            'public'    => $this->bool(true),
        ], ['report_id']);

        $this->table('bt_attachments', [
            'public_id'   => $this->str(40),
            'project_id'  => $this->fk(),
            'owner_type'  => $this->str(16, true), // event | report
            'owner_id'    => $this->str(64, true),
            'kind'        => $this->str(16), // minidump | screenshot | log | recording | file
            'storage_key' => $this->str(512),
            'content_type' => $this->str(100, true),
            'size'        => $this->bigint(),
            'sha256'      => $this->str(64, true),
            'meta'        => $this->text(),
            'status'      => $this->str(16, false, 'PENDING'), // PENDING | STORED
            'expires_at'  => $this->dt(),
        ], [['owner_type', 'owner_id']], ['public_id']);

        $this->table('bt_symbols', [
            'release_id'  => $this->fk(true),
            'release'     => $this->str(80),
            'project_id'  => $this->fk(true),
            'platform'    => $this->str(16),
            'kind'        => $this->str(16), // sourcemap | hermes | dsym | pdb | breakpad | r8
            'debug_id'    => $this->str(128, true),
            'file_name'   => $this->str(255),
            'storage_key' => $this->str(512),
            'size'        => $this->bigint(),
            'uploaded_by' => $this->str(64, true),
            'uploaded_at' => $this->dt(),
        ], ['release', 'debug_id']);

        $this->table('bt_comments', [
            'target_type' => $this->str(16), // issue | report
            'target_id'   => $this->fk(),
            'user_id'     => $this->fk(true),
            'body'        => $this->text(false),
            'kind'        => $this->str(16, false, 'COMMENT'), // COMMENT | ACTIVITY
        ], [['target_type', 'target_id']]);

        $this->table('bt_issue_links', [
            'issue_id' => $this->fk(),
            'kind'     => $this->str(16), // github | task | support
            'ref'      => $this->str(255),
            'url'      => $this->str(512, true),
            'created_by' => $this->fk(true),
        ], ['issue_id']);

        $this->table('bt_alert_rules', [
            'name'       => $this->str(150),
            'project_id' => $this->fk(true),
            'condition'  => $this->str(32), // new_issue | regression | spike | threshold | crash_free_below
            'threshold'  => $this->text(), // JSON
            'channels'   => $this->text(), // JSON [in_app, email, webhook]
            'webhook_url' => $this->str(512, true),
            'quiet_hours' => $this->str(32, true),
            'digest'     => $this->bool(),
            'enabled'    => $this->bool(true),
            'created_by' => $this->fk(true),
        ]);

        $this->table('bt_alert_events', [
            'rule_id'  => $this->fk(true),
            'issue_id' => $this->fk(true),
            'kind'     => $this->str(32),
            'message'  => $this->text(),
            'payload'  => $this->text(),
        ], ['rule_id']);

        $this->table('bt_scrub_rules', [
            'name'        => $this->str(100),
            'pattern'     => $this->str(512),
            'replacement' => $this->str(64, false, '[Filtered]'),
            'scope'       => $this->str(16, false, 'global'),
            'project_id'  => $this->fk(true),
            'enabled'     => $this->bool(true),
        ]);

        $this->table('bt_fingerprint_rules', [
            'project_id'  => $this->fk(true),
            'match_type'  => $this->str(64, true),
            'match_value' => $this->str(255, true),
            'fingerprint' => $this->str(128),
            'enabled'     => $this->bool(true),
        ]);

        $this->table('bt_tenant_policies', [
            'tenant_ref'       => $this->str(64),
            'policy'           => $this->str(24, false, 'CRASHES_AND_ERRORS'),
            'user_reports'     => $this->bool(true),
            'screenshots'      => $this->bool(true),
            'logs'             => $this->bool(true),
            'server_reporting' => $this->bool(true),
            'required_by_contract' => $this->bool(),
            'updated_by'       => $this->str(64, true),
        ], [], ['tenant_ref']);

        $this->table('bt_quota_counters', [
            'bucket'  => $this->str(191),
            'window'  => $this->str(16),
            'count'   => $this->bigint(),
            'dropped' => $this->bigint(),
        ], [], [['bucket', 'window']], false);
    }

    public function down(): void
    {
        foreach (['bt_quota_counters', 'bt_tenant_policies', 'bt_fingerprint_rules', 'bt_scrub_rules', 'bt_alert_events', 'bt_alert_rules', 'bt_issue_links', 'bt_comments', 'bt_symbols', 'bt_attachments', 'bt_report_updates', 'bt_reports', 'bt_session_users', 'bt_sessions_daily', 'bt_issue_stats_hourly', 'bt_issue_releases', 'bt_issue_users', 'bt_events', 'bt_issues', 'bt_projects'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}

<?php

namespace App\Database\Migrations;

use App\Database\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Applications, channels, releases, packages, revisions, uploads, changelogs,
 * manifests, attestations, rollouts, minimum versions, download pages (§4, §11).
 */
class CreateCatalogTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('applications', [
            'public_id'               => $this->str(40),
            'name'                    => $this->str(150),
            'slug'                    => $this->str(100),
            'app_identifier'          => $this->str(150),
            'kind'                    => $this->str(16, false, 'DESKTOP'), // DESKTOP | SERVER | MOBILE | OTHER
            'description'             => $this->text(),
            'icon_asset'              => $this->str(255, true),
            'status'                  => $this->str(16, false, 'ACTIVE'), // ACTIVE | HIDDEN | RETIRED
            'public_download_enabled' => $this->bool(true),
            'private'                 => $this->bool(),
            'requires_license_token'  => $this->bool(),
            'default_channel_id'      => $this->fk(true),
            'show_previous_versions'  => $this->bool(true),
            'manifest_settings'       => $this->text(),
            'security_settings'       => $this->text(),
            'sort_order'              => $this->int(),
        ], ['status'], ['slug', 'app_identifier', 'public_id']);

        $this->table('application_platforms', [
            'application_id'       => $this->fk(),
            'platform'             => $this->str(16), // windows | macos | linux | android | ios | server
            'architecture'         => $this->str(16), // x64 | arm64 | universal | any
            'package_types'        => $this->text(false), // JSON list
            'required_for_publish' => $this->bool(true),
            'default_package_type' => $this->str(16, true),
            'min_os_version'       => $this->str(40, true),
        ], [], [['application_id', 'platform', 'architecture']]);

        $this->table('release_channels', [
            'application_id'        => $this->fk(true), // NULL = global
            'code'                  => $this->str(32), // STABLE | BETA | DEV | INTERNAL | CUSTOM
            'name'                  => $this->str(100),
            'description'           => $this->text(),
            'is_public'             => $this->bool(),
            'is_default'            => $this->bool(),
            'allow_direct_download' => $this->bool(),
            'allow_auto_update'     => $this->bool(true),
            'access_rule'           => $this->text(), // JSON
            'retention'             => $this->text(), // JSON
            'sort_order'            => $this->int(),
        ], ['code'], [['application_id', 'code']]);

        $this->table('application_channels', [
            'application_id' => $this->fk(),
            'channel_id'     => $this->fk(),
            'enabled'        => $this->bool(true),
        ], [], [['application_id', 'channel_id']], false);

        $this->table('releases', [
            'public_id'                 => $this->str(40),
            'application_id'            => $this->fk(),
            'channel_id'                => $this->fk(),
            'version'                   => $this->str(64),
            'version_major'             => $this->int(),
            'version_minor'             => $this->int(),
            'version_patch'             => $this->int(),
            'version_pre'               => $this->str(64, true),
            'version_sort'              => $this->str(64), // zero-padded sortable key
            'build_number'              => $this->bigint(),
            'build_revision'            => $this->int(),
            'release_type'              => $this->str(16, false, 'RECOMMENDED'), // OPTIONAL | RECOMMENDED | MANDATORY | CRITICAL
            'status'                    => $this->str(20, false, 'DRAFT'),
            'support_state'             => $this->str(16, false, 'SUPPORTED'),
            'visibility'                => $this->str(16, false, 'PUBLIC'), // PUBLIC | HIDDEN
            'minimum_supported_version' => $this->str(64, true),
            'minimum_no_change'         => $this->bool(),
            'mandatory'                 => $this->bool(),
            'mandatory_after'           => $this->dt(),
            'grace_minutes'             => $this->int(true, null),
            'rollback_supported'        => $this->bool(true),
            'migration_compatibility'   => $this->text(),
            'notes'                     => $this->text(),
            'known_issues'              => $this->text(),
            'requirements_override'     => $this->text(), // JSON
            'release_date'              => $this->dt(),
            'scheduled_publish_at'      => $this->dt(),
            'auto_pause_on_gate'        => $this->bool(),
            'qa_notes'                  => $this->text(),
            'published_at'              => $this->dt(),
            'published_by'              => $this->fk(true),
            'created_by'                => $this->fk(true),
            'created_by_client'         => $this->fk(true),
            'paused_at'                 => $this->dt(),
            'withdrawn_at'              => $this->dt(),
            'withdrawn_by'              => $this->fk(true),
            'withdraw_reason'           => $this->text(),
            'superseded_at'             => $this->dt(),
            'superseded_by_release_id'  => $this->fk(true),
            'last_error'                => $this->text(),
        ], [['application_id', 'channel_id', 'status'], 'status', 'version_sort'], [['application_id', 'channel_id', 'version'], 'public_id']);

        $this->table('release_approvals', [
            'release_id' => $this->fk(),
            'kind'       => $this->str(16), // QA | RELEASE
            'decision'   => $this->str(16), // PASS | FAIL | APPROVED | REJECTED
            'platform'   => $this->str(16, true),
            'user_id'    => $this->fk(),
            'comment'    => $this->text(),
        ], [['release_id', 'kind']]);

        $this->table('release_packages', [
            'public_id'          => $this->str(40),
            'release_id'         => $this->fk(),
            'platform'           => $this->str(16),
            'architecture'       => $this->str(16),
            'package_type'       => $this->str(16),
            'edition'            => $this->str(16, false, ''), // '' for desktop; FREE|BUSINESS|ENTERPRISE|ALL for server (exposed as null)
            'scope'              => $this->str(16, true), // full | core-patch
            'active_revision_id' => $this->fk(true),
            'minimum_os_version' => $this->str(40, true),
            'status'             => $this->str(16, false, 'EMPTY'), // EMPTY | PROCESSING | READY | REJECTED
            'health'             => $this->str(16, false, 'UNKNOWN'), // OK | DEGRADED | UNKNOWN
            'health_checked_at'  => $this->dt(),
        ], ['release_id'], [['release_id', 'platform', 'architecture', 'package_type', 'edition'], 'public_id']);

        $this->table('release_package_revisions', [
            'public_id'           => $this->str(40),
            'release_package_id'  => $this->fk(),
            'revision_number'     => $this->int(false, 1),
            'storage_provider_id' => $this->fk(true),
            'storage_object_key'  => $this->str(512, true),
            'quarantine_key'      => $this->str(512, true),
            'file_name'           => $this->str(255),
            'size_bytes'          => $this->bigint(),
            'sha256'              => $this->str(64, true),
            'signature_status'    => $this->str(20, false, 'PENDING'), // PENDING | VALID | INVALID | SKIPPED | NOT_REQUIRED
            'signature_detail'    => $this->text(),
            'scan_status'         => $this->str(20, false, 'PENDING'), // PENDING | CLEAN | INFECTED | SKIPPED | ERROR
            'scan_detail'         => $this->text(),
            'metadata'            => $this->text(), // JSON
            'attestation_match'   => $this->str(16, false, 'PENDING'), // PENDING | MATCH | MISMATCH | NO_ATTESTATION
            'pipeline'            => $this->text(), // JSON step statuses
            'status'              => $this->str(16, false, 'PROCESSING'), // UPLOADING | PROCESSING | READY | REJECTED | REPLACED
            'reject_code'         => $this->str(40, true),
            'replace_reason'      => $this->text(),
            'uploaded_by'         => $this->fk(true),
            'uploaded_by_client'  => $this->fk(true),
            'uploaded_at'         => $this->dt(),
        ], ['release_package_id', 'status'], ['public_id']);

        $this->table('uploads', [
            'public_id'           => $this->str(40),
            'release_id'          => $this->fk(),
            'release_package_id'  => $this->fk(),
            'user_id'             => $this->fk(true),
            'api_client_id'       => $this->fk(true),
            'protocol'            => $this->str(16, false, 'tus'), // tus | multipart
            'file_name'           => $this->str(255),
            'size_bytes'          => $this->bigint(),
            'offset_bytes'        => $this->bigint(),
            'storage_provider_id' => $this->fk(true),
            'quarantine_key'      => $this->str(512, true),
            'multipart_upload_id' => $this->str(255, true),
            'reason'              => $this->text(),
            'status'              => $this->str(16, false, 'OPEN'), // OPEN | COMPLETE | ABORTED | EXPIRED
            'expires_at'          => $this->dt(),
        ], ['release_id', 'status'], ['public_id']);

        $this->table('release_changelogs', [
            'release_id'       => $this->fk(),
            'locale'           => $this->str(8, false, 'en'),
            'summary'          => $this->str(280, true),
            'highlights'       => $this->text(),
            'new_features'     => $this->text(),
            'improvements'     => $this->text(),
            'bug_fixes'        => $this->text(),
            'performance'      => $this->text(),
            'security'         => $this->text(),
            'known_issues'     => $this->text(),
            'deprecated'       => $this->text(),
            'removed'          => $this->text(),
            'markdown_content' => $this->longtext(),
            'version'          => $this->int(false, 1),
            'updated_by'       => $this->fk(true),
        ], [], [['release_id', 'locale']]);

        $this->table('release_changelog_versions', [
            'release_id' => $this->fk(),
            'locale'     => $this->str(8),
            'version'    => $this->int(),
            'snapshot'   => $this->longtext(),
            'updated_by' => $this->fk(true),
        ], [['release_id', 'locale']]);

        $this->table('release_manifests', [
            'release_id'       => $this->fk(true),
            'application_id'   => $this->fk(),
            'channel_id'       => $this->fk(),
            'kind'             => $this->str(16), // RELEASE | CHANNEL
            'manifest_version' => $this->int(false, 1),
            'manifest_json'    => $this->longtext(false),
            'sha256'           => $this->str(64),
            'key_id'           => $this->str(64, true),
            'signature'        => $this->text(),
            'signature_valid'  => $this->bool(),
            'generated_at'     => $this->dt(),
            'published_at'     => $this->dt(),
            'superseded_at'    => $this->dt(),
            'reason'           => $this->text(),
        ], [['application_id', 'channel_id', 'kind'], 'release_id']);

        $this->table('build_attestations', [
            'release_id'   => $this->fk(),
            'ci_client_id' => $this->fk(true),
            'payload'      => $this->longtext(false),
            'signature'    => $this->text(false),
            'key_id'       => $this->str(64),
            'verified'     => $this->bool(),
            'verify_error' => $this->text(),
            'verified_at'  => $this->dt(),
        ], ['release_id']);

        $this->table('release_rollouts', [
            'release_id'   => $this->fk(),
            'status'       => $this->str(16, false, 'PENDING'), // PENDING | ACTIVE | PAUSED | DONE
            'mode'         => $this->str(16, false, 'STAGED'), // FULL | STAGED
            'stages'       => $this->text(), // JSON [5,25,50,100]
            'stage_no'     => $this->int(false, 0),
            'percentage'   => $this->int(false, 0),
            'stage_started_at' => $this->dt(),
            'started_at'   => $this->dt(),
            'paused_at'    => $this->dt(),
            'completed_at' => $this->dt(),
            'auto_advance' => $this->bool(),
            'auto_pause'   => $this->bool(),
            'thresholds'   => $this->text(), // JSON {dwell_hours, min_success_rate, max_failure_rate, max_rollback_rate}
        ], [], ['release_id']);

        $this->table('release_rollout_rules', [
            'rollout_id' => $this->fk(),
            'kind'       => $this->str(16), // tenant | org | platform | arch | version_range
            'value'      => $this->str(191),
        ], ['rollout_id']);

        $this->table('minimum_version_policies', [
            'application_id'  => $this->fk(),
            'channel_id'      => $this->fk(),
            'platform'        => $this->str(16, true),
            'minimum_version' => $this->str(64),
            'enforce_from'    => $this->dt(),
            'message'         => $this->text(),
            'updated_by'      => $this->fk(true),
        ], [], [['application_id', 'channel_id', 'platform']]);

        $this->table('minimum_version_policy_history', [
            'application_id'  => $this->fk(),
            'channel_id'      => $this->fk(),
            'platform'        => $this->str(16, true),
            'before_version'  => $this->str(64, true),
            'minimum_version' => $this->str(64),
            'enforce_from'    => $this->dt(),
            'message'         => $this->text(),
            'reason'          => $this->text(),
            'updated_by'      => $this->fk(true),
        ], ['application_id']);

        $this->table('download_pages', [
            'application_id'  => $this->fk(),
            'locale'          => $this->str(8, false, 'en'),
            'hero'            => $this->text(), // JSON {title, subtitle, screenshot}
            'description_md'  => $this->text(),
            'requirements'    => $this->text(), // JSON {windows:[..], macos:[..], linux:[..]}
            'install_help_md' => $this->text(), // JSON per platform markdown
            'faq'             => $this->text(), // JSON [{q,a}]
            'support_url'     => $this->str(255, true),
            'status'          => $this->str(16, false, 'PUBLISHED'), // DRAFT | PUBLISHED
            'draft'           => $this->longtext(), // pending edits JSON
            'version'         => $this->int(false, 1),
            'updated_by'      => $this->fk(true),
        ], [], [['application_id', 'locale']]);

        $this->table('download_page_versions', [
            'application_id' => $this->fk(),
            'locale'         => $this->str(8),
            'version'        => $this->int(),
            'snapshot'       => $this->longtext(),
            'updated_by'     => $this->fk(true),
        ], ['application_id']);
    }

    public function down(): void
    {
        foreach (['download_page_versions', 'download_pages', 'minimum_version_policy_history', 'minimum_version_policies', 'release_rollout_rules', 'release_rollouts', 'build_attestations', 'release_manifests', 'release_changelog_versions', 'release_changelogs', 'uploads', 'release_package_revisions', 'release_packages', 'release_approvals', 'releases', 'application_channels', 'release_channels', 'application_platforms', 'applications'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}

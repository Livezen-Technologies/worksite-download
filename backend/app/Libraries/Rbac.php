<?php

namespace App\Libraries;

/**
 * Role → permission matrix (§7.5, §17.12, FR-BT-PRV-05). Seeded into
 * roles/permissions tables; this class is the single source of truth.
 */
final class Rbac
{
    public const ROLES = [
        'super_admin'     => 'Super Admin',
        'release_manager' => 'Release Manager',
        'developer'       => 'Developer',
        'qa'              => 'QA',
        'content_manager' => 'Content Manager',
        'support'         => 'Support',
        'viewer'          => 'Viewer',
        'portal_user'     => 'Portal User',
        'beta_tester'     => 'Beta Tester',
    ];

    private const SA = 'super_admin';
    private const RM = 'release_manager';
    private const DEV = 'developer';
    private const QA = 'qa';
    private const CM = 'content_manager';
    private const SUP = 'support';
    private const VW = 'viewer';

    /** @var array<string, array{0:string, 1:list<string>}> permission => [label, roles] */
    public const MATRIX = [
        'application.view'          => ['View applications', [self::SA, self::RM, self::DEV, self::QA, self::CM, self::SUP, self::VW]],
        'application.manage'        => ['Create and edit applications', [self::SA]],
        'release.view'              => ['View releases', [self::SA, self::RM, self::DEV, self::QA, self::CM, self::SUP, self::VW]],
        'release.create'            => ['Create releases', [self::SA, self::RM, self::DEV]],
        'release.edit'              => ['Edit releases / submit for test', [self::SA, self::RM, self::DEV]],
        'release.upload'            => ['Upload packages / attestations', [self::SA, self::RM, self::DEV]],
        'release.approve.qa'        => ['QA approval', [self::SA, self::QA]],
        'release.approve'           => ['Release Manager approval', [self::SA, self::RM]],
        'release.publish'           => ['Publish, pause, resume, regenerate manifests', [self::SA, self::RM]],
        'release.withdraw'          => ['Withdraw releases', [self::SA, self::RM]],
        'release.file.upload'       => ['Upload release files', [self::SA, self::RM, self::DEV]],
        'release.file.replace'      => ['Replace release files', [self::SA, self::RM]],
        'release.file.delete'       => ['Delete release files', [self::SA]],
        'changelog.view'            => ['View changelogs', [self::SA, self::RM, self::DEV, self::QA, self::CM, self::SUP, self::VW]],
        'changelog.manage'          => ['Edit changelogs', [self::SA, self::RM, self::DEV, self::CM]],
        'rollout.view'              => ['View rollouts', [self::SA, self::RM, self::DEV, self::QA, self::SUP]],
        'rollout.manage'            => ['Manage rollouts', [self::SA, self::RM]],
        'minimum_version.manage'    => ['Change minimum / mandatory versions', [self::SA, self::RM]],
        'downloads.view'            => ['View download analytics', [self::SA, self::RM, self::DEV, self::CM, self::SUP, self::VW]],
        'devices.view'              => ['View device statistics', [self::SA, self::RM, self::DEV, self::QA, self::SUP]],
        'content.manage'            => ['Edit download pages', [self::SA, self::CM]],
        'user.manage'               => ['Manage users', [self::SA]],
        'api_client.manage'         => ['Manage API clients', [self::SA]],
        'storage.manage'            => ['Manage storage / CDN', [self::SA]],
        'security.manage'           => ['Security settings', [self::SA]],
        'signing_key.rotate'        => ['Rotate signing keys', [self::SA]],
        'audit.view'                => ['View audit log', [self::SA, self::RM, self::SUP]],
        'campaign.manage'           => ['Server notification campaigns', [self::SA, self::RM]],
        'installations.view'        => ['View server installations', [self::SA, self::RM, self::DEV, self::SUP]],
        'fleet.manage'              => ['Managed fleet rollouts', [self::SA, self::RM]],
        'bugtrace.view'             => ['Bug Trace: view', [self::SA, self::RM, self::DEV, self::QA, self::SUP, self::VW]],
        'bugtrace.triage'           => ['Bug Trace: triage', [self::SA, self::RM, self::DEV, self::QA, self::SUP]],
        'bugtrace.manage'           => ['Bug Trace: projects and alerts', [self::SA, self::RM]],
        'bugtrace.reporter.contact' => ['Bug Trace: see reporter contact', [self::SA, self::SUP]],
        'bugtrace.settings'         => ['Bug Trace: privacy settings / deletion', [self::SA]],
        'bugtrace.export'           => ['Bug Trace: export', [self::SA, self::RM]],
        'bugtrace.tenant.view'      => ['Bug Trace: see tenant attribution', [self::SA, self::SUP]],
        'os.publish'                => ['WorkSuite OS: upload ISO/APT and publish to a ring (CI)', [self::SA, self::RM]],
        'bugtrace.symbols.upload'   => ['Bug Trace: upload symbols (CI)', [self::SA]],
    ];

    /** Permissions an API client may be granted (CI never gets release.publish by default — FR-UC-CI-03). */
    public const CI_DEFAULT = ['release.view', 'release.create', 'release.upload', 'release.file.upload', 'changelog.manage', 'bugtrace.symbols.upload', 'os.publish'];

    /** @return list<string> */
    public static function permissionsFor(string $role): array
    {
        $out = [];
        foreach (self::MATRIX as $perm => [, $roles]) {
            if (in_array($role, $roles, true)) {
                $out[] = $perm;
            }
        }

        return $out;
    }
}

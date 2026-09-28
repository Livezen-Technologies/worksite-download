<?php

namespace App\Services\BugTrace;

use App\Libraries\BugTrace\Bt;
use App\Libraries\BugTrace\ReleaseLinker;
use App\Libraries\Semver;

/**
 * Hooks called by the release module (ReleaseService) — WF-C35, FR-BT-ISS-07/08.
 *
 *   onReleasePublished($releaseRow): issues of the release's application that
 *   are RESOLVED "in next release" become RESOLVED in this version; linked
 *   reports (and reports of issues resolved "in version X" == this version)
 *   are marked "fixed in X" and reporters with contact consent are e-mailed.
 *   Nothing is published automatically.
 *
 *   onReleaseWithdrawn($releaseRow): issues whose "next release" was this one
 *   go back to waiting for the next release.
 */
class BugTraceReleaseHooks
{
    /** Channels whose releases do not resolve "next release" issues. */
    private const INTERNAL_CHANNELS = ['DEV', 'INTERNAL'];

    /** @param array<string, mixed> $releaseRow row of `releases` (id, application_id, channel_id, version, …) */
    public function onReleasePublished(array $releaseRow): void
    {
        $db      = Bt::db();
        $version = (string) $releaseRow['version'];
        $appId   = (int) $releaseRow['application_id'];
        $relId   = (int) $releaseRow['id'];
        $channel = $db->table('release_channels')->select('code')->where('id', $releaseRow['channel_id'] ?? 0)->get()->getRow('code');
        if (in_array($channel, self::INTERNAL_CHANNELS, true)) {
            return;
        }
        $projects = array_map('intval', array_column($db->table('bt_projects')->select('id')->where('application_id', $appId)->get()->getResultArray(), 'id'));
        if ($projects === []) {
            return;
        }
        $reports = new ReportService();
        $audit   = ['type' => 'SYSTEM', 'id' => 'bugtrace', 'label' => 'release published ' . $version];

        $waiting = $db->table('bt_issues')->whereIn('project_id', $projects)->where('status', 'RESOLVED')->where('resolution', 'NEXT_RELEASE')->where('resolve_version', null)->get()->getResultArray();
        foreach ($waiting as $issue) {
            // Only a newer release than the one seen when the issue was resolved carries the fix.
            $base = ReleaseLinker::parse($issue['resolved_last_release'])['version'];
            if ($base !== null) {
                try {
                    if (Semver::compare($version, $base) <= 0) {
                        continue;
                    }
                } catch (\Throwable) {
                }
            }
            $db->table('bt_issues')->where('id', $issue['id'])->update(['resolve_version' => $version, 'resolved_in_release_id' => $relId, 'updated_at' => Bt::now()]);
            IssueService::activity((int) $issue['id'], null, "Resolved in {$version} (release published)");
            service('audit')->record('bugtrace.issue.auto_resolved', 'bt_issue', $issue['public_id'], ['resolution' => 'NEXT_RELEASE'], ['resolve_version' => $version, 'release' => $releaseRow['public_id'] ?? null], null, $audit);
            $fresh = $db->table('bt_issues')->where('id', $issue['id'])->get()->getRowArray();
            $reports->markFixed($fresh, $version, $relId);
        }

        // Issues resolved "in version X" learn that X is now available.
        foreach ($db->table('bt_issues')->whereIn('project_id', $projects)->where('status', 'RESOLVED')->where('resolution', 'VERSION')->where('resolve_version', $version)->get()->getResultArray() as $issue) {
            if ($issue['resolved_in_release_id'] === null) {
                $db->table('bt_issues')->where('id', $issue['id'])->update(['resolved_in_release_id' => $relId]);
            }
            $reports->markFixed($issue, $version, $relId);
        }
    }

    /** @param array<string, mixed> $releaseRow */
    public function onReleaseWithdrawn(array $releaseRow): void
    {
        $db = Bt::db();
        foreach ($db->table('bt_issues')->where('resolved_in_release_id', $releaseRow['id'])->where('resolution', 'NEXT_RELEASE')->get()->getResultArray() as $issue) {
            $db->table('bt_issues')->where('id', $issue['id'])->update(['resolve_version' => null, 'resolved_in_release_id' => null, 'updated_at' => Bt::now()]);
            IssueService::activity((int) $issue['id'], null, "Release {$releaseRow['version']} was withdrawn — waiting for the next release again");
            foreach ($db->table('bt_reports')->where('issue_id', $issue['id'])->where('fixed_release_id', $releaseRow['id'])->get()->getResultArray() as $r) {
                $db->table('bt_reports')->where('id', $r['id'])->update(['fixed_in' => null, 'fixed_release_id' => null, 'notified_fixed_at' => null, 'updated_at' => Bt::now()]);
                $db->table('bt_report_updates')->insert(['report_id' => $r['id'], 'status' => $r['status'], 'message' => "Release {$releaseRow['version']} was withdrawn; the fix will ship in a later release.", 'public' => 1, 'created_at' => Bt::now(), 'updated_at' => Bt::now()]);
            }
        }
    }

    /**
     * Public-safe known issues for a release page (FR-BT-ISS-08): unresolved
     * issues seen in that release with a `known_issue_public` text.
     *
     * @return list<array{id: string, text: string}>
     */
    public function knownIssues(int $releaseId): array
    {
        $db      = Bt::db();
        $release = $db->table('releases')->where('id', $releaseId)->get()->getRowArray();
        if (! $release) {
            return [];
        }
        $projects = array_map('intval', array_column($db->table('bt_projects')->select('id')->where('application_id', $release['application_id'])->get()->getResultArray(), 'id')) ?: [0];
        $rows     = $db->table('bt_issues')->select('public_id, known_issue_public')->whereIn('project_id', $projects)->whereIn('status', Bt::UNRESOLVED)
            ->where('known_issue_public IS NOT NULL', null, false)
            ->where('EXISTS (SELECT 1 FROM bt_issue_releases ir WHERE ir.issue_id = bt_issues.id AND (ir.' . $db->protectIdentifiers('release') . ' = ' . $db->escape($release['version']) . ' OR ir.' . $db->protectIdentifiers('release') . ' LIKE ' . $db->escape($release['version'] . '+%') . '))', null, false)
            ->get()->getResultArray();

        return array_map(static fn ($r) => ['id' => $r['public_id'], 'text' => $r['known_issue_public']], $rows);
    }
}

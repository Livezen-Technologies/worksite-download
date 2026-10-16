<?php

namespace App\Services;

use App\Libraries\Semver;
use App\Libraries\Storage\StorageFactory;

/**
 * Pre-publish checklist (§7.4, FR-UC-REL-08, FR-UC-SRV-07). Publish is blocked
 * until every blocking item passes.
 */
class ReleaseValidationService
{
    /**
     * @param list<string> $skip item keys to ignore (e.g. approvals for submit-to-test)
     *
     * @return array{passed: bool, items: list<array{key:string,label:string,status:string,blocking:bool,detail:?string}>}
     */
    public function check(array $release, array $skip = [], bool $probeStorage = true): array
    {
        $cfg     = config('UpdateCenter');
        $prod    = $cfg->isProduction();
        $db      = db_connect();
        $app     = Catalog::appById((int) $release['application_id']);
        $channel = Catalog::channelById((int) $release['channel_id']);
        $items   = [];
        $add     = static function (string $key, string $label, string $status, bool $blocking, ?string $detail = null) use (&$items, $skip): void {
            if (in_array($key, $skip, true)) {
                return;
            }
            $items[] = compact('key', 'label', 'status', 'blocking', 'detail');
        };

        // Version
        $dup = $db->table('releases')->where('application_id', $release['application_id'])->where('channel_id', $release['channel_id'])->where('version', $release['version'])->where('id !=', $release['id'])->countAllResults();
        $add('version', 'Version entered, valid SemVer, unique per app/channel', Semver::isValid($release['version']) && $dup === 0 ? 'pass' : 'fail', true, $release['version']);

        // Build number
        $prevBuild = $db->table('releases')->selectMax('build_number')->where('application_id', $release['application_id'])->where('channel_id', $release['channel_id'])->where('id !=', $release['id'])->whereNotIn('status', ['WITHDRAWN'])->where('created_at <', $release['created_at'] ?? Catalog::now())->get()->getRow('build_number');
        $add('build', 'Build number valid and greater than previous build on channel', (int) $release['build_number'] > 0 && (int) $release['build_number'] > (int) $prevBuild ? 'pass' : 'fail', true, 'build ' . $release['build_number'] . ($prevBuild ? " (previous {$prevBuild})" : ''));

        // Packages
        $packages = $db->table('release_packages p')->select('p.*, r.sha256, r.scan_status, r.signature_status, r.attestation_match, r.storage_object_key, r.storage_provider_id, r.status AS rev_status, r.file_name')
            ->join('release_package_revisions r', 'r.id = p.active_revision_id', 'left')->where('p.release_id', $release['id'])->get()->getResultArray();
        $ready = array_values(array_filter($packages, static fn ($p) => $p['status'] === 'READY' && $p['rev_status'] === 'READY'));
        $processing = array_filter($packages, static fn ($p) => $p['status'] === 'PROCESSING');

        if ($app['kind'] === 'SERVER') {
            $editions = array_map(static fn ($p) => $p['edition'], $ready);
            $selected = json_decode((string) ($db->table('release_server_meta')->select('editions')->where('release_id', $release['id'])->get()->getRow('editions') ?? ''), true);
            if (is_array($selected) && $selected !== []) {
                // WS-CORE-SYSUPDATE FR-DL-REL-03: every selected edition uploaded and valid.
                $missing = array_values(array_diff($selected, $editions));
                $labels  = array_map([ServerReleaseConsoleService::class, 'editionLabel'], $missing);
                $add('packages', 'Every selected edition uploaded and valid (' . implode(', ', array_map([ServerReleaseConsoleService::class, 'editionLabel'], $selected)) . ')', $missing === [] ? 'pass' : ($processing ? 'pending' : 'fail'), true, $missing === [] ? implode(', ', $editions) : 'Missing or invalid: ' . implode(', ', $labels));
            } else {
                $missing = array_diff(['FREE', 'BUSINESS', 'ENTERPRISE'], $editions);
                $allOnly = in_array('ALL', $editions, true) && count(array_intersect(['FREE', 'BUSINESS', 'ENTERPRISE'], $editions)) === 0;
                $add('packages', 'All required editions READY (Free, Business, Enterprise — or one ALL core patch)', $missing === [] || $allOnly ? 'pass' : ($processing ? 'pending' : 'fail'), true, $missing === [] || $allOnly ? implode(', ', $editions) : 'Missing: ' . implode(', ', $missing));
            }
        } else {
            $missing = [];
            foreach (Catalog::platforms((int) $app['id']) as $pl) {
                if (! $pl['required_for_publish']) {
                    continue;
                }
                $found = false;
                foreach ($ready as $p) {
                    if ($p['platform'] === $pl['platform'] && $p['architecture'] === $pl['architecture']) {
                        $found = true;
                    }
                }
                if (! $found) {
                    $missing[] = $pl['platform'] . ' ' . $pl['architecture'];
                }
            }
            $add('packages', 'Required platform packages uploaded', $missing === [] && $ready !== [] ? 'pass' : ($processing ? 'pending' : 'fail'), true, $missing === [] ? count($ready) . ' packages ready' : 'Missing: ' . implode(', ', $missing));
        }

        $noHash = array_filter($ready, static fn ($p) => ! preg_match('/^[a-f0-9]{64}$/', (string) $p['sha256']));
        $add('sha256', 'SHA-256 calculated for every active revision', $ready !== [] && $noHash === [] ? 'pass' : 'fail', true);

        $scanRequired = $prod || (json_decode((string) $app['security_settings'], true)['malware_scan_required'] ?? false) && $cfg->scanner !== 'none';
        $scanBad      = array_filter($ready, static fn ($p) => $p['scan_status'] !== 'CLEAN' && ! (! $scanRequired && $p['scan_status'] === 'SKIPPED'));
        $add('scan', 'Malware scan clean', $ready === [] ? 'pending' : ($scanBad === [] ? ($scanRequired ? 'pass' : 'skipped') : 'fail'), $scanRequired, $scanRequired ? null : 'No scanner configured (development)');

        // Unsigned is accepted outside production, and on non-STABLE channels when allowed.
        $unsignedOk = ! $prod || ($cfg->allowUnsignedTestChannels && ($channel['code'] ?? '') !== 'STABLE');
        $skipped    = array_filter($ready, static fn ($p) => $p['signature_status'] === 'SKIPPED');
        $sigBad     = array_filter($ready, static fn ($p) => ! in_array($p['signature_status'], ['VALID', 'NOT_REQUIRED'], true) && ! ($unsignedOk && $p['signature_status'] === 'SKIPPED'));
        $add('signatures', 'Package code signatures valid (Authenticode, Developer ID + notarization, GPG)', $ready === [] ? 'pending' : ($sigBad !== [] ? 'fail' : ($skipped !== [] ? 'skipped' : 'pass')), true,
            $sigBad !== [] ? count($sigBad) . ' package(s) unsigned or invalid' : ($skipped !== [] ? count($skipped) . ' package(s) unsigned — allowed on this channel' : null));

        $attBad = array_filter($ready, static fn ($p) => $p['attestation_match'] !== 'MATCH');
        $attMis = array_filter($ready, static fn ($p) => $p['attestation_match'] === 'MISMATCH');
        $attRequired = $prod || $cfg->requireAttestation;
        $add('attestation', 'Package hashes match CI build attestation', $ready === [] ? 'pending' : ($attBad === [] ? 'pass' : ($attMis !== [] || $attRequired ? 'fail' : 'skipped')), $attRequired || $attMis !== [], $attBad === [] ? null : ($attMis !== [] ? 'Hash mismatch' : 'No CI attestation recorded'));

        $add('changelog', 'Changelog entered (summary + at least one category)', (new ChangelogService())->isComplete((int) $release['id']) ? 'pass' : 'fail', true);

        $minOk = ($release['minimum_supported_version'] !== null && $release['minimum_supported_version'] !== '') || $release['minimum_no_change'];
        $add('minimum', 'Minimum version configured (or explicitly “no change”)', $minOk ? 'pass' : 'fail', true, $release['minimum_supported_version'] ?: ($release['minimum_no_change'] ? 'No change' : null));

        $manifestOk = false;
        $manifestDetail = null;
        try {
            $doc        = (new ManifestService())->buildRelease($release);
            $manifestOk = $doc['packages'] !== [];
            $manifestDetail = count($doc['packages']) . ' packages';
        } catch (\Throwable $e) {
            $manifestDetail = $e->getMessage();
        }
        $add('manifest', 'Manifest generated', $manifestOk ? 'pass' : 'fail', true, $manifestDetail);
        $signed = (new ManifestService())->latestSigned((int) $release['id']);
        $add('manifest_signature', 'Manifest signature verified after signing', $signed ? ($signed['signature_valid'] ? 'pass' : 'fail') : 'pending', false, $signed ? $signed['key_id'] : 'Signed and verified during publish');

        if ($channel['code'] === 'STABLE' && ($cfg->requireApprovals || $prod)) {
            $qa = $db->table('release_approvals')->where('release_id', $release['id'])->where('kind', 'QA')->where('decision', 'PASS')->countAllResults();
            $rm = $db->table('release_approvals')->where('release_id', $release['id'])->where('kind', 'RELEASE')->where('decision', 'APPROVED')->countAllResults();
            $add('approvals', 'QA approval + Release Manager approval', $qa && $rm ? 'pass' : 'pending', true, ($qa ? 'QA ✓' : 'QA pending') . ' · ' . ($rm ? 'Release Manager ✓' : 'Release Manager pending'));
        }

        if ($probeStorage && $ready !== []) {
            $bad = [];
            foreach ($ready as $p) {
                try {
                    $prov = StorageFactory::fromRow(Catalog::storageById((int) $p['storage_provider_id']));
                    if ($prov->head((string) $p['storage_object_key']) === null) {
                        $bad[] = $p['file_name'];
                    }
                } catch (\Throwable $e) {
                    $bad[] = $p['file_name'] . ' (' . $e->getMessage() . ')';
                }
            }
            $add('storage', 'Storage objects readable via CDN (HEAD probe)', $bad === [] ? 'pass' : 'fail', true, $bad === [] ? null : 'Unreadable: ' . implode(', ', $bad));
        }

        if ($app['kind'] === 'SERVER') {
            $meta = $db->table('release_server_meta')->where('release_id', $release['id'])->get()->getRowArray();
            $add('upgrade_path', 'Upgrade path (min_from_version)', ! empty($meta['min_from_version']) ? 'pass' : 'fail', true, $meta['min_from_version'] ?? null);
            $add('requirements', 'Requirements declared', ! empty($meta['requires']) && $meta['requires'] !== '[]' ? 'pass' : 'fail', true);
            $mig = json_decode((string) ($meta['migrations'] ?? ''), true);
            $add('migrations', 'Migrations listed with one-way flags', is_array($mig) && isset($mig['count']) ? 'pass' : 'fail', true, is_array($mig) ? ($mig['count'] ?? 0) . ' migrations, ' . count($mig['one_way'] ?? []) . ' one-way' : null);
            if (! empty($meta['severity'])) { // releases created in the server console (FR-DL-REL-01)
                $rev = $meta['migrations_reversible'];
                $contradiction = $rev !== null && (int) $rev === 1 && is_array($mig) && ($mig['one_way'] ?? []) !== [];
                $add('reversibility', 'Migration reversibility stated', $rev === null ? 'fail' : ($contradiction ? 'fail' : 'pass'), true,
                    $rev === null ? 'State whether every migration is reversible' : ($contradiction ? 'Stated reversible, but the package lists one-way migrations' : ((int) $rev === 1 ? 'All migrations reversible' : 'Has one-way migrations (DB restore on rollback)')));
            }
            $add('desktop_compat', 'Desktop compatibility range', ! empty($meta['desktop_compat']) ? 'pass' : 'fail', true, $meta['desktop_compat'] ?? null);
            $staging = json_decode((string) ($meta['staging_test'] ?? ''), true);
            $add('staging_test', 'Staging install test passed', ($staging['status'] ?? '') === 'PASSED' ? 'pass' : (($staging['status'] ?? '') === 'FAILED' ? 'fail' : 'pending'), true, $staging['status'] ?? 'Not reported');
            $add('admin_notice', 'Admin notice text', ! empty($meta['admin_notice']) ? 'pass' : 'fail', true);
        }

        $passed = true;
        foreach ($items as $i) {
            if ($i['blocking'] && $i['status'] !== 'pass' && ! ($i['status'] === 'skipped')) {
                $passed = false;
            }
        }

        return ['passed' => $passed, 'items' => $items];
    }

    /** @return list<array<string,mixed>> failing blocking items */
    public static function failures(array $checklist): array
    {
        return array_values(array_filter($checklist['items'], static fn ($i) => $i['blocking'] && ! in_array($i['status'], ['pass', 'skipped'], true)));
    }
}

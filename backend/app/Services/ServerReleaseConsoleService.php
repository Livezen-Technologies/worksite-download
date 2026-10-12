<?php

namespace App\Services;

use App\Controllers\Admin\ReleasesController;
use App\Exceptions\ApiException;
use App\Libraries\CanonicalJson;
use App\Libraries\Semver;
use App\Libraries\Signing\SigningRefusedException;
use App\Libraries\Storage\StorageFactory;

/**
 * Server release console on download.worksuitecloud.com (WS-CORE-SYSUPDATE-SRS-001 §4, DL-1..DL-4):
 * list with fleet KPIs, one-step creation with every server field, the publish step
 * (QA ≠ Release Manager, step-up MFA, type-to-confirm), public edition folders with
 * SHA256SUMS + .sig, and the installations fleet.
 */
class ServerReleaseConsoleService
{
    public const SEVERITIES = ['PATCH', 'MINOR', 'MAJOR', 'SECURITY', 'CRITICAL'];

    /** Release type (urgency) a severity maps to when none is given. */
    public const SEVERITY_TYPE = ['PATCH' => 'RECOMMENDED', 'MINOR' => 'RECOMMENDED', 'MAJOR' => 'OPTIONAL', 'SECURITY' => 'MANDATORY', 'CRITICAL' => 'CRITICAL'];

    public const CHANNELS = ['STABLE', 'BETA', 'LTS'];

    public const STATUS_GROUPS = [
        'draft'     => ['DRAFT', 'UPLOADING', 'READY_FOR_TEST'],
        'testing'   => ['TESTING', 'APPROVED'],
        'published' => ['PUBLISHED', 'SUPERSEDED'],
        'paused'    => ['PAUSED'],
        'withdrawn' => ['WITHDRAWN'],
    ];

    /** "Professional" is the product name of the FREE edition (§3). */
    public static function normalizeEdition(string $e): string
    {
        $e = strtoupper(trim($e));

        return $e === 'PROFESSIONAL' ? 'FREE' : $e;
    }

    public static function editionLabel(string $e): string
    {
        return match (strtoupper($e)) {
            'FREE'       => 'Professional',
            'ALL'        => 'All editions (core patch)',
            default      => ucfirst(strtolower($e)),
        };
    }

    public function __construct(private readonly ?AuthContext $auth = null)
    {
    }

    private function app(): array
    {
        return (new ServerUpdateService())->serverApp();
    }

    // ---- DL-1 ---------------------------------------------------------------------

    public function list(array $q): array
    {
        $db  = db_connect();
        $app = $this->app();
        $b   = $db->table('releases r')->select('r.*')->join('release_channels c', 'c.id = r.channel_id')->where('r.application_id', $app['id']);
        if (! empty($q['channel'])) {
            $b->where('c.code', strtoupper((string) $q['channel']));
        }
        if (! empty($q['status'])) {
            $group = strtolower((string) $q['status']);
            $b->whereIn('r.status', self::STATUS_GROUPS[$group] ?? array_map('strtoupper', explode(',', (string) $q['status'])));
        }
        $rows     = $b->orderBy('r.version_sort', 'DESC')->limit(200)->get()->getResultArray();
        $installs = $db->table('server_installations')->select('id, version, channel, offline_mode, mode, last_report_at')->get()->getResultArray();
        $rings    = new ServerRingService();

        return ['kpis' => $this->kpis($app, $installs), 'data' => array_map(fn ($r) => $this->row($r, $installs, $rings), $rows), 'minimums' => $this->minimums($app)];
    }

    private function row(array $r, array $installs, ServerRingService $rings): array
    {
        $db       = db_connect();
        $meta     = ServerRingService::meta($r);
        $editions = array_values(array_unique(array_column($db->table('release_packages')->select('edition')->where('release_id', $r['id'])->where('status', 'READY')->get()->getResultArray(), 'edition')));
        if ($editions === []) {
            $editions = json_decode((string) ($meta['editions'] ?? '[]'), true) ?: [];
        }
        $channel = Catalog::channelById((int) $r['channel_id'])['code'];
        $inCh    = array_values(array_filter($installs, static fn ($i) => strtoupper((string) $i['channel']) === $channel));
        $on      = count(array_filter($inCh, static fn ($i) => $i['version'] === $r['version']));
        $ro      = $db->table('release_rollouts')->where('release_id', $r['id'])->get()->getRowArray();

        return ReleasesController::present($r) + [
            'severity'       => ServerRingService::severity($r, $meta),
            'editions'       => $editions,
            'editions_label' => $editions === [] ? '—' : implode(', ', array_map([self::class, 'editionLabel'], $editions)),
            'ring_summary'   => $this->ringSummary($r, $ro, $rings),
            'adoption_pct'   => $inCh === [] ? 0.0 : round(100 * $on / count($inCh), 1),
            'installations_on_version' => $on,
            'commit'         => $meta['commit_sha'] ?? null,
        ];
    }

    private function ringSummary(array $r, ?array $ro, ServerRingService $rings): string
    {
        return match (true) {
            in_array($r['status'], ['DRAFT', 'UPLOADING'], true)  => 'Draft',
            $r['status'] === 'READY_FOR_TEST'                     => 'Waiting for QA',
            $r['status'] === 'TESTING'                            => 'Waiting for QA',
            $r['status'] === 'APPROVED'                           => 'Ready to publish',
            $ro === null || $ro['mode'] !== 'RINGS'               => in_array($r['status'], ['PUBLISHED', 'SUPERSEDED'], true) ? 'Complete' : ucfirst(strtolower($r['status'])),
            default                                               => (function () use ($r, $ro, $rings) {
                $state = $rings->state($r);
                $cur   = max(0, $rings->currentRing($state));
                if ($r['status'] === 'WITHDRAWN') {
                    return 'Stopped at ring ' . $cur;
                }
                if ($state['pause'] !== null) {
                    return 'Paused at ring ' . $cur;
                }

                return $ro['status'] === 'DONE' || $r['status'] === 'SUPERSEDED' ? 'Ring 3 · complete' : 'Ring ' . $cur . ' · open';
            })(),
        };
    }

    /** DL-1 KPIs: linked installations, on latest stable, below minimum, rollbacks in 30 days. */
    public function kpis(array $app, array $installs): array
    {
        $db      = db_connect();
        $stable  = Catalog::channel('STABLE', (int) $app['id']);
        $latest  = (new ManifestService())->currentRelease((int) $app['id'], (int) $stable['id']);
        $offline = count(array_filter($installs, static fn ($i) => ! empty($i['offline_mode']) || $i['mode'] === 'AIR_GAPPED'));
        $onLatest = $latest ? count(array_filter($installs, static fn ($i) => $i['version'] === $latest['version'])) : 0;
        $below   = 0;
        $minSvc  = new MinimumVersionService();
        $mins    = [];
        foreach ($installs as $i) {
            $code = strtoupper((string) ($i['channel'] ?: 'STABLE'));
            if (! array_key_exists($code, $mins)) {
                try {
                    $mins[$code] = $minSvc->channelMinimum((int) $app['id'], (int) Catalog::channel($code, (int) $app['id'])['id'], null)['minimum_version'] ?? null;
                } catch (\Throwable) {
                    $mins[$code] = null;
                }
            }
            try {
                if ($mins[$code] && $i['version'] && Semver::lt($i['version'], $mins[$code])) {
                    $below++;
                }
            } catch (\Throwable) {
            }
        }
        $rb = $db->table('server_update_events')->select('installation_id, to_version')->where('type', 'rolled_back')->where('occurred_at >=', gmdate('Y-m-d H:i:s', time() - 30 * 86400))->get()->getResultArray();

        return [
            'linked'            => count($installs),
            'online'            => count($installs) - $offline,
            'offline'           => $offline,
            'latest_stable'     => $latest['version'] ?? null,
            'on_latest_stable'  => $onLatest,
            'on_latest_pct'     => $installs === [] ? 0.0 : round(100 * $onLatest / count($installs), 1),
            'below_minimum'     => $below,
            'minimum_stable'    => $mins['STABLE'] ?? ($minSvc->channelMinimum((int) $app['id'], (int) $stable['id'], null)['minimum_version'] ?? null),
            'rollbacks_30d'     => count($rb),
            'rollback_versions' => array_values(array_unique(array_filter(array_column($rb, 'to_version')))),
        ];
    }

    /** Minimum supported server version per channel (FR-DL-MIN-01, set via the existing minimum-versions API). */
    private function minimums(array $app): array
    {
        $out = [];
        foreach (self::CHANNELS as $code) {
            try {
                $ch = Catalog::channel($code, (int) $app['id']);
            } catch (\Throwable) {
                continue;
            }
            $m     = (new MinimumVersionService())->channelMinimum((int) $app['id'], (int) $ch['id'], null, false);
            $out[] = ['channel' => $code, 'minimum_version' => $m['minimum_version'] ?? null, 'enforce_from' => Catalog::iso($m['enforce_from'] ?? null)];
        }

        return $out;
    }

    // ---- FR-DL-REL-01 -----------------------------------------------------------------

    /**
     * Create a server release with every field: version, build, commit, channel, severity,
     * min_from_version, release notes (Markdown), known issues, upgrade notes, editions.
     */
    public function create(array $in): array
    {
        $errors   = [];
        $severity = strtoupper((string) ($in['severity'] ?? ''));
        if (! in_array($severity, self::SEVERITIES, true)) {
            $errors['severity'] = 'patch, minor, major, security or critical';
        }
        $channel = strtoupper((string) ($in['channel'] ?? 'STABLE'));
        if (! in_array($channel, self::CHANNELS, true)) {
            $errors['channel'] = 'stable, beta or lts';
        }
        $commit = trim((string) ($in['commit'] ?? ''));
        if ($commit !== '' && ! preg_match('/^[0-9a-f]{7,40}$/i', $commit)) {
            $errors['commit'] = 'Commit must be a 7–40 character hex SHA';
        }
        $min = trim((string) ($in['min_from_version'] ?? ''));
        if ($min !== '' && ! Semver::isValid($min)) {
            $errors['min_from_version'] = 'Must be SemVer, e.g. 3.0.0';
        }
        $editions = array_values(array_unique(array_map([self::class, 'normalizeEdition'], (array) ($in['editions'] ?? ['FREE', 'BUSINESS', 'ENTERPRISE']))));
        if ($editions === [] || array_diff($editions, PackageService::EDITIONS) !== [] || (in_array('ALL', $editions, true) && count($editions) > 1)) {
            $errors['editions'] = 'Choose Professional, Business and/or Enterprise — or All (core patch) on its own';
        }
        $notes = (string) ($in['notes'] ?? '');
        if (mb_strlen($notes) > 50000) {
            $errors['notes'] = 'Release notes are limited to 50,000 characters';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $svc     = new ReleaseService($this->auth);
        $release = $svc->create([
            'app_id' => 'server', 'version' => (string) ($in['version'] ?? ''), 'build' => $in['build'] ?? null, 'channel' => $channel,
            'release_type' => strtoupper((string) ($in['release_type'] ?? self::SEVERITY_TYPE[$severity])),
            'notes' => $notes === '' ? null : $notes, 'known_issues' => ($in['known_issues'] ?? '') ?: null, 'minimum_no_change' => true,
            'rollback_supported' => ! array_key_exists('migrations_reversible', $in) || $in['migrations_reversible'] !== false,
        ]);
        $meta = [
            'severity' => $severity, 'commit_sha' => $commit === '' ? null : strtolower($commit), 'upgrade_notes' => ($in['upgrade_notes'] ?? '') ?: null,
            'migrations_reversible' => array_key_exists('migrations_reversible', $in) && $in['migrations_reversible'] !== null ? ($in['migrations_reversible'] ? 1 : 0) : null,
            'editions' => json_encode($editions), 'min_from_version' => $min === '' ? null : $min,
            'desktop_compat' => ($in['desktop_compat'] ?? '') ?: null, 'admin_notice' => ($in['admin_notice'] ?? '') ?: null,
            'downtime_estimate_s' => isset($in['downtime_estimate_s']) && $in['downtime_estimate_s'] !== '' ? (int) $in['downtime_estimate_s'] : null,
            'updated_at' => Catalog::now(),
        ];
        db_connect()->table('release_server_meta')->where('release_id', $release['id'])->update($meta);
        if ($notes !== '') {
            (new ChangelogService())->save($release, ['summary' => mb_substr(trim((string) ($in['summary'] ?? '')) ?: "WorkSuite Server {$release['version']}", 0, 280), 'markdown' => $notes], $this->auth?->userId());
        }
        service('audit')->record('server.release_created', 'release', $release['public_id'], null, ['severity' => $severity, 'channel' => $channel, 'editions' => $editions, 'commit' => $meta['commit_sha'], 'min_from_version' => $meta['min_from_version'], 'migrations_reversible' => $meta['migrations_reversible']]);

        return Catalog::releaseById((int) $release['id']);
    }

    /** Edit the server fields of a release before it is published. */
    public function updateMeta(array $release, array $in): array
    {
        if (! in_array($release['status'], ReleaseService::PRE_PUBLISH, true)) {
            throw new ApiException('RELEASE_IMMUTABLE', 'Server release fields are frozen after publication', 409);
        }
        $set = [];
        if (array_key_exists('severity', $in)) {
            $sev = strtoupper((string) $in['severity']);
            if (! in_array($sev, self::SEVERITIES, true)) {
                throw ApiException::validation(['severity' => 'patch, minor, major, security or critical']);
            }
            $set['severity'] = $sev;
        }
        if (array_key_exists('commit', $in)) {
            $c = trim((string) $in['commit']);
            if ($c !== '' && ! preg_match('/^[0-9a-f]{7,40}$/i', $c)) {
                throw ApiException::validation(['commit' => 'Commit must be a 7–40 character hex SHA']);
            }
            $set['commit_sha'] = $c === '' ? null : strtolower($c);
        }
        if (array_key_exists('upgrade_notes', $in)) {
            $set['upgrade_notes'] = ($in['upgrade_notes'] ?? '') ?: null;
        }
        if (array_key_exists('migrations_reversible', $in)) {
            $set['migrations_reversible'] = $in['migrations_reversible'] === null ? null : ($in['migrations_reversible'] ? 1 : 0);
        }
        if (array_key_exists('editions', $in)) {
            $ed = array_values(array_unique(array_map([self::class, 'normalizeEdition'], (array) $in['editions'])));
            if ($ed === [] || array_diff($ed, PackageService::EDITIONS) !== [] || (in_array('ALL', $ed, true) && count($ed) > 1)) {
                throw ApiException::validation(['editions' => 'Choose Professional, Business and/or Enterprise — or All (core patch) on its own']);
            }
            $set['editions'] = json_encode($ed);
        }
        if ($set === []) {
            return $release;
        }
        $before = array_intersect_key(ServerRingService::meta($release), $set);
        db_connect()->table('release_server_meta')->where('release_id', $release['id'])->update($set + ['updated_at' => Catalog::now()]);
        service('audit')->record('release.server_meta_changed', 'release', $release['public_id'], $before, $set);

        return $release;
    }

    // ---- FR-DL-REL-04 -------------------------------------------------------------------

    /**
     * Release Manager publish: QA must have passed; the publisher records the Release Manager
     * approval and publishes in one step. The QA approver can never publish (two-person rule).
     * Step-up MFA is checked by the controller; type-to-confirm here.
     */
    public function publish(array $release, string $confirmVersion, ?string $reason): array
    {
        if ($confirmVersion !== $release['version']) {
            throw ApiException::validation(['confirm_version' => 'Type the version number exactly to confirm']);
        }
        if (! ServerRingService::isServer($release)) {
            throw ApiException::validation(['release' => 'Not a server release']);
        }
        $svc = new ReleaseService($this->auth);
        if ($release['status'] === 'TESTING') {
            $release = $svc->approve($release, 'Approved at publish' . ($reason ? ': ' . $reason : ''));
        }
        if ($release['status'] !== 'APPROVED') {
            throw new ApiException('INVALID_TRANSITION', 'QA must approve the release before it can be published', 409);
        }

        return $svc->publish($release, $reason);
    }

    // ---- FR-DL-REL-05 -------------------------------------------------------------------

    /**
     * After the manifest is signed: copy each edition package to
     * server/<edition>/<channel>/<version>+<build>/ and write SHA256SUMS, SHA256SUMS.sig
     * (signed by the Signing Service, kind CHECKSUMS) and the signed release manifest.
     *
     * @return list<string> object keys written
     */
    public function publishFiles(array $release): array
    {
        $ms       = new ManifestService();
        $signed   = $ms->latestSigned((int) $release['id']);
        if ($signed === null) {
            throw new ApiException('MANIFEST_MISSING', 'Release has no signed manifest', 409);
        }
        $manifest = json_decode($signed['manifest_json'], true);
        $app      = Catalog::appById((int) $release['application_id']);
        $channel  = strtolower(Catalog::channelById((int) $release['channel_id'])['code']);
        $rows     = db_connect()->table('release_packages p')->select('p.edition, r.file_name, r.sha256, r.size_bytes, r.storage_provider_id, r.storage_object_key')
            ->join('release_package_revisions r', 'r.id = p.active_revision_id')->where('p.release_id', $release['id'])->where('r.status', 'READY')->get()->getResultArray();
        $written  = [];
        foreach ($rows as $p) {
            $provider = StorageFactory::fromRow(Catalog::storageById((int) $p['storage_provider_id']));
            $dir      = 'server/' . strtolower((string) $p['edition']) . "/{$channel}/{$release['version']}+{$release['build_number']}/";
            $provider->copy((string) $p['storage_object_key'], $dir . $p['file_name']);
            $sums = $p['sha256'] . '  ' . $p['file_name'] . "\n";
            $doc  = [
                'schema' => 1, 'type' => 'checksums', 'app' => $app['app_identifier'], 'release_id' => $release['public_id'], 'version' => $release['version'],
                'build' => (int) $release['build_number'], 'channel' => $channel, 'edition' => strtolower((string) $p['edition']),
                'files' => [['name' => $p['file_name'], 'sha256' => $p['sha256'], 'size' => (int) $p['size_bytes']]],
                'sha256sums_sha256' => hash('sha256', $sums), 'release_manifest_sha256' => hash('sha256', CanonicalJson::encode($manifest)), 'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            try {
                $sig = service('signingClient')->sign('CHECKSUMS', $doc, ['release_manifest' => $manifest]);
            } catch (SigningRefusedException $e) {
                throw new ApiException('SIGNING_REFUSED', 'Signing Service refused SHA256SUMS: ' . implode('; ', $e->reasons), 409, ['reasons' => $e->reasons]);
            }
            foreach (['SHA256SUMS' => [$sums, 'text/plain'], 'SHA256SUMS.sig' => [json_encode($sig, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n", 'application/json'], 'release-manifest.json' => [CanonicalJson::encode($manifest), 'application/json']] as $name => [$bytes, $type]) {
                $tmp = tempnam(sys_get_temp_dir(), 'ucsum');
                file_put_contents($tmp, $bytes);
                try {
                    $provider->putFile($dir . $name, $tmp, $type);
                } finally {
                    if (is_file($tmp)) {
                        unlink($tmp);
                    }
                }
            }
            $written[] = $dir . $p['file_name'];
            $written[] = $dir . 'SHA256SUMS';
            $written[] = $dir . 'SHA256SUMS.sig';
            $written[] = $dir . 'release-manifest.json';
        }
        service('audit')->record('server.release_files_published', 'release', $release['public_id'], null, ['objects' => $written], null, ['type' => 'SYSTEM', 'id' => 'release-service', 'label' => 'publish']);

        return $written;
    }

    // ---- DL-4 ---------------------------------------------------------------------------

    /** Segment filters for DL-4: on_latest, below_minimum, on_hold, stale (no heartbeat 24 h). */
    public function fleetCounts(): array
    {
        $db       = db_connect();
        $app      = $this->app();
        $installs = $db->table('server_installations')->get()->getResultArray();
        $k        = $this->kpis($app, $installs);
        $held     = $db->table('server_update_plans')->select('installation_id')->distinct()->where('state', 'HELD')->like('state_reason', 'Rolled back', 'after')->countAllResults();
        $stale    = count(array_filter($installs, static fn ($i) => empty($i['offline_mode']) && (! $i['last_report_at'] || strtotime($i['last_report_at'] . ' UTC') < time() - 86400)));

        return ['all' => count($installs), 'on_latest' => $k['on_latest_stable'], 'latest_stable' => $k['latest_stable'], 'below_minimum' => $k['below_minimum'], 'on_hold' => $held, 'stale' => $stale];
    }

    /** Adoption per published release (installations on each version). */
    public function adoption(): array
    {
        $db   = db_connect();
        $app  = $this->app();
        $rows = $db->table('releases')->where('application_id', $app['id'])->whereIn('status', ['PUBLISHED', 'SUPERSEDED', 'PAUSED', 'WITHDRAWN'])->orderBy('version_sort', 'DESC')->limit(12)->get()->getResultArray();
        $by   = array_column($db->table('server_installations')->select('version, COUNT(*) AS c')->groupBy('version')->get()->getResultArray(), 'c', 'version');
        $all  = max(1, array_sum(array_map('intval', $by)));

        return array_map(static fn ($r) => ['release_id' => $r['public_id'], 'version' => $r['version'], 'status' => $r['status'], 'channel' => Catalog::channelById((int) $r['channel_id'])['code'], 'installations' => (int) ($by[$r['version']] ?? 0), 'pct' => round(100 * (int) ($by[$r['version']] ?? 0) / $all, 1)], $rows);
    }
}

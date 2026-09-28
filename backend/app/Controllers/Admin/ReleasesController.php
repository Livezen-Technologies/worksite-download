<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Libraries\Base64Url;
use App\Libraries\CanonicalJson;
use App\Libraries\Semver;
use App\Libraries\Signing\Ed25519;
use App\Services\Catalog;
use App\Services\ChangelogService;
use App\Services\KeyRegistry;
use App\Services\ManifestService;
use App\Services\PackageService;
use App\Services\PublicCatalogService;
use App\Services\ReleaseService;
use App\Services\ReleaseValidationService;
use App\Services\RolloutService;
use App\Services\ServerUpdateService;
use App\Services\TelemetryService;
use App\Services\UpdateResolverService;

/**
 * Release management (§7.3, FR-UC-REL/PKG/UPL/CHG/MAN/ROL, §17 server releases).
 */
class ReleasesController extends BaseApiController
{
    private function svc(): ReleaseService
    {
        return new ReleaseService($this->auth);
    }

    private function load(string $id): array
    {
        return Catalog::release($id);
    }

    public static function present(array $r): array
    {
        $app  = Catalog::appById((int) $r['application_id']);
        $db   = db_connect();
        $pk   = $db->table('release_packages')->select('status')->where('release_id', $r['id'])->get()->getResultArray();
        $sup  = $r['superseded_by_release_id'] ? Catalog::releaseById((int) $r['superseded_by_release_id']) : null;

        return [
            'id'                        => $r['public_id'],
            'app'                       => ['id' => $app['public_id'], 'slug' => $app['slug'], 'name' => $app['name'], 'kind' => $app['kind']],
            'version'                   => $r['version'],
            'build'                     => (int) $r['build_number'],
            'build_revision'            => (int) $r['build_revision'],
            'channel'                   => Catalog::channelById((int) $r['channel_id'])['code'],
            'release_type'              => $r['release_type'],
            'status'                    => $r['status'],
            'support_state'             => $r['support_state'],
            'visibility'                => $r['visibility'],
            'minimum_supported_version' => $r['minimum_supported_version'],
            'minimum_no_change'         => (bool) $r['minimum_no_change'],
            'mandatory'                 => (bool) $r['mandatory'],
            'mandatory_after'           => Catalog::iso($r['mandatory_after']),
            'grace_minutes'             => $r['grace_minutes'] === null ? null : (int) $r['grace_minutes'],
            'rollback_supported'        => (bool) $r['rollback_supported'],
            'release_date'              => Catalog::iso($r['release_date']),
            'scheduled_publish_at'      => Catalog::iso($r['scheduled_publish_at']),
            'published_at'              => Catalog::iso($r['published_at']),
            'published_by'              => Catalog::userLabel($r['published_by'] ? (int) $r['published_by'] : null),
            'created_by'                => $r['created_by'] ? Catalog::userLabel((int) $r['created_by']) : ($r['created_by_client'] ? 'CI' : null),
            'withdrawn_at'              => Catalog::iso($r['withdrawn_at']),
            'withdraw_reason'           => $r['withdraw_reason'],
            'superseded_by'             => $sup ? ['id' => $sup['public_id'], 'version' => $sup['version']] : null,
            'notes'                     => $r['notes'],
            'known_issues'              => $r['known_issues'],
            'qa_notes'                  => $r['qa_notes'],
            'auto_pause_on_gate'        => (bool) $r['auto_pause_on_gate'],
            'last_error'                => $r['last_error'],
            'created_at'                => Catalog::iso($r['created_at']),
            'updated_at'                => Catalog::iso($r['updated_at']),
            'packages_ready'            => count(array_filter($pk, static fn ($p) => $p['status'] === 'READY')),
            'packages_total'            => count($pk),
        ];
    }

    private function full(array $r): array
    {
        $app = Catalog::appById((int) $r['application_id']);

        return self::present($r) + [
            'packages'        => (new PackageService())->listForRelease($r),
            'changelog'       => ChangelogService::present((new ChangelogService())->get((int) $r['id'])),
            'checklist'       => (new ReleaseValidationService())->check($r, [], false),
            'approvals'       => $this->svc()->approvals($r),
            'rollout'         => (new RolloutService())->present($r),
            'manifest'        => (new ManifestService())->info($r),
            'server_meta'     => $app['kind'] === 'SERVER' ? $this->serverMetaData($r) : null,
            'campaign'        => $app['kind'] === 'SERVER' ? (new ServerUpdateService())->campaign($r) : null,
            'allowed_actions' => $this->svc()->allowedActions($r, $this->auth),
        ];
    }

    public function index()
    {
        [$limit, $offset] = $this->paging(50);
        $g   = $this->request->getGet();
        $db  = db_connect();
        $build = static function () use ($db, $g) {
            $q = $db->table('releases r')->join('applications a', 'a.id = r.application_id')->join('release_channels c', 'c.id = r.channel_id');
            if (! empty($g['app'])) {
                $q->groupStart()->where('a.slug', $g['app'])->orWhere('a.public_id', $g['app'])->groupEnd();
            }
            if (! empty($g['channel'])) {
                $q->whereIn('c.code', array_map('strtoupper', explode(',', (string) $g['channel'])));
            }
            if (! empty($g['platform'])) {
                $q->where('EXISTS (SELECT 1 FROM ' . $db->prefixTable('release_packages') . ' p WHERE p.release_id = r.id AND p.platform = ' . $db->escape((string) $g['platform']) . ')', null, false);
            }
            if (! empty($g['q'])) {
                $q->groupStart()->like('r.version', (string) $g['q'])->orLike('a.name', (string) $g['q'])->groupEnd();
            }

            return $q;
        };
        $counts = [];
        foreach ($build()->select('r.status, COUNT(*) AS c', false)->groupBy('r.status')->get()->getResultArray() as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }
        $q = $build();
        if (! empty($g['status'])) {
            $q->whereIn('r.status', array_map('strtoupper', explode(',', (string) $g['status'])));
        }
        $total = (clone $q)->countAllResults();
        $rows  = $q->select('r.*')->orderBy('r.updated_at', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return ['data' => array_map([self::class, 'present'], $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'counts' => $counts ?: new \stdClass()];
    }

    public function create()
    {
        $r = $this->svc()->create($this->body());

        return $this->ok(self::present($r), 201);
    }

    public function show(string $id)
    {
        return $this->full($this->load($id));
    }

    public function update(string $id)
    {
        return self::present($this->svc()->update($this->load($id), $this->body()));
    }

    public function checklist(string $id)
    {
        return (new ReleaseValidationService())->check($this->load($id));
    }

    public function changelog(string $id)
    {
        $r = $this->load($id);
        if (in_array($r['status'], ['WITHDRAWN'], true)) {
            throw new ApiException('RELEASE_IMMUTABLE', 'Withdrawn releases cannot be edited', 409);
        }

        return (new ChangelogService())->save($r, $this->body(), $this->auth->userId());
    }

    public function submitTest(string $id)
    {
        return self::present($this->svc()->submitForTest($this->load($id)));
    }

    public function startTesting(string $id)
    {
        return self::present($this->svc()->startTesting($this->load($id)));
    }

    public function qa(string $id)
    {
        $b = $this->body();

        return self::present($this->svc()->qa($this->load($id), (string) ($b['decision'] ?? 'PASS'), ($b['platform'] ?? null) ?: null, trim((string) ($b['comment'] ?? ''))));
    }

    public function approve(string $id)
    {
        return self::present($this->svc()->approve($this->load($id), trim((string) ($this->body()['comment'] ?? ''))));
    }

    public function publishSummary(string $id)
    {
        return $this->svc()->publishSummary($this->load($id));
    }

    public function publish(string $id)
    {
        $b = $this->body();
        $this->requireStepUp();

        return self::present($this->svc()->publish($this->load($id), $this->reason($b, false), $b['scheduled_publish_at'] ?? null));
    }

    public function pause(string $id)
    {
        $b = $this->body();
        $this->requireStepUp();

        return self::present($this->svc()->pause($this->load($id), (string) $this->reason($b)));
    }

    public function resume(string $id)
    {
        $b = $this->body();
        $this->requireStepUp();

        return self::present($this->svc()->resume($this->load($id), $this->reason($b, false)));
    }

    public function withdraw(string $id)
    {
        $b = $this->body();
        $this->reason($b);
        $this->requireStepUp();
        $r = $this->svc()->withdraw($this->load($id), (string) $b['reason'], (string) ($b['confirm_version'] ?? ''));

        return self::present($r) + ['minimum_lowered_to' => $r['minimum_lowered_to'] ?? null];
    }

    public function supersede(string $id)
    {
        $b = $this->body();
        $this->requireStepUp();

        return self::present($this->svc()->supersede($this->load($id), (string) $this->reason($b)));
    }

    public function policy(string $id)
    {
        $r = $this->load($id);
        $b = $this->body();
        if (in_array($r['status'], ['PUBLISHED', 'PAUSED'], true)) {
            $this->reason($b);
            $this->requireStepUp();
        }

        return self::present($this->svc()->setPolicy($r, $b));
    }

    public function supportState(string $id)
    {
        $b = $this->body();
        $this->reason($b);
        $this->requireStepUp();

        return self::present($this->svc()->setSupportState($this->load($id), (string) ($b['support_state'] ?? ''), (string) $b['reason']));
    }

    public function preview(string $id)
    {
        $r   = $this->load($id);
        $app = Catalog::appById((int) $r['application_id']);
        $pub = (new PublicCatalogService($this->auth))->releaseDetail($app, $r);
        $samples = [];
        $prev    = db_connect()->table('releases')->where('application_id', $app['id'])->where('channel_id', $r['channel_id'])->where('version_sort <', $r['version_sort'])->whereIn('status', ['PUBLISHED', 'SUPERSEDED'])->orderBy('version_sort', 'DESC')->get()->getRowArray();
        $from    = $prev['version'] ?? '0.0.1';
        $resolver = new UpdateResolverService();
        foreach ((new PackageService())->listForRelease($r) as $p) {
            if ($p['active_revision'] === null || $p['edition'] !== null) {
                continue;
            }
            // Simulated response: what a device on the previous version gets once this release is live.
            $status = match (true) {
                $r['release_type'] === 'CRITICAL'                                                      => 'UPDATE_REQUIRED',
                $r['release_type'] === 'MANDATORY' || $r['mandatory']                                  => ($r['mandatory_after'] && strtotime($r['mandatory_after'] . ' UTC') <= time()) ? 'UPDATE_REQUIRED' : 'UPDATE_RECOMMENDED',
                $r['release_type'] === 'RECOMMENDED'                                                   => 'UPDATE_RECOMMENDED',
                default                                                                                => 'UPDATE_AVAILABLE',
            };
            if ($r['minimum_supported_version'] && Semver::lt($from, $r['minimum_supported_version'])) {
                $status = 'UPDATE_REQUIRED';
            }
            $samples[] = [
                'sample'   => ['platform' => $p['platform'], 'architecture' => $p['architecture'], 'install_type' => $p['package_type'], 'current_version' => $from],
                'response' => ['status' => $status, 'latest_version' => $r['version'], 'build' => (int) $r['build_number'], 'release_type' => $r['release_type'], 'mandatory' => (bool) $r['mandatory'] || $r['release_type'] === 'MANDATORY', 'mandatory_after' => Catalog::iso($r['mandatory_after']), 'grace_minutes' => $r['grace_minutes'] === null ? null : (int) $r['grace_minutes'],
                    'effective_minimum' => $r['minimum_supported_version'], 'highlights' => $pub['changelog']['highlights'], 'rollout' => ['stage' => 1, 'percentage' => (new RolloutService())->present($r)['stages'][0] ?? 100],
                    'package' => ['type' => $p['package_type'], 'size' => $p['active_revision']['size'], 'sha256' => $p['active_revision']['sha256'], 'min_os' => $p['minimum_os_version']]],
            ];
        }
        unset($resolver);

        return ['public' => $pub, 'update_responses' => $samples];
    }

    public function manifest(string $id)
    {
        return (new ManifestService())->info($this->load($id));
    }

    public function regenerate(string $id)
    {
        $b = $this->body();
        $this->reason($b);
        $this->requireStepUp();

        return $this->svc()->regenerateManifest($this->load($id), (string) $b['reason']);
    }

    public function rollout(string $id)
    {
        return (new RolloutService())->present($this->load($id));
    }

    public function configureRollout(string $id)
    {
        $r = $this->load($id);
        if (in_array($r['status'], ['PUBLISHED', 'PAUSED'], true) && Catalog::channelById((int) $r['channel_id'])['code'] === 'STABLE') {
            $this->requireStepUp();
        }

        return (new RolloutService())->configure($r, $this->body());
    }

    public function rolloutAction(string $id, string $action)
    {
        $r = $this->load($id);
        if (Catalog::channelById((int) $r['channel_id'])['code'] === 'STABLE') {
            $this->requireStepUp();
        }

        return (new RolloutService())->action($r, $action, $this->body());
    }

    public function downloads(string $id)
    {
        $r  = $this->load($id);
        $db = db_connect();
        $by = $db->table('download_events')->select('platform, architecture, package_type, COUNT(*) AS c')->where('release_id', $r['id'])->where('is_bot', 0)->where('redirect_ok', 1)
            ->groupBy('platform')->groupBy('architecture')->groupBy('package_type')->orderBy('c', 'DESC')->get()->getResultArray();
        $series = $db->table('download_events')->select('SUBSTR(created_at, 1, 10) AS d, COUNT(*) AS c', false)->where('release_id', $r['id'])->where('is_bot', 0)->where('redirect_ok', 1)
            ->groupBy('d')->orderBy('d')->get()->getResultArray();

        return [
            'total'      => array_sum(array_map('intval', array_column($by, 'c'))),
            'by_package' => array_map(static fn ($p) => ['label' => PublicCatalogService::label((string) $p['platform'], (string) $p['architecture'], (string) $p['package_type']), 'platform' => $p['platform'], 'architecture' => $p['architecture'], 'package_type' => $p['package_type'], 'count' => (int) $p['c']], $by),
            'series'     => array_map(static fn ($s) => ['date' => $s['d'], 'count' => (int) $s['c']], $series),
        ];
    }

    public function devices(string $id)
    {
        $r     = $this->load($id);
        $tel   = new TelemetryService();
        $stats = $tel->releaseStats((int) $r['id']);
        $dist  = $tel->distribution((int) $r['application_id'], Catalog::channelById((int) $r['channel_id'])['code']);
        $types = array_column(db_connect()->table('update_events')->select('type, COUNT(*) AS c')->where('release_id', $r['id'])->groupBy('type')->get()->getResultArray(), 'c', 'type');

        return [
            'adoption'            => array_map(static fn ($v) => ['version' => $v['version'], 'count' => $v['count'], 'pct' => $v['pct']], $dist['by_version']),
            'update_success_rate' => $stats['success_rate'],
            'failures_by_code'    => $tel->updateStatus((int) $r['application_id'], (int) $r['id'])['failures_by_code'],
            'rolled_back'         => $stats['rolled_back'],
            'events_by_type'      => array_map('intval', $types) ?: new \stdClass(),
        ];
    }

    public function activity(string $id)
    {
        $r = $this->load($id);

        return ['data' => array_map([AdministrationController::class, 'presentAudit'], db_connect()->table('audit_logs')->where('entity_type', 'release')->where('entity_id', $r['public_id'])->orderBy('id', 'DESC')->limit(200)->get()->getResultArray())];
    }

    /** CI build attestation (FR-UC-CI-02, §2.2). */
    public function attestation(string $id)
    {
        $r   = $this->load($id);
        $b   = $this->body();
        $payload = $b['payload'] ?? null;
        if (! is_array($payload) || empty($b['signature']) || empty($b['key_id'])) {
            throw ApiException::validation(['payload' => 'payload, signature and key_id are required']);
        }
        $keys  = KeyRegistry::ciAttestationKeys();
        $ok    = isset($keys[$b['key_id']]) && Ed25519::verify(CanonicalJson::encode($payload), Base64Url::decode((string) $b['signature']), $keys[$b['key_id']]);
        $error = null;
        $app   = Catalog::appById((int) $r['application_id']);
        if (! $ok) {
            $error = isset($keys[$b['key_id']]) ? 'Signature invalid' : 'CI key ' . $b['key_id'] . ' is not pinned';
        } elseif (($payload['app_id'] ?? null) !== $app['app_identifier'] || (string) ($payload['version'] ?? '') !== $r['version'] || (int) ($payload['build'] ?? 0) !== (int) $r['build_number']) {
            $ok    = false;
            $error = 'Attestation app/version/build does not match the release';
        }
        db_connect()->table('build_attestations')->insert([
            'release_id' => $r['id'], 'ci_client_id' => $this->auth->clientId(), 'payload' => CanonicalJson::encode($payload),
            'signature' => (string) $b['signature'], 'key_id' => (string) $b['key_id'], 'verified' => $ok ? 1 : 0, 'verify_error' => $error,
            'verified_at' => $ok ? Catalog::now() : null, 'created_at' => Catalog::now(), 'updated_at' => Catalog::now(),
        ]);
        service('audit')->record('release.attestation_recorded', 'release', $r['public_id'], null, ['verified' => $ok, 'key_id' => $b['key_id'], 'files' => count((array) ($payload['files'] ?? [])), 'error' => $error]);
        if ($ok) {
            // Re-evaluate READY revisions against the new attestation.
            $hashes = array_map(static fn ($f) => strtolower((string) ($f['sha256'] ?? '')), (array) ($payload['files'] ?? []));
            foreach (db_connect()->table('release_package_revisions v')->select('v.id, v.sha256')->join('release_packages p', 'p.id = v.release_package_id')->where('p.release_id', $r['id'])->where('v.status', 'READY')->get()->getResultArray() as $v) {
                db_connect()->table('release_package_revisions')->where('id', $v['id'])->update(['attestation_match' => in_array($v['sha256'], $hashes, true) ? 'MATCH' : 'MISMATCH']);
            }
        }
        if (! $ok) {
            throw new ApiException('ATTESTATION_INVALID', $error, 422);
        }

        return ['verified' => true, 'error' => null];
    }

    public function createPackage(string $id)
    {
        $r = $this->load($id);
        $b = $this->body();
        $this->guardPackageWrite($r, $b);

        return $this->ok((new PackageService())->createUpload($r, $b, $this->auth), 201);
    }

    public function replacePackage(string $id, string $pkgId)
    {
        $r   = $this->load($id);
        $pkg = (new PackageService())->find($r, $pkgId);
        $b   = $this->body() + ['platform' => $pkg['platform'], 'architecture' => $pkg['architecture'], 'package_type' => $pkg['package_type'], 'edition' => $pkg['edition']];
        $this->guardPackageWrite($r, $b, true);

        return $this->ok((new PackageService())->createUpload($r, $b, $this->auth), 201);
    }

    private function guardPackageWrite(array $r, array $b, bool $replace = false): void
    {
        if (in_array($r['status'], ['PUBLISHED', 'PAUSED'], true)) {
            // Emergency replacement (§7.6): release.file.replace + MFA + reason.
            $this->requirePermission('release.file.replace');
            $this->reason($b);
            $this->requireStepUp();
        } elseif (! $this->auth->can('release.upload') && ! $this->auth->can('release.file.upload')) {
            throw ApiException::forbidden('Missing permission release.upload');
        }
    }

    public function completePackage(string $id, string $pkgId)
    {
        return (new PackageService())->completeMultipart($this->load($id), $pkgId, $this->body());
    }

    public function package(string $id, string $pkgId)
    {
        $r = $this->load($id);

        return (new PackageService())->present((new PackageService())->find($r, $pkgId));
    }

    public function deletePackage(string $id, string $pkgId)
    {
        $r   = $this->load($id);
        $b   = $this->body();
        $this->reason($b);
        $this->requireStepUp();
        $svc = new PackageService();
        $svc->delete($r, $svc->find($r, $pkgId), (string) $b['reason'], (string) ($b['confirm_file_name'] ?? ''));

        return ['ok' => true];
    }

    // ---- Server releases ----------------------------------------------------

    private function serverMetaData(array $r): array
    {
        $m = db_connect()->table('release_server_meta')->where('release_id', $r['id'])->get()->getRowArray() ?? [];

        return [
            'min_from_version'    => $m['min_from_version'] ?? null,
            'requires'            => json_decode((string) ($m['requires'] ?? 'null'), true),
            'migrations'          => json_decode((string) ($m['migrations'] ?? 'null'), true),
            'desktop_compat'      => $m['desktop_compat'] ?? null,
            'downtime_estimate_s' => isset($m['downtime_estimate_s']) ? (int) $m['downtime_estimate_s'] : null,
            'staging_test'        => json_decode((string) ($m['staging_test'] ?? 'null'), true),
            'admin_notice'        => $m['admin_notice'] ?? null,
            'advisory'            => $m['advisory'] ?? null,
        ];
    }

    public function serverMeta(string $id)
    {
        return $this->serverMetaData($this->load($id));
    }

    public function saveServerMeta(string $id)
    {
        $r = $this->load($id);
        if (Catalog::appById((int) $r['application_id'])['kind'] !== 'SERVER') {
            throw ApiException::validation(['release' => 'Not a server release']);
        }
        if (! in_array($r['status'], ReleaseService::PRE_PUBLISH, true)) {
            throw new ApiException('RELEASE_IMMUTABLE', 'Server metadata is frozen after publication', 409);
        }
        $b   = $this->body();
        $set = [];
        if (array_key_exists('min_from_version', $b)) {
            if ($b['min_from_version'] && ! Semver::isValid((string) $b['min_from_version'])) {
                throw ApiException::validation(['min_from_version' => 'Invalid SemVer']);
            }
            $set['min_from_version'] = $b['min_from_version'] ?: null;
        }
        foreach (['requires', 'migrations', 'staging_test'] as $j) {
            if (array_key_exists($j, $b)) {
                $set[$j] = $b[$j] === null ? null : json_encode($b[$j]);
            }
        }
        foreach (['desktop_compat', 'admin_notice', 'advisory'] as $s) {
            if (array_key_exists($s, $b)) {
                $set[$s] = $b[$s] === '' ? null : $b[$s];
            }
        }
        if (array_key_exists('downtime_estimate_s', $b)) {
            $set['downtime_estimate_s'] = $b['downtime_estimate_s'] === null ? null : (int) $b['downtime_estimate_s'];
        }
        $db = db_connect();
        if ($db->table('release_server_meta')->where('release_id', $r['id'])->countAllResults()) {
            $db->table('release_server_meta')->where('release_id', $r['id'])->update($set + ['updated_at' => Catalog::now()]);
        } else {
            $db->table('release_server_meta')->insert($set + ['release_id' => $r['id'], 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        }
        service('audit')->record('release.server_meta_changed', 'release', $r['public_id'], null, array_keys($set) === [] ? null : $b);

        return $this->serverMetaData($r);
    }

    public function campaign(string $id)
    {
        return (new ServerUpdateService())->campaign($this->load($id)) ?? ['status' => 'NONE'];
    }

    public function saveCampaign(string $id)
    {
        return (new ServerUpdateService())->configureCampaign($this->load($id), $this->body(), $this->auth->userId());
    }
}

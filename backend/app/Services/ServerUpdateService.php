<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendWebhookJob;
use App\Libraries\Queue;
use App\Libraries\Semver;
use App\Libraries\Signing\Ed25519;
use App\Libraries\Trust\TokenException;

/**
 * WorkSuite Web (Server) distribution (§17): update API for installations,
 * installation registry, admin-notification campaigns with reminders and the
 * managed fleet (FR-UC-SRV-09..16, WF-C22/C26/C29).
 */
class ServerUpdateService
{
    public const RANK = ['FREE' => 1, 'BUSINESS' => 2, 'ENTERPRISE' => 3, 'CUSTOM' => 3];

    /** Reminder cadence in hours and max repeats per urgency (§17.6). */
    public const REMINDERS = ['OPTIONAL' => [0, 0], 'RECOMMENDED' => [168, 3], 'MANDATORY' => [72, 1000], 'CRITICAL' => [24, 1000]];

    public static function editionAllows(string $licenseEdition, string $packageEdition): bool
    {
        $packageEdition = strtoupper($packageEdition);
        if (in_array($packageEdition, ['FREE', 'ALL'], true)) {
            return true;
        }

        return (self::RANK[strtoupper($licenseEdition)] ?? 0) >= (self::RANK[$packageEdition] ?? 99);
    }

    public function serverApp(): array
    {
        return db_connect()->table('applications')->where('kind', 'SERVER')->orderBy('id')->get()->getRowArray() ?? throw ApiException::notFound('APP_NOT_FOUND', 'Server application not configured');
    }

    /**
     * Authenticate an installation by its license token (FR-UC-SRV-11) or by the installation
     * key issued at registration (`Authorization: Installation <key>`, WS-CORE-SYSUPDATE §10).
     */
    public function installationFromLicense(?string $header): array
    {
        if ($header !== null && str_starts_with($header, 'Installation ')) {
            return (new ServerAutoUpdateService())->installationFromKey($header);
        }
        $token = $header !== null && str_starts_with($header, 'License ') ? substr($header, 8) : null;
        if ($token === null) {
            throw new ApiException('LICENSE_TOKEN_INVALID', 'Installation key or license token required (Authorization: Installation … or License …)', 401);
        }
        try {
            $lic = service('trustChain')->verifyLicenseToken($token);
        } catch (TokenException $e) {
            throw new ApiException('LICENSE_TOKEN_INVALID', 'Installation license invalid: ' . $e->getMessage(), 401);
        }
        $db  = db_connect();
        $row = $db->table('server_installations')->where('installation_id', $lic['installation_id'])->get()->getRowArray();
        if ($row === null) {
            $db->table('server_installations')->insert(['installation_id' => $lic['installation_id'], 'edition' => $lic['edition'], 'channel' => 'STABLE', 'mode' => 'SELF_HOSTED', 'build' => 0, 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
            $row = $db->table('server_installations')->where('installation_id', $lic['installation_id'])->get()->getRowArray();
        } elseif ($row['edition'] !== $lic['edition']) {
            $db->table('server_installations')->where('id', $row['id'])->update(['edition' => $lic['edition']]);
            $row['edition'] = $lic['edition'];
        }

        return $row + ['license' => $lic];
    }

    public function report(array $inst, array $in): array
    {
        $set = ['last_report_at' => Catalog::now(), 'updated_at' => Catalog::now()];
        foreach (['version' => 64, 'php_version' => 32, 'db_version' => 64, 'name' => 191, 'maintenance_window' => 64] as $k => $len) {
            if (isset($in[$k]) && is_string($in[$k])) {
                $set[$k] = substr($in[$k], 0, $len);
            }
        }
        if (isset($in['build'])) {
            $set['build'] = (int) $in['build'];
        }
        if (isset($in['channel'])) {
            $set['channel'] = strtoupper(substr((string) $in['channel'], 0, 32));
        }
        // WS-CORE-SYSUPDATE FR-SU-LINK-03 heartbeat: runtime versions, disk, policy mode, health (no business data).
        if (isset($in['node_version']) && is_string($in['node_version'])) {
            $set['node_version'] = substr($in['node_version'], 0, 32);
        }
        if (isset($in['mariadb_version']) && is_string($in['mariadb_version'])) {
            $set['db_version'] = substr($in['mariadb_version'], 0, 64);
        }
        if (isset($in['disk_free_mb']) && is_numeric($in['disk_free_mb'])) {
            $set['disk_free_mb'] = max(0, (int) $in['disk_free_mb']);
        }
        if (isset($in['policy_mode']) && in_array(strtoupper((string) $in['policy_mode']), ServerAutoUpdateService::MODES, true)) {
            $set['policy_mode'] = strtoupper((string) $in['policy_mode']);
        }
        if (isset($in['health']) && in_array(strtoupper((string) $in['health']), ['OK', 'DEGRADED', 'DOWN'], true)) {
            $set['health'] = strtoupper((string) $in['health']);
        }
        if (array_key_exists('offline_mode', $in)) {
            $set['offline_mode'] = $in['offline_mode'] ? 1 : 0;
        }
        if (isset($in['last_run']['result']) && in_array(strtoupper((string) $in['last_run']['result']), ['SUCCEEDED', 'ROLLED_BACK', 'FAILED', 'CANCELLED'], true)) {
            $set['last_run_status'] = strtoupper((string) $in['last_run']['result']);
        }
        if (isset($in['mode']) && in_array(strtoupper((string) $in['mode']), ['MANAGED', 'SELF_HOSTED', 'AIR_GAPPED'], true)) {
            $set['mode'] = strtoupper((string) $in['mode']);
        }
        // Contact and webhook only with explicit opt-in (§17.13: no hostnames unless opted in).
        if (! empty($in['notify_opt_in'])) {
            $set['notify_contact'] = filter_var($in['notify_contact'] ?? '', FILTER_VALIDATE_EMAIL) ?: null;
        }
        if (isset($in['webhook_url']) && preg_match('#^https://#', (string) $in['webhook_url'])) {
            $set['webhook_url'] = substr((string) $in['webhook_url'], 0, 512);
        }
        db_connect()->table('server_installations')->where('id', $inst['id'])->update($set);

        return ['ok' => true, 'installation_id' => $inst['installation_id']];
    }

    public function events(array $inst, array $events): array
    {
        $db = db_connect();
        $n  = 0;
        foreach (array_slice($events, 0, 100) as $e) {
            $id   = (string) ($e['event_id'] ?? '');
            $type = (string) ($e['type'] ?? '');
            if ($id === '' || ! in_array($type, ['started', 'completed', 'failed', 'rolled_back', 'scheduled', 'postponed', 'skipped'], true) || $db->table('server_update_events')->where('event_id', $id)->countAllResults()) {
                continue;
            }
            $release = isset($e['to']) ? $db->table('releases')->where('application_id', $this->serverApp()['id'])->where('version', (string) $e['to'])->get()->getRowArray() : null;
            (new ServerAutoUpdateService())->onEvent($inst, $release, $type, isset($e['step']) ? substr((string) $e['step'], 0, 32) : (isset($e['reason']) ? substr((string) $e['reason'], 0, 200) : null));
            if (in_array($type, ['scheduled', 'postponed', 'skipped'], true)) {
                // Plan decisions (FR-SU-PLAN-02) are recorded on the plan, not as runs.
                $db->table('server_update_events')->insert(['event_id' => $id, 'installation_id' => $inst['id'], 'release_id' => $release['id'] ?? null, 'type' => $type, 'from_version' => $e['from'] ?? null, 'to_version' => $e['to'] ?? null, 'occurred_at' => isset($e['at']) && strtotime((string) $e['at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $e['at'])) : Catalog::now()]);
                $n++;

                continue;
            }
            $db->table('server_update_events')->insert([
                'event_id' => $id, 'installation_id' => $inst['id'], 'release_id' => $release['id'] ?? null, 'type' => $type,
                'step' => isset($e['step']) ? substr((string) $e['step'], 0, 32) : null, 'error_code' => isset($e['error_code']) ? substr((string) $e['error_code'], 0, 64) : null,
                'from_version' => $e['from'] ?? null, 'to_version' => $e['to'] ?? null,
                'occurred_at' => isset($e['at']) && strtotime((string) $e['at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $e['at'])) : Catalog::now(),
            ]);
            $status = strtoupper($type === 'rolled_back' ? 'ROLLED_BACK' : $type);
            $set    = ['last_run_status' => $status, 'updated_at' => Catalog::now()];
            if ($type === 'completed' && isset($e['to'])) {
                $set['version'] = (string) $e['to'];
                $set['support_state'] = 'SUPPORTED';
            }
            $db->table('server_installations')->where('id', $inst['id'])->update($set);
            if (in_array($type, ['failed', 'rolled_back'], true)) {
                service('notifier')->notify('server.installation_failed', ['title' => 'Server update ' . str_replace('_', ' ', $type), 'body' => ($inst['name'] ?: $inst['installation_id']) . ': ' . ($e['to'] ?? '?') . ' failed at ' . ($e['step'] ?? '?') . ' (' . ($e['error_code'] ?? 'no code') . ')', 'link' => '/admin/installations'], null, false);
            }
            $n++;
        }

        return ['accepted' => $n];
    }

    /**
     * GET /server/update (§17.10): status + edition package + upgrade chain.
     */
    public function check(array $inst, array $q): array
    {
        $app     = $this->serverApp();
        $version = Semver::normalize((string) ($q['version'] ?? $inst['version'] ?? '0.0.0'));
        $edition = strtoupper((string) ($q['edition'] ?? $inst['edition']));
        if (! self::editionAllows($inst['license']['edition'], $edition)) {
            throw new ApiException('LICENSE_TOKEN_INVALID', "License does not cover the {$edition} edition", 403);
        }
        $channel = Catalog::channel((string) ($q['channel'] ?? $inst['channel'] ?? 'STABLE'), (int) $app['id']);
        if (! $channel['is_public'] && ! in_array($channel['code'], ['BETA', 'LTS'], true)) {
            $channel = Catalog::channel('STABLE');
        }
        if ($channel['code'] === 'LTS' && strtoupper((string) $inst['license']['edition']) !== 'ENTERPRISE') {
            $channel = Catalog::channel('STABLE'); // lts is Enterprise only (FR-SU-LINK-02)
        }
        db_connect()->table('server_installations')->where('id', $inst['id'])->update(['last_check_at' => Catalog::now(), 'version' => $version, 'build' => (int) ($q['build'] ?? $inst['build']), 'channel' => $channel['code']]);

        $db       = db_connect();
        $releases = $db->table('releases')->where('application_id', $app['id'])->where('channel_id', $channel['id'])->whereIn('status', ['PUBLISHED', 'SUPERSEDED'])
            ->where('support_state !=', 'BLOCKED')->orderBy('version_sort', 'DESC')->get()->getResultArray();
        $offered  = null;
        $campaign = null;
        foreach ($releases as $r) {
            $c   = $db->table('notification_campaigns')->where('release_id', $r['id'])->orderBy('id', 'DESC')->get()->getRowArray();
            $pct = $c ? (int) $c['rollout_pct'] : 100;
            if ($r['status'] === 'PUBLISHED' && Semver::compare($r['version'], $version) > 0 && ! RolloutService::inCohort($r['public_id'], $inst['installation_id'], $pct)) {
                continue;
            }
            if ($c && ! $this->inAudience($c, $inst, $version)) {
                continue;
            }
            $offered  = $r;
            $campaign = $c;
            break;
        }
        $curRow = $db->table('releases')->where('application_id', $app['id'])->where('version', $version)->get()->getRowArray();
        $resolver = new UpdateResolverService();

        $status  = 'CURRENT';
        $chain   = [];
        $package = null;
        if ($curRow && $curRow['status'] === 'WITHDRAWN') {
            $status = 'RELEASE_WITHDRAWN';
        } elseif (($curRow && in_array($curRow['support_state'], ['UNSUPPORTED', 'BLOCKED'], true)) || $inst['support_state'] === 'UNSUPPORTED') {
            $status = 'UNSUPPORTED';
        }
        if ($offered && Semver::compare($offered['version'], $version) > 0) {
            $urgency  = $campaign['urgency'] ?? $offered['release_type'];
            $deadline = $campaign['deadline'] ?? $offered['mandatory_after'];
            if ($status === 'CURRENT') {
                $status = match (true) {
                    $urgency === 'CRITICAL'                                                                              => 'UPDATE_REQUIRED',
                    $urgency === 'MANDATORY' && $deadline !== null && strtotime($deadline . ' UTC') <= time()           => 'UPDATE_REQUIRED',
                    in_array($urgency, ['MANDATORY', 'RECOMMENDED'], true)                                               => 'UPDATE_RECOMMENDED',
                    default                                                                                              => 'UPDATE_AVAILABLE',
                };
            }
            $chain   = $this->upgradeChain($app, $channel, $version, $offered);
            $first   = $chain[0] ?? $offered;
            $pkgRow  = $resolver->packageFor($first, 'server', 'any', 'zip', $edition) ?? $resolver->packageFor($first, 'server', 'any', 'zip', 'ALL');
            if ($pkgRow) {
                $grant   = $resolver->issueGrant($pkgRow, $inst['installation_id'], 6 * 3600);
                $meta    = json_decode((string) db_connect()->table('release_package_revisions')->select('metadata')->where('public_id', $pkgRow['rev_public_id'])->get()->getRow('metadata'), true) ?: [];
                $package = ['id' => $pkgRow['public_id'], 'revision_id' => $pkgRow['rev_public_id'], 'edition' => strtolower($pkgRow['edition']), 'scope' => $pkgRow['scope'], 'file_name' => $pkgRow['file_name'], 'size' => (int) $pkgRow['size_bytes'], 'sha256' => $pkgRow['sha256'], 'package_json' => $meta['package_json'] ?? null, 'download_grant' => $grant['grant']];
            }
        }
        // FR-DL-MIN-01: below the channel minimum → REQUIRED notice (never a remote disable).
        $min        = (new MinimumVersionService())->channelMinimum((int) $app['id'], (int) $channel['id'], null);
        $belowMin   = false;
        try {
            $belowMin = $min !== null && Semver::lt($version, (string) $min['minimum_version']);
        } catch (\Throwable) {
        }
        if ($belowMin && $offered && Semver::compare($offered['version'], $version) > 0 && in_array($status, ['UPDATE_AVAILABLE', 'UPDATE_RECOMMENDED'], true)) {
            $status = 'UPDATE_REQUIRED';
        }
        $withdrawn = $db->table('releases')->select('public_id, version, withdrawn_at')->where('application_id', $app['id'])->where('channel_id', $channel['id'])->where('status', 'WITHDRAWN')->orderBy('version_sort', 'DESC')->limit(20)->get()->getResultArray();
        $rollbackAsked = $curRow && $curRow['status'] === 'WITHDRAWN'
            && $db->table('server_update_plans')->where('installation_id', $inst['id'])->where('release_id', $curRow['id'])->where('rollback_requested', 1)->countAllResults() > 0;
        $ringInfo = null;
        if ($offered && Semver::compare($offered['version'], $version) > 0) {
            $policy   = (new ServerAutoUpdateService())->policyRow((int) $inst['id']);
            $ringNo   = ServerRingService::ringOf($inst, $policy['ring'] ?? null);
            $ringSvc  = new ServerRingService();
            $openTs   = $ringSvc->openAt($offered, $ringNo);
            $ringInfo = ['ring' => $ringNo, 'ring_name' => ServerRingService::RINGS[$ringNo], 'ring_open' => $ringSvc->isOpen($offered, $ringNo), 'ring_open_at' => $openTs === null ? null : gmdate('Y-m-d\TH:i:s\Z', $openTs)];
        }
        $switch = $this->editionSwitch($inst, $q, $edition, $curRow, $resolver);
        $meta = $offered ? $db->table('release_server_meta')->where('release_id', $offered['id'])->get()->getRowArray() : null;
        $cl   = $offered ? (new ChangelogService())->get((int) $offered['id']) : null;
        $fleet = null;
        if ($inst['mode'] === 'MANAGED' && $campaign && $campaign['fleet_status'] === 'ACTIVE') {
            $stages = json_decode((string) $campaign['fleet_stages'], true) ?: [100];
            $pct    = $stages[max(0, (int) $campaign['fleet_stage_no'] - 1)] ?? 100;
            $fleet  = ['auto_apply' => RolloutService::inCohort('fleet' . $offered['public_id'], $inst['installation_id'], $pct), 'window' => $campaign['fleet_window'], 'stage' => (int) $campaign['fleet_stage_no']];
        }
        $cfg = config('UpdateCenter');
        $doc = [
            'status'             => $status,
            'product'            => 'worksuite-server',
            'installation_id'    => $inst['installation_id'],
            'edition'            => strtolower($edition),
            'channel'            => strtolower($channel['code']),
            'current_version'    => $version,
            'latest_version'     => $offered['version'] ?? null,
            'build'              => $offered ? (int) $offered['build_number'] : null,
            'release_id'         => $offered['public_id'] ?? null,
            'urgency'            => $campaign['urgency'] ?? ($offered['release_type'] ?? null),
            'deadline'           => Catalog::iso($campaign['deadline'] ?? ($offered['mandatory_after'] ?? null)),
            'min_from_version'   => $meta['min_from_version'] ?? null,
            'upgrade_chain'      => array_map(static fn ($r) => ['release_id' => $r['public_id'], 'version' => $r['version'], 'build' => (int) $r['build_number']], $chain),
            'package'            => $package,
            'migrations'         => json_decode((string) ($meta['migrations'] ?? 'null'), true),
            'requires'           => json_decode((string) ($meta['requires'] ?? 'null'), true),
            'desktop_compat'     => $meta['desktop_compat'] ?? null,
            'downtime_estimate_s' => isset($meta['downtime_estimate_s']) ? (int) $meta['downtime_estimate_s'] : null,
            'admin_notice'       => $meta['admin_notice'] ?? null,
            'advisory'           => $meta['advisory'] ?? null,
            'highlights'         => $cl ? (json_decode((string) $cl['highlights'], true) ?: []) : [],
            'release_notes_url'  => $offered ? rtrim($cfg->publicBaseUrl, '/') . '/apps/' . $app['slug'] . '/releases/' . $offered['version'] : null,
            'release_manifest_url' => $offered ? '/api/v1/releases/' . $offered['public_id'] . '/manifest' : null,
            'fleet'              => $fleet,
            'edition_switch'     => $switch,
            'severity'           => $offered ? ServerRingService::severity($offered) : null,
            'minimum_supported_version' => $min['minimum_version'] ?? null,
            'below_minimum'      => $belowMin,
            'rollout'            => $ringInfo,
            'withdrawn_releases' => array_map(static fn ($w) => ['release_id' => $w['public_id'], 'version' => $w['version'], 'withdrawn_at' => Catalog::iso($w['withdrawn_at'])], $withdrawn),
            'rollback_requested' => $rollbackAsked,
            'issued_at'          => gmdate('Y-m-d\TH:i:s\Z'),
            'expires_at'         => gmdate('Y-m-d\TH:i:s\Z', time() + $cfg->updateResponseTtl),
        ];

        return Ed25519::signDocument($doc, service('responseKeys'), $cfg->responseKeyId);
    }

    /** FR-UC-SRV-13: releases to apply in order when below min_from_version. */
    /**
     * Edition switch (FR-TEN-UPD-17, AC-C54): after a license edition change the
     * installation is offered the package of its *current* version for the new
     * edition. Downgrades disable paid modules but keep their data (tenant side).
     */
    public function editionSwitch(array $inst, array $q, string $edition, ?array $curRow, UpdateResolverService $resolver): ?array
    {
        $installed = strtoupper(trim((string) ($q['installed_edition'] ?? '')));
        if ($installed === '' || $installed === $edition || ! isset(self::RANK[$installed]) || ! $curRow
            || ! in_array($curRow['status'], ['PUBLISHED', 'SUPERSEDED'], true)) {
            return null;
        }
        $pkgRow = $resolver->packageFor($curRow, 'server', 'any', 'zip', $edition);
        if (! $pkgRow) {
            return null;
        }
        $grant = $resolver->issueGrant($pkgRow, $inst['installation_id'], 6 * 3600);

        return [
            'from'      => strtolower($installed),
            'to'        => strtolower($edition),
            'direction' => (self::RANK[$edition] ?? 0) >= self::RANK[$installed] ? 'UPGRADE' : 'DOWNGRADE',
            'version'   => $curRow['version'],
            'release_id' => $curRow['public_id'],
            'package'   => ['id' => $pkgRow['public_id'], 'revision_id' => $pkgRow['rev_public_id'], 'edition' => strtolower($pkgRow['edition']), 'file_name' => $pkgRow['file_name'], 'size' => (int) $pkgRow['size_bytes'], 'sha256' => $pkgRow['sha256'], 'download_grant' => $grant['grant']],
            'keeps_data' => true,
        ];
    }

    public function upgradeChain(array $app, array $channel, string $from, array $target): array
    {
        $db   = db_connect();
        $min  = static fn (array $r) => $db->table('release_server_meta')->select('min_from_version')->where('release_id', $r['id'])->get()->getRow('min_from_version');
        $all  = $db->table('releases')->where('application_id', $app['id'])->where('channel_id', $channel['id'])->whereIn('status', ['PUBLISHED', 'SUPERSEDED'])->where('support_state !=', 'BLOCKED')->orderBy('version_sort')->get()->getResultArray();
        $chain = [];
        $cur   = $from;
        for ($guard = 0; $guard < 20; $guard++) {
            $tMin = $min($target);
            if ($tMin === null || Semver::gte($cur, $tMin)) {
                break;
            }
            $step = null;
            foreach ($all as $r) {
                if (Semver::compare($r['version'], $cur) > 0 && Semver::compare($r['version'], $target['version']) < 0) {
                    $rMin = $min($r);
                    if ($rMin === null || Semver::gte($cur, $rMin)) {
                        $step = $r; // keep the highest reachable
                    }
                }
            }
            if ($step === null) {
                break;
            }
            $chain[] = $step;
            $cur     = $step['version'];
        }
        if ($chain !== []) {
            $chain[] = $target;
        }

        return $chain;
    }

    private function inAudience(array $campaign, array $inst, string $version): bool
    {
        $a = json_decode((string) $campaign['audience'], true) ?: [];
        if (! empty($a['editions']) && ! in_array(strtoupper($inst['edition']), array_map('strtoupper', $a['editions']), true) && ! in_array('ALL', array_map('strtoupper', $a['editions']), true)) {
            return false;
        }
        if (! empty($a['modes']) && ! in_array($inst['mode'], $a['modes'], true)) {
            return false;
        }
        if (! empty($a['version_range'])) {
            try {
                if (! Semver::satisfies($version, (string) $a['version_range'])) {
                    return false;
                }
            } catch (\Throwable) {
            }
        }

        return true;
    }

    // ---- Campaigns -----------------------------------------------------

    public function campaign(array $release): ?array
    {
        $c = db_connect()->table('notification_campaigns')->where('release_id', $release['id'])->orderBy('id', 'DESC')->get()->getRowArray();

        return $c ? $this->presentCampaign($c, $release) : null;
    }

    public function presentCampaign(array $c, array $release): array
    {
        return [
            'id'             => (int) $c['id'],
            'audience'       => json_decode((string) $c['audience'], true) ?: [],
            'urgency'        => $c['urgency'],
            'deadline'       => Catalog::iso($c['deadline']),
            'rollout_pct'    => (int) $c['rollout_pct'],
            'channels'       => json_decode((string) $c['channels'], true) ?: [],
            'fleet_window'   => $c['fleet_window'],
            'fleet_stages'   => json_decode((string) $c['fleet_stages'], true) ?: [5, 25, 100],
            'fleet_status'   => $c['fleet_status'],
            'fleet_stage_no' => (int) $c['fleet_stage_no'],
            'status'         => $c['status'],
            'sent_count'     => (int) $c['sent_count'],
            'reminders_sent' => (int) $c['reminders_sent'],
            'eligible_count' => count($this->eligible($c, $release)),
        ];
    }

    public function configureCampaign(array $release, array $in, ?int $userId): array
    {
        $urgency = strtoupper((string) ($in['urgency'] ?? $release['release_type']));
        if (! in_array($urgency, ReleaseService::TYPES, true)) {
            throw ApiException::validation(['urgency' => 'OPTIONAL, RECOMMENDED, MANDATORY or CRITICAL']);
        }
        $channels = array_values(array_intersect((array) ($in['channels'] ?? ['webhook', 'email', 'in_app']), ['webhook', 'email', 'in_app', 'whatsapp']));
        $stages   = array_values(array_map('intval', (array) ($in['fleet_stages'] ?? [5, 25, 100])));
        $row      = [
            'release_id'   => $release['id'],
            'audience'     => json_encode(['editions' => array_values((array) ($in['audience']['editions'] ?? ['FREE', 'BUSINESS', 'ENTERPRISE'])), 'channels' => (array) ($in['audience']['channels'] ?? ['STABLE']), 'version_range' => (string) ($in['audience']['version_range'] ?? ''), 'modes' => array_values((array) ($in['audience']['modes'] ?? ['MANAGED', 'SELF_HOSTED', 'AIR_GAPPED']))]),
            'urgency'      => $urgency,
            'deadline'     => Catalog::toDb($in['deadline'] ?? null),
            'rollout_pct'  => max(1, min(100, (int) ($in['rollout_pct'] ?? ($urgency === 'CRITICAL' ? 100 : 100)))),
            'channels'     => json_encode($channels),
            'fleet_window' => $in['fleet_window'] ?? null,
            'fleet_stages' => json_encode($stages ?: [100]),
            'created_by'   => $userId,
            'updated_at'   => Catalog::now(),
        ];
        if ($urgency === 'CRITICAL') {
            $row['rollout_pct'] = 100; // WF-C26: campaign 100 %
        }
        $db       = db_connect();
        $existing = $db->table('notification_campaigns')->where('release_id', $release['id'])->orderBy('id', 'DESC')->get()->getRowArray();
        if ($existing) {
            $db->table('notification_campaigns')->where('id', $existing['id'])->update($row);
        } else {
            $db->table('notification_campaigns')->insert($row + ['status' => 'DRAFT', 'created_at' => Catalog::now()]);
        }
        service('audit')->record('campaign.configured', 'release', $release['public_id'], null, ['urgency' => $urgency, 'rollout_pct' => $row['rollout_pct'], 'channels' => $channels]);
        if (in_array($release['status'], ['PUBLISHED'], true) && ! $existing) {
            $this->send($release);
        }

        return $this->campaign($release);
    }

    /** @return list<array<string,mixed>> */
    private function eligible(array $c, array $release): array
    {
        $out = [];
        foreach (db_connect()->table('server_installations')->get()->getResultArray() as $inst) {
            $v = $inst['version'] ?: '0.0.0';
            try {
                if (Semver::gte($v, $release['version'])) {
                    continue;
                }
            } catch (\Throwable) {
            }
            if (! $this->inAudience($c, $inst, $v)) {
                continue;
            }
            if (! RolloutService::inCohort($release['public_id'], $inst['installation_id'], (int) $c['rollout_pct'])) {
                continue;
            }
            $out[] = $inst;
        }

        return $out;
    }

    /** WF-C22: webhooks + emails to eligible installations. */
    public function send(array $release, bool $reminder = false): int
    {
        $db = db_connect();
        $c  = $db->table('notification_campaigns')->where('release_id', $release['id'])->orderBy('id', 'DESC')->get()->getRowArray();
        if (! $c) {
            $this->configureCampaign($release, [], null);
            $c = $db->table('notification_campaigns')->where('release_id', $release['id'])->orderBy('id', 'DESC')->get()->getRowArray();
        }
        $channels = json_decode((string) $c['channels'], true) ?: [];
        $app      = Catalog::appById((int) $release['application_id']);
        $meta     = $db->table('release_server_meta')->where('release_id', $release['id'])->get()->getRowArray();
        $editions = array_map(static fn ($e) => strtolower($e), array_column($db->table('release_packages')->select('edition')->where('release_id', $release['id'])->where('status', 'READY')->get()->getResultArray(), 'edition'));
        $event    = $c['urgency'] === 'CRITICAL' ? 'server.release.advisory' : 'server.release.published';
        $payload  = [
            'app' => $app['app_identifier'], 'version' => $release['version'], 'build' => (int) $release['build_number'], 'release_id' => $release['public_id'],
            'editions' => $editions, 'urgency' => $c['urgency'], 'deadline' => Catalog::iso($c['deadline']),
            'notes_url' => rtrim(config('UpdateCenter')->publicBaseUrl, '/') . '/apps/' . $app['slug'] . '/releases/' . $release['version'],
            'admin_notice' => $meta['admin_notice'] ?? null, 'advisory' => $meta['advisory'] ?? null, 'reminder' => $reminder,
            'severity' => ServerRingService::severity($release, $meta ?: null),
        ];
        $sent = 0;
        foreach ($this->eligible($c, $release) as $inst) {
            if (in_array('webhook', $channels, true) && $inst['webhook_url']) {
                Queue::push(SendWebhookJob::class, ['url' => $inst['webhook_url'], 'event' => $event, 'payload' => $payload + ['installation_id' => $inst['installation_id']], 'secret_ref' => 'UC_APP_SECRET'], 'webhooks', 0, 8);
                $db->table('campaign_deliveries')->insert(['campaign_id' => $c['id'], 'installation_id' => $inst['id'], 'channel' => 'webhook', 'status' => 'QUEUED', 'sent_at' => Catalog::now(), 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
            }
            // Email the opted-in license contact and the registered admin contacts (FR-DL-NOTIFY-01) —
            // always for installations without a webhook (WF-C22 step 3). Deduplicated (Q4).
            $to = array_values(array_unique(array_filter(array_merge([$inst['notify_contact'] ?? null], json_decode((string) ($inst['admin_contacts'] ?? '[]'), true) ?: []))));
            if ($to !== [] && (in_array('email', $channels, true) || ! $inst['webhook_url'] || in_array($c['urgency'], ['MANDATORY', 'CRITICAL'], true))) {
                $security = $c['urgency'] === 'CRITICAL' || in_array($payload['severity'], ServerRingService::SECURITY_SEVERITIES, true);
                $subject  = ($security ? '[Security] ' : '') . ($reminder ? 'Reminder: ' : '') . "WorkSuite {$release['version']} is available for your installation";
                foreach ($to as $addr) {
                    service('notifier')->email($addr, $subject, "A WorkSuite Server update ({$release['version']}, " . strtolower($c['urgency']) . ") is available.\n\nOpen Admin Center › System › Updates on your server to review and install it.\nRelease notes: {$payload['notes_url']}" . ($c['deadline'] ? "\nSupport deadline: " . Catalog::iso($c['deadline']) : ''));
                    $db->table('campaign_deliveries')->insert(['campaign_id' => $c['id'], 'installation_id' => $inst['id'], 'channel' => 'email', 'status' => 'QUEUED', 'sent_at' => Catalog::now(), 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
                }
            }
            $sent++;
        }
        $upd = ['status' => 'SENT', 'updated_at' => Catalog::now()];
        if ($reminder) {
            $upd['last_reminder_at'] = Catalog::now();
            $db->table('notification_campaigns')->where('id', $c['id'])->set('reminders_sent', 'reminders_sent + 1', false)->update($upd);
        } else {
            $upd['sent_count'] = $sent;
            $upd['last_reminder_at'] = Catalog::now();
            $db->table('notification_campaigns')->where('id', $c['id'])->update($upd);
        }

        return $sent;
    }

    public function notifyWithdrawn(array $release, string $reason, bool $rollbackRequested = false): void
    {
        $app = Catalog::appById((int) $release['application_id']);
        foreach (db_connect()->table('server_installations')->where('webhook_url IS NOT NULL')->get()->getResultArray() as $inst) {
            Queue::push(SendWebhookJob::class, ['url' => $inst['webhook_url'], 'event' => 'server.release.withdrawn', 'payload' => ['app' => $app['app_identifier'], 'version' => $release['version'], 'release_id' => $release['public_id'], 'reason' => $reason, 'installation_id' => $inst['installation_id'], 'rollback_requested' => $rollbackRequested && $inst['version'] === $release['version']], 'secret_ref' => 'UC_APP_SECRET'], 'webhooks', 0, 8);
        }
    }

    public function onPublished(array $release): void
    {
        $c = db_connect()->table('notification_campaigns')->where('release_id', $release['id'])->countAllResults();
        if ($c === 0) {
            $this->configureCampaign($release, [], null); // defaults from the release type
        }
        $this->send(Catalog::releaseById((int) $release['id']));
    }

    /** Scheduler: reminders (§17.6) and UNSUPPORTED after deadline (WF-C26). */
    public function runReminders(): array
    {
        $db  = db_connect();
        $out = [];
        foreach ($db->table('notification_campaigns c')->select('c.*')->join('releases r', 'r.id = c.release_id')->where('r.status', 'PUBLISHED')->where('c.status', 'SENT')->get()->getResultArray() as $c) {
            [$hours, $max] = self::REMINDERS[$c['urgency']] ?? [0, 0];
            $release       = Catalog::releaseById((int) $c['release_id']);
            $sevMeta       = $db->table('release_server_meta')->select('severity')->where('release_id', $release['id'])->get()->getRow('severity');
            if (in_array(strtoupper((string) $sevMeta), ServerRingService::SECURITY_SEVERITIES, true)) {
                [$hours, $max] = [168, 1000]; // FR-DL-NOTIFY-01: reminder every 7 days until applied
            }
            if ($hours > 0 && (int) $c['reminders_sent'] < $max && $c['last_reminder_at'] && strtotime($c['last_reminder_at'] . ' UTC') <= time() - $hours * 3600) {
                $n     = $this->send($release, true);
                $out[] = "reminded {$n} installations for {$release['version']}";
            }
            if ($c['deadline'] && strtotime($c['deadline'] . ' UTC') <= time() && in_array($c['urgency'], ['MANDATORY', 'CRITICAL'], true)) {
                foreach ($this->eligible($c, $release) as $inst) {
                    if ($inst['support_state'] !== 'UNSUPPORTED' && ! ($c['urgency'] === 'CRITICAL' && $inst['advisory_ack_at'])) {
                        $db->table('server_installations')->where('id', $inst['id'])->update(['support_state' => 'UNSUPPORTED']);
                        $out[] = "marked {$inst['installation_id']} UNSUPPORTED";
                    }
                }
            }
        }

        return $out;
    }

    public function acknowledgeAdvisory(array $inst): void
    {
        db_connect()->table('server_installations')->where('id', $inst['id'])->update(['advisory_ack_at' => Catalog::now()]);
    }

    // ---- Managed fleet (FR-UC-SRV-15, WF-C29) --------------------------

    public function fleet(array $release, string $action): array
    {
        $db = db_connect();
        $c  = $db->table('notification_campaigns')->where('release_id', $release['id'])->orderBy('id', 'DESC')->get()->getRowArray();
        if (! $c) {
            $this->configureCampaign($release, [], null);
            $c = $db->table('notification_campaigns')->where('release_id', $release['id'])->orderBy('id', 'DESC')->get()->getRowArray();
        }
        if ($release['status'] !== 'PUBLISHED') {
            throw new ApiException('INVALID_TRANSITION', 'Fleet rollouts need a published release', 409);
        }
        $stages = json_decode((string) $c['fleet_stages'], true) ?: [100];
        $set    = match ($action) {
            'start'   => ['fleet_status' => 'ACTIVE', 'fleet_stage_no' => 1],
            'pause'   => ['fleet_status' => 'PAUSED'],
            'resume'  => ['fleet_status' => 'ACTIVE'],
            'advance' => (int) $c['fleet_stage_no'] >= count($stages) ? ['fleet_status' => 'DONE'] : ['fleet_stage_no' => (int) $c['fleet_stage_no'] + 1],
            default   => throw ApiException::notFound('UNKNOWN_ACTION', 'Unknown fleet action'),
        };
        $db->table('notification_campaigns')->where('id', $c['id'])->update($set + ['updated_at' => Catalog::now()]);
        service('audit')->record('fleet.' . $action, 'release', $release['public_id'], ['fleet_status' => $c['fleet_status'], 'stage' => (int) $c['fleet_stage_no']], $set);

        return $this->campaign($release);
    }

    /** Scheduler: auto-pause fleet rollouts on failure rate (WF-C29 step 3). */
    public function evaluateFleet(float $maxFailureRate = 0.1): array
    {
        $db  = db_connect();
        $out = [];
        foreach ($db->table('notification_campaigns')->where('fleet_status', 'ACTIVE')->get()->getResultArray() as $c) {
            $done = $db->table('server_update_events e')->join('server_installations i', 'i.id = e.installation_id')->where('e.release_id', $c['release_id'])->where('i.mode', 'MANAGED')->whereIn('e.type', ['completed', 'failed', 'rolled_back'])->countAllResults();
            $bad  = $db->table('server_update_events e')->join('server_installations i', 'i.id = e.installation_id')->where('e.release_id', $c['release_id'])->where('i.mode', 'MANAGED')->whereIn('e.type', ['failed', 'rolled_back'])->countAllResults();
            if ($done >= 3 && $bad / $done > $maxFailureRate) {
                $db->table('notification_campaigns')->where('id', $c['id'])->update(['fleet_status' => 'PAUSED']);
                $release = Catalog::releaseById((int) $c['release_id']);
                service('notifier')->notify('rollout.alert', ['title' => 'Managed fleet paused', 'body' => "WorkSuite Server {$release['version']}: {$bad}/{$done} hosted updates failed or rolled back.", 'link' => '/admin/fleet']);
                service('audit')->record('fleet.auto_paused', 'release', $release['public_id'], null, ['failed' => $bad, 'total' => $done], null, ['type' => 'SYSTEM', 'id' => 'scheduler', 'label' => 'fleet evaluator']);
                $out[] = "paused fleet for {$release['version']}";
            }
        }

        return $out;
    }

    public function installations(array $filters, int $limit, int $offset): array
    {
        $db = db_connect();
        $q  = static function () use ($db, $filters) {
            $b = $db->table('server_installations');
            foreach (['edition', 'mode', 'support_state', 'channel'] as $f) {
                if (! empty($filters[$f])) {
                    $b->where($f, strtoupper((string) $filters[$f]));
                }
            }
            if (! empty($filters['status'])) {
                $b->where('last_run_status', strtoupper((string) $filters['status']));
            }
            if (! empty($filters['version'])) {
                $b->where('version', (string) $filters['version']);
            }
            if (! empty($filters['q'])) {
                $b->groupStart()->like('name', (string) $filters['q'])->orLike('installation_id', (string) $filters['q'])->groupEnd();
            }
            // DL-4 segments.
            switch ((string) ($filters['segment'] ?? '')) {
                case 'stale':
                    $b->where('offline_mode', 0)->groupStart()->where('last_report_at IS NULL')->orWhere('last_report_at <', gmdate('Y-m-d H:i:s', time() - 86400))->groupEnd();
                    break;
                case 'on_hold':
                    $b->where('id IN (SELECT installation_id FROM ' . $db->prefixTable('server_update_plans') . " WHERE state = 'HELD' AND state_reason LIKE 'Rolled back%')", null, false);
                    break;
                case 'offline':
                    $b->groupStart()->where('offline_mode', 1)->orWhere('mode', 'AIR_GAPPED')->groupEnd();
                    break;
            }

            return $b;
        };
        $rows     = $q()->orderBy('last_report_at', 'DESC')->limit($limit, $offset)->get()->getResultArray();
        $policies = array_column($db->table('server_auto_update_policies')->select('installation_id, mode, ring, version')->get()->getResultArray(), null, 'installation_id');
        $minSvc   = new MinimumVersionService();
        $app      = $this->serverApp();
        $mins     = [];
        $data     = [];
        foreach ($rows as $r) {
            $pol  = $policies[$r['id']] ?? null;
            $ring = ServerRingService::ringOf($r, $pol['ring'] ?? null);
            $ch   = strtoupper((string) ($r['channel'] ?: 'STABLE'));
            if (! array_key_exists($ch, $mins)) {
                try {
                    $mins[$ch] = $minSvc->channelMinimum((int) $app['id'], (int) Catalog::channel($ch, (int) $app['id'])['id'], null)['minimum_version'] ?? null;
                } catch (\Throwable) {
                    $mins[$ch] = null;
                }
            }
            $below = false;
            try {
                $below = $mins[$ch] !== null && $r['version'] && Semver::lt($r['version'], $mins[$ch]);
            } catch (\Throwable) {
            }
            $data[] = [
                'installation_id' => $r['installation_id'], 'name' => $r['name'], 'edition' => $r['edition'], 'version' => $r['version'], 'build' => (int) $r['build'],
                'channel' => $r['channel'], 'mode' => $r['mode'], 'support_state' => $r['support_state'], 'last_report_at' => Catalog::iso($r['last_report_at']),
                'last_check_at' => Catalog::iso($r['last_check_at']), 'last_run_status' => $r['last_run_status'], 'php_version' => $r['php_version'], 'db_version' => $r['db_version'],
                'node_version' => $r['node_version'] ?? null, 'disk_free_mb' => isset($r['disk_free_mb']) ? (int) $r['disk_free_mb'] : null, 'health' => $r['health'] ?? null,
                'offline_mode' => (bool) ($r['offline_mode'] ?? false), 'policy_mode' => $pol['mode'] ?? $r['policy_mode'] ?? null, 'policy_version' => $pol ? (int) $pol['version'] : null,
                'ring' => $ring, 'ring_name' => ServerRingService::RINGS[$ring], 'ring_override' => $r['ring'] !== null, 'below_minimum' => $below, 'linked' => ! empty($r['key_hash']),
                'registered_at' => Catalog::iso($r['registered_at'] ?? null),
            ];
        }

        return ['data' => $data, 'total' => $q()->countAllResults()];
    }

    public function stats(): array
    {
        $db   = db_connect();
        $app  = $this->serverApp();
        $by   = [];
        foreach ($db->table('server_installations')->select('edition, version, COUNT(*) AS c')->groupBy('edition')->groupBy('version')->get()->getResultArray() as $r) {
            $by[$r['edition']][] = ['version' => $r['version'] ?? 'unknown', 'count' => (int) $r['c']];
        }
        $latest  = (new ManifestService())->currentRelease((int) $app['id'], (int) Catalog::channel('STABLE')['id']);
        $pending = 0;
        foreach ($db->table('server_installations')->select('version')->get()->getResultArray() as $r) {
            try {
                if ($latest && $r['version'] && Semver::lt($r['version'], $latest['version'])) {
                    $pending++;
                }
            } catch (\Throwable) {
            }
        }
        $runs = static fn (string $type) => array_map(static fn ($e) => ['installation_id' => $e['installation_id'], 'name' => $e['name'], 'to_version' => $e['to_version'], 'step' => $e['step'], 'error_code' => $e['error_code'], 'occurred_at' => Catalog::iso($e['occurred_at'])],
            $db->table('server_update_events e')->select('e.*, i.installation_id, i.name')->join('server_installations i', 'i.id = e.installation_id')->where('e.type', $type)->orderBy('e.occurred_at', 'DESC')->limit(20)->get()->getResultArray());

        return [
            'total'          => $db->table('server_installations')->countAllResults(),
            'latest_version' => $latest['version'] ?? null,
            'by_edition'     => $by ?: new \stdClass(),
            'pending_updates' => $pending,
            'failed_runs'    => $runs('failed'),
            'rolled_back'    => $runs('rolled_back'),
            'unsupported'    => $db->table('server_installations')->where('support_state', 'UNSUPPORTED')->countAllResults(),
            'by_mode'        => array_column($db->table('server_installations')->select('mode, COUNT(*) AS c')->groupBy('mode')->get()->getResultArray(), 'c', 'mode') ?: new \stdClass(),
            'segments'       => (new ServerReleaseConsoleService())->fleetCounts(),
            'adoption'       => (new ServerReleaseConsoleService())->adoption(),
        ];
    }
}

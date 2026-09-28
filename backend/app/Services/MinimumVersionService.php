<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\Semver;

/**
 * Minimum supported version per application × channel (× platform)
 * (§4.6, FR-UC-MIN-01..04, WF-C11).
 */
class MinimumVersionService
{
    /** Policy in force (enforce_from reached), platform-specific overrides generic. */
    public function channelMinimum(int $appId, int $channelId, ?string $platform, bool $onlyEnforced = true): ?array
    {
        $db = db_connect();
        $q  = $db->table('minimum_version_policies')->where('application_id', $appId)->where('channel_id', $channelId);
        $rows = $q->get()->getResultArray();
        $best = null;
        foreach ($rows as $r) {
            if ($onlyEnforced && $r['enforce_from'] !== null && strtotime($r['enforce_from'] . ' UTC') > time()) {
                continue;
            }
            if ($r['platform'] !== null && $r['platform'] !== $platform) {
                continue;
            }
            if ($best === null || ($r['platform'] !== null && $best['platform'] === null)) {
                $best = $r;
            }
        }

        return $best;
    }

    /** @return array<string,string> platform => minimum (enforced) */
    public function platformMinimums(int $appId, int $channelId): array
    {
        $out = [];
        foreach (db_connect()->table('minimum_version_policies')->where('application_id', $appId)->where('channel_id', $channelId)->where('platform IS NOT NULL')->get()->getResultArray() as $r) {
            if ($r['enforce_from'] === null || strtotime($r['enforce_from'] . ' UTC') <= time()) {
                $out[$r['platform']] = $r['minimum_version'];
            }
        }

        return $out;
    }

    public function list(int $appId): array
    {
        $db   = db_connect();
        $data = [];
        foreach ($db->table('minimum_version_policies')->where('application_id', $appId)->orderBy('channel_id')->get()->getResultArray() as $r) {
            $data[] = ['channel' => Catalog::channelById((int) $r['channel_id'])['code'], 'platform' => $r['platform'], 'minimum_version' => $r['minimum_version'], 'enforce_from' => Catalog::iso($r['enforce_from']), 'message' => $r['message'], 'updated_at' => Catalog::iso($r['updated_at'])];
        }
        $history = [];
        foreach ($db->table('minimum_version_policy_history')->where('application_id', $appId)->orderBy('id', 'DESC')->limit(50)->get()->getResultArray() as $h) {
            $history[] = ['channel' => Catalog::channelById((int) $h['channel_id'])['code'], 'platform' => $h['platform'], 'before_version' => $h['before_version'], 'minimum_version' => $h['minimum_version'], 'enforce_from' => Catalog::iso($h['enforce_from']), 'message' => $h['message'], 'reason' => $h['reason'], 'updated_by' => Catalog::userLabel($h['updated_by'] ? (int) $h['updated_by'] : null), 'created_at' => Catalog::iso($h['created_at'])];
        }

        return ['data' => $data, 'history' => $history];
    }

    /** Impact preview (FR-UC-MIN-04). */
    public function impact(int $appId, string $channelCode, ?string $platform, string $version): array
    {
        if (! Semver::isValid($version)) {
            throw ApiException::validation(['version' => 'Invalid SemVer']);
        }
        $db = db_connect();
        $q  = $db->table('device_versions')->select('version, COUNT(*) AS c')->where('application_id', $appId)
            ->where('last_seen >=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))->where('UPPER(channel)', strtoupper($channelCode));
        if ($platform) {
            $q->where('platform', $platform);
        }
        $rows = $q->groupBy('version')->get()->getResultArray();
        $total = $req = $unsupported = 0;
        $byVersion = [];
        $blocked   = array_column($db->table('releases')->select('version, support_state')->where('application_id', $appId)->whereIn('support_state', ['UNSUPPORTED', 'BLOCKED'])->get()->getResultArray(), 'support_state', 'version');
        foreach ($rows as $r) {
            $c = (int) $r['c'];
            $total += $c;
            try {
                $below = Semver::lt($r['version'], $version);
            } catch (\Throwable) {
                $below = false;
            }
            if (isset($blocked[$r['version']])) {
                $unsupported += $c;
            } elseif ($below) {
                $req += $c;
            }
            $byVersion[] = ['version' => $r['version'], 'count' => $c, 'affected' => $below];
        }
        usort($byVersion, static fn ($a, $b) => Semver::compare($b['version'], $a['version']));

        return ['active_devices' => $total, 'update_required' => $req, 'unsupported' => $unsupported, 'by_version' => $byVersion];
    }

    public function set(int $appId, array $in, ?int $userId): array
    {
        $channel = Catalog::channel((string) ($in['channel'] ?? 'STABLE'), $appId);
        $platform = ($in['platform'] ?? null) ?: null;
        $version = trim((string) ($in['minimum_version'] ?? ''));
        if (! Semver::isValid($version)) {
            throw ApiException::validation(['minimum_version' => 'Invalid SemVer']);
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        if ($reason === '') {
            throw ApiException::validation(['reason' => 'A reason is required']);
        }
        // Minimum above latest published → rejected (WF-C11).
        $latest = (new ManifestService())->currentRelease($appId, (int) $channel['id']);
        if ($latest === null || Semver::compare($version, $latest['version']) > 0) {
            throw new ApiException('MINIMUM_ABOVE_LATEST', 'Minimum version cannot be above the latest published release' . ($latest ? " ({$latest['version']})" : ''), 422);
        }
        $db   = db_connect();
        $prev = $db->table('minimum_version_policies')->where('application_id', $appId)->where('channel_id', $channel['id'])->where('platform', $platform)->get()->getRowArray();
        $row  = [
            'application_id' => $appId, 'channel_id' => $channel['id'], 'platform' => $platform, 'minimum_version' => $version,
            'enforce_from' => Catalog::toDb($in['enforce_from'] ?? null), 'message' => trim((string) ($in['message'] ?? '')) ?: null,
            'updated_by' => $userId, 'updated_at' => Catalog::now(),
        ];
        if ($prev) {
            $db->table('minimum_version_policies')->where('id', $prev['id'])->update($row);
        } else {
            $db->table('minimum_version_policies')->insert($row + ['created_at' => Catalog::now()]);
        }
        $db->table('minimum_version_policy_history')->insert([
            'application_id' => $appId, 'channel_id' => $channel['id'], 'platform' => $platform, 'before_version' => $prev['minimum_version'] ?? null,
            'minimum_version' => $version, 'enforce_from' => $row['enforce_from'], 'message' => $row['message'], 'reason' => $reason, 'updated_by' => $userId,
            'created_at' => Catalog::now(), 'updated_at' => Catalog::now(),
        ]);
        $app = Catalog::appById($appId);
        service('audit')->record('minimum_version.changed', 'application', $app['public_id'], $prev ? ['channel' => $channel['code'], 'platform' => $platform, 'minimum_version' => $prev['minimum_version'], 'enforce_from' => $prev['enforce_from']] : null, ['channel' => $channel['code'], 'platform' => $platform, 'minimum_version' => $version, 'enforce_from' => $row['enforce_from']], $reason);
        (new ManifestService())->signChannel($appId, (int) $channel['id'], 'Minimum version changed');
        (new TenantEventsService())->emit('minimum.changed', $appId, null, (int) $channel['id'], ['channel' => strtolower($channel['code']), 'platform' => $platform, 'minimum_version' => $version, 'enforce_from' => Catalog::iso($row['enforce_from'])]);
        UpdateResolverService::bumpCacheEpoch();

        return $this->list($appId);
    }

    /** WF-C14: withdrawn release was the minimum → lower to previous published. */
    public function lowerIfMinimum(array $withdrawn): ?string
    {
        $db   = db_connect();
        $rows = $db->table('minimum_version_policies')->where('application_id', $withdrawn['application_id'])->where('channel_id', $withdrawn['channel_id'])->where('minimum_version', $withdrawn['version'])->get()->getResultArray();
        if ($rows === []) {
            return null;
        }
        $prev = $db->table('releases')->where('application_id', $withdrawn['application_id'])->where('channel_id', $withdrawn['channel_id'])
            ->whereIn('status', ['PUBLISHED', 'SUPERSEDED', 'PAUSED'])->where('version_sort <', $withdrawn['version_sort'])->orderBy('version_sort', 'DESC')->limit(1)->get()->getRowArray();
        $to = $prev['version'] ?? null;
        foreach ($rows as $r) {
            if ($to === null) {
                $db->table('minimum_version_policies')->where('id', $r['id'])->delete();
            } else {
                $db->table('minimum_version_policies')->where('id', $r['id'])->update(['minimum_version' => $to, 'updated_at' => Catalog::now()]);
            }
            $db->table('minimum_version_policy_history')->insert(['application_id' => $r['application_id'], 'channel_id' => $r['channel_id'], 'platform' => $r['platform'], 'before_version' => $r['minimum_version'], 'minimum_version' => $to ?? '0.0.0', 'enforce_from' => $r['enforce_from'], 'message' => $r['message'], 'reason' => 'Automatically lowered: minimum release withdrawn', 'created_at' => Catalog::now(), 'updated_at' => Catalog::now()]);
        }
        service('audit')->record('minimum_version.auto_lowered', 'release', $withdrawn['public_id'], ['minimum_version' => $withdrawn['version']], ['minimum_version' => $to], 'Withdrawn release was the minimum supported version');

        return $to ?? 'none';
    }
}

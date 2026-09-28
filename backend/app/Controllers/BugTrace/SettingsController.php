<?php

namespace App\Controllers\BugTrace;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Libraries\BugTrace\Bt;
use App\Services\BugTrace\AlertService;
use App\Services\BugTrace\EventProcessor;
use App\Services\BugTrace\PolicyService;
use App\Services\BugTrace\PrivacyService;
use App\Services\BugTrace\ProjectService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Bug Trace configuration: projects, alert rules, tenant policies, scrub and
 * fingerprint rules, retention and privacy requests.
 */
class SettingsController extends BaseApiController
{
    // ------------------------------------------------------------ projects

    public function projects(): array
    {
        $rows = Bt::db()->table('bt_projects')->orderBy('id')->get()->getResultArray();

        return ['data' => array_map([ProjectService::class, 'format'], $rows)];
    }

    public function updateProject(string $id): array
    {
        $this->requirePermission('bugtrace.manage');
        $before = ProjectService::resolve($id);
        $out    = (new ProjectService())->patch($id, $this->body());
        service('audit')->record('bugtrace.project.updated', 'bt_project', $out['id'], $before ? ProjectService::format($before, false) : null, $out);

        return $out;
    }

    // -------------------------------------------------------------- alerts

    public function alerts(): array
    {
        $rows = Bt::db()->table('bt_alert_rules')->orderBy('id')->get()->getResultArray();
        $recent = Bt::db()->table('bt_alert_events')->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();

        return [
            'data'   => array_map([AlertService::class, 'format'], $rows),
            'recent' => array_map(static fn ($e) => ['kind' => $e['kind'], 'message' => $e['message'], 'created_at' => Bt::iso($e['created_at']), 'delivered_at' => Bt::iso($e['delivered_at'] ?? null)], $recent),
        ];
    }

    public function showAlert(string $id): array
    {
        return AlertService::format((new AlertService())->find($id));
    }

    public function createAlert(): ResponseInterface
    {
        $this->requirePermission('bugtrace.manage');
        $out = (new AlertService())->create($this->body(), $this->auth->userId());
        service('audit')->record('bugtrace.alert.created', 'bt_alert_rule', $out['id'], null, $out);

        return $this->ok($out, 201);
    }

    public function updateAlert(string $id): array
    {
        $this->requirePermission('bugtrace.manage');
        $svc  = new AlertService();
        $rule = $svc->find($id);
        $row  = $svc->validate($this->body(), true);
        if ($row !== []) {
            Bt::db()->table('bt_alert_rules')->where('id', $rule['id'])->update($row + ['updated_at' => Bt::now()]);
        }
        $out = AlertService::format($svc->find($id));
        service('audit')->record('bugtrace.alert.updated', 'bt_alert_rule', $id, AlertService::format($rule), $out);

        return $out;
    }

    public function deleteAlert(string $id): array
    {
        $this->requirePermission('bugtrace.manage');
        $rule = (new AlertService())->find($id);
        Bt::db()->table('bt_alert_rules')->where('id', $rule['id'])->delete();
        service('audit')->record('bugtrace.alert.deleted', 'bt_alert_rule', $id, AlertService::format($rule), null);

        return ['ok' => true];
    }

    // ------------------------------------------------------------ policies

    public function policies(): array
    {
        return (new PolicyService())->list();
    }

    public function putPolicy(string $tenantRef): array
    {
        $this->requirePermission('bugtrace.settings');
        $res = (new PolicyService())->put($tenantRef, $this->body(), $this->auth->actorId());
        service('audit')->record('bugtrace.policy.updated', 'tenant', $tenantRef, $res['before'], $res['after'], $this->reason($this->body(), false));

        return $res['after'];
    }

    // --------------------------------------------------------- scrub rules

    public function scrubRules(): array
    {
        $rows = Bt::db()->table('bt_scrub_rules')->orderBy('id')->get()->getResultArray();

        return ['data' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'pattern' => $r['pattern'], 'replacement' => $r['replacement'], 'scope' => $r['scope'], 'project' => $r['project_id'] ? Bt::projectPublicId(EventProcessor::project((int) $r['project_id'])) : null, 'enabled' => (bool) $r['enabled']], $rows)];
    }

    public function createScrubRule(): ResponseInterface
    {
        $b       = $this->body();
        $pattern = (string) ($b['pattern'] ?? '');
        $re      = preg_match('/^([\/#~!%@]).*\1[imsxuU]*$/s', $pattern) ? $pattern : '/' . str_replace('/', '\/', $pattern) . '/';
        if ($pattern === '' || strlen($pattern) > 500 || @preg_match($re, '') === false) {
            throw ApiException::validation(['pattern' => 'A valid regular expression is required']);
        }
        $project = ! empty($b['project']) ? ProjectService::resolve((string) $b['project']) : null;
        Bt::db()->table('bt_scrub_rules')->insert([
            'name' => mb_substr((string) ($b['name'] ?? 'Custom rule'), 0, 100), 'pattern' => $pattern,
            'replacement' => mb_substr((string) ($b['replacement'] ?? '[Filtered]'), 0, 64) ?: '[Filtered]',
            'scope' => $project ? 'project' : 'global', 'project_id' => $project['id'] ?? null, 'enabled' => (int) ($b['enabled'] ?? 1),
            'created_at' => Bt::now(), 'updated_at' => Bt::now(),
        ]);
        $id = (int) Bt::db()->insertID();
        service('audit')->record('bugtrace.scrub_rule.created', 'bt_scrub_rule', (string) $id, null, ['pattern' => $pattern]);

        return $this->ok(['id' => $id], 201);
    }

    public function deleteScrubRule(string $id): array
    {
        Bt::db()->table('bt_scrub_rules')->where('id', (int) $id)->delete();
        service('audit')->record('bugtrace.scrub_rule.deleted', 'bt_scrub_rule', $id);

        return ['ok' => true];
    }

    // --------------------------------------------------- fingerprint rules

    public function fingerprintRules(): array
    {
        $rows = Bt::db()->table('bt_fingerprint_rules')->orderBy('id')->get()->getResultArray();

        return ['data' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'project' => $r['project_id'] ? Bt::projectPublicId(EventProcessor::project((int) $r['project_id'])) : null, 'match_type' => $r['match_type'], 'match_value' => $r['match_value'], 'fingerprint' => $r['fingerprint'], 'enabled' => (bool) $r['enabled']], $rows)];
    }

    public function createFingerprintRule(): ResponseInterface
    {
        $b = $this->body();
        if (! in_array($b['match_type'] ?? null, ['exception_type', 'message', 'file', 'function', 'type'], true) || empty($b['match_value']) || empty($b['fingerprint'])) {
            throw ApiException::validation(['match_type' => 'exception_type, message, file, function or type; match_value and fingerprint required']);
        }
        $project = ! empty($b['project']) ? ProjectService::resolve((string) $b['project']) : null;
        Bt::db()->table('bt_fingerprint_rules')->insert([
            'project_id' => $project['id'] ?? null, 'match_type' => $b['match_type'], 'match_value' => mb_substr((string) $b['match_value'], 0, 255),
            'fingerprint' => mb_substr((string) $b['fingerprint'], 0, 128), 'enabled' => (int) ($b['enabled'] ?? 1), 'created_at' => Bt::now(), 'updated_at' => Bt::now(),
        ]);

        return $this->ok(['id' => (int) Bt::db()->insertID()], 201);
    }

    public function deleteFingerprintRule(string $id): array
    {
        Bt::db()->table('bt_fingerprint_rules')->where('id', (int) $id)->delete();

        return ['ok' => true];
    }

    // ------------------------------------------------ retention & privacy

    public function retention(): array
    {
        return Bt::setting(PrivacyService::SETTINGS_KEY, PrivacyService::DEFAULT_RETENTION);
    }

    public function saveRetention(): array
    {
        $b      = $this->body();
        $before = $this->retention();
        $after  = $before;
        foreach (['events_days' => [7, 3650], 'issues_days' => [30, 3650], 'aggregates_days' => [30, 3650], 'reports_days' => [30, 3650]] as $k => [$lo, $hi]) {
            if (isset($b[$k])) {
                if (! is_numeric($b[$k]) || $b[$k] < $lo || $b[$k] > $hi) {
                    throw ApiException::validation([$k => "Between {$lo} and {$hi} days"]);
                }
                $after[$k] = (int) $b[$k];
            }
        }
        if (isset($b['editions']) && is_array($b['editions'])) {
            $after['editions'] = array_map(static fn ($o) => array_map('intval', array_intersect_key((array) $o, array_flip(['events_days', 'reports_days']))), $b['editions']);
        }
        Bt::saveSetting(PrivacyService::SETTINGS_KEY, $after);
        service('audit')->record('bugtrace.retention.updated', 'settings', PrivacyService::SETTINGS_KEY, $before, $after);

        return $after;
    }

    /** POST /privacy/delete {user_ref?|installation_ref?, reason} — bugtrace.settings + step-up MFA, audited. */
    public function privacyDelete(): array
    {
        $b = $this->body();
        $this->requireStepUp();
        $reason = $this->reason($b);
        $userRef = isset($b['user_ref']) ? (string) $b['user_ref'] : null;
        $instRef = isset($b['installation_ref']) ? (string) $b['installation_ref'] : null;
        $result  = (new PrivacyService())->delete($userRef, $instRef);
        $subject = $userRef ?: $instRef;
        service('audit')->record('bugtrace.privacy.deleted', $userRef ? 'bt_user_ref' : 'bt_installation_ref', substr(hash('sha256', (string) $subject), 0, 16), null, $result + ['subject_kind' => $userRef ? 'user_ref' : 'installation_ref'], $reason);

        return ['ok' => true, 'deleted' => $result, 'completed_at' => gmdate('Y-m-d\TH:i:s\Z')];
    }

    /** POST /privacy/export {user_ref?|installation_ref?, reason} — bugtrace.settings + step-up MFA, audited. */
    public function privacyExport(): array
    {
        $b = $this->body();
        $this->requireStepUp();
        $reason = $this->reason($b);
        $out    = (new PrivacyService())->export($b['user_ref'] ?? null, $b['installation_ref'] ?? null);
        service('audit')->record('bugtrace.privacy.exported', 'bt_subject', substr(hash('sha256', (string) ($b['user_ref'] ?? $b['installation_ref'])), 0, 16), null, ['events' => count($out['events']), 'reports' => count($out['reports'])], $reason);

        return $out;
    }
}

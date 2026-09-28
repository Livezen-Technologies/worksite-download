<?php

namespace App\Controllers\BugTrace;

use App\Controllers\BaseApiController;
use App\Services\BugTrace\ReleaseHealthService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Release Health (/api/v1/admin/bugtrace/health) — B5, FR-BT-HLT-01/02/04.
 * Readable by bugtrace.view or release.view (CI pipelines query it before a
 * stage advance with ?release_id=).
 */
class HealthController extends BaseApiController
{
    public function index(): array|ResponseInterface
    {
        $q   = $this->request->getGet() ?? [];
        $svc = new ReleaseHealthService();
        if (($q['format'] ?? '') === 'csv') {
            $this->requirePermission('bugtrace.export');

            return $this->response->setStatusCode(200)->setContentType('text/csv; charset=utf-8')
                ->setHeader('Content-Disposition', 'attachment; filename="bugtrace-release-health-' . gmdate('Ymd') . '.csv"')
                ->setHeader('Cache-Control', 'no-store')->setBody($svc->csv($q));
        }

        return $svc->health($q);
    }

    /** GET /health/gate-rules */
    public function rules(): array
    {
        return ReleaseHealthService::rules();
    }

    /** PUT /health/gate-rules (bugtrace.manage) */
    public function saveRules(): array
    {
        $this->requirePermission('bugtrace.manage');
        $before = ReleaseHealthService::rules();
        $after  = ReleaseHealthService::saveRules($this->body());
        service('audit')->record('bugtrace.gate_rules.updated', 'settings', ReleaseHealthService::SETTINGS_KEY, $before, $after);

        return $after;
    }
}

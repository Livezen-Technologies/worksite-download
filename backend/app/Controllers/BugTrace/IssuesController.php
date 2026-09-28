<?php

namespace App\Controllers\BugTrace;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Services\BugTrace\IssueService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin Bug Trace issues (/api/v1/admin/bugtrace/issues) — B1/B2,
 * FR-BT-ISS-01..07. Read: bugtrace.view; changes: bugtrace.triage;
 * CSV export (?format=csv): bugtrace.export.
 */
class IssuesController extends BaseApiController
{
    private function svc(): IssueService
    {
        return new IssueService();
    }

    public function index(): array|ResponseInterface
    {
        $q = $this->request->getGet() ?? [];
        if (($q['format'] ?? '') === 'csv') {
            $this->requirePermission('bugtrace.export');
            service('audit')->record('bugtrace.export', 'bt_issue', null, null, ['filters' => array_diff_key($q, ['format' => 1])]);

            return $this->response->setStatusCode(200)->setContentType('text/csv; charset=utf-8')
                ->setHeader('Content-Disposition', 'attachment; filename="bugtrace-issues-' . gmdate('Ymd') . '.csv"')
                ->setHeader('Cache-Control', 'no-store')->setBody($this->svc()->csv($q, $this->auth));
        }
        [$limit, $offset] = $this->paging(50);

        return $this->svc()->list($q, $this->auth, $limit, $offset);
    }

    public function show(string $id): array
    {
        return $this->svc()->detail($id, $this->auth);
    }

    public function events(string $id): array
    {
        [$limit, $offset] = $this->paging(50);

        return $this->svc()->events($id, $this->auth, $limit, $offset);
    }

    public function update(string $id): array
    {
        $this->requirePermission('bugtrace.triage');

        return $this->svc()->patch($id, $this->body(), $this->auth);
    }

    public function merge(string $id): array
    {
        $this->requirePermission('bugtrace.triage');
        $into = (string) ($this->body()['into'] ?? '');
        if ($into === '') {
            throw ApiException::validation(['into' => 'Target issue id is required']);
        }

        return $this->svc()->merge($id, $into, $this->auth);
    }

    public function split(string $id): array
    {
        $this->requirePermission('bugtrace.triage');

        return $this->svc()->split($id, $this->auth);
    }

    public function comment(string $id): ResponseInterface
    {
        $this->requirePermission('bugtrace.triage');

        return $this->ok($this->svc()->comment($id, $this->body(), $this->auth), 201);
    }

    public function link(string $id): ResponseInterface
    {
        $this->requirePermission('bugtrace.triage');

        return $this->ok($this->svc()->link($id, $this->body(), $this->auth), 201);
    }

    public function bulk(): array
    {
        $this->requirePermission('bugtrace.triage');
        $b = $this->body();

        return $this->svc()->bulk(array_values((array) ($b['ids'] ?? [])), (array) ($b['patch'] ?? []), $this->auth);
    }
}

<?php

namespace App\Controllers\BugTrace;

use App\Controllers\BaseApiController;
use App\Services\BugTrace\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin user-report inbox (/api/v1/admin/bugtrace/reports) — B3,
 * FR-BT-ISS-05, FR-BT-REP-06. All actions need bugtrace.triage; the reporter's
 * e-mail is only included for bugtrace.reporter.contact.
 */
class ReportsController extends BaseApiController
{
    public function index(): array
    {
        [$limit, $offset] = $this->paging(50);

        return (new ReportService())->list($this->request->getGet() ?? [], $limit, $offset);
    }

    public function show(string $ref): array
    {
        $out = (new ReportService())->detail($ref, $this->auth);
        if ($out['contact'] !== null) {
            service('audit')->record('bugtrace.reporter.contact_viewed', 'bt_report', $out['reference']);
        }

        return $out;
    }

    public function update(string $ref): array
    {
        return (new ReportService())->patch($ref, $this->body(), $this->auth);
    }

    public function reply(string $ref): array
    {
        return (new ReportService())->reply($ref, $this->body(), $this->auth);
    }

    public function createIssue(string $ref): ResponseInterface
    {
        return $this->ok((new ReportService())->createIssue($ref, $this->auth), 201);
    }
}

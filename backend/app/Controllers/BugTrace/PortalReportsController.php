<?php

namespace App\Controllers\BugTrace;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Services\BugTrace\ReportService;

/**
 * "My bug reports" in the Update Center portal (FR-BT-REP-07):
 * GET /api/v1/portal/bug-reports.
 */
class PortalReportsController extends BaseApiController
{
    public function index(): array
    {
        if ($this->auth->user === null) {
            throw new ApiException('UNAUTHENTICATED', 'Sign in required', 401);
        }

        return (new ReportService())->forPortalUser((int) $this->auth->userId());
    }
}

<?php

namespace App\Jobs;

use App\Services\BugTrace\PrivacyService;

/**
 * Applies Bug Trace retention (FR-BT-PRV-04). Normally run by `spark bugtrace:retention`.
 */
class BugTraceRetentionJob implements JobInterface
{
    public function handle(array $payload): void
    {
        (new PrivacyService())->applyRetention();
    }
}

<?php

namespace App\Jobs;

use App\Services\BugTrace\EventProcessor;

/**
 * Processes one scrubbed Bug Trace event (queue "bugtrace"): symbolication,
 * fingerprinting, issue upsert, counters, regression checks and alerts.
 */
class BugTraceProcessEventJob implements JobInterface
{
    public function handle(array $payload): void
    {
        (new EventProcessor())->process($payload);
    }
}

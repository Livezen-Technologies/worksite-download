<?php

namespace App\Jobs;

use App\Services\BugTrace\SessionService;

/**
 * Aggregates session updates (start / crashed / abnormal) into
 * bt_sessions_daily and bt_session_users for crash-free rates (FR-BT-SDK-09).
 */
class BugTraceProcessSessionsJob implements JobInterface
{
    public function handle(array $payload): void
    {
        (new SessionService())->record((int) $payload['project_id'], (array) ($payload['sessions'] ?? []), (int) ($payload['received_at'] ?? time()));
    }
}

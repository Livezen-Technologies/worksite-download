<?php

namespace App\Jobs;

use App\Services\BugTrace\SymbolService;

/**
 * Re-symbolicates a release's recent events after CI uploaded its symbols.
 */
class BugTraceResymbolicateJob implements JobInterface
{
    public function handle(array $payload): void
    {
        (new SymbolService())->resymbolicate(isset($payload['release_id']) ? (int) $payload['release_id'] : null, $payload['release'] ?? null);
    }
}

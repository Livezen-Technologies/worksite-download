<?php

namespace App\Commands;

use App\Services\BugTrace\PrivacyService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Applies Bug Trace retention (FR-BT-PRV-04): raw events 90 d, issues and
 * aggregates 13 months, closed reports + attachments 12 months, unlinked
 * uploads 24 h (configurable per edition). Schedule: daily
 * (cron `17 3 * * *  php spark bugtrace:retention`).
 */
class BugTraceRetention extends BaseCommand
{
    protected $group       = 'BugTrace';
    protected $name        = 'bugtrace:retention';
    protected $description = 'Delete Bug Trace data past its retention period.';

    public function run(array $params)
    {
        $res = (new PrivacyService())->applyRetention();
        CLI::write('bugtrace:retention — ' . json_encode($res));
    }
}

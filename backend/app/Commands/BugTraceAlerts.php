<?php

namespace App\Commands;

use App\Services\BugTrace\AlertService;
use App\Services\BugTrace\ReleaseHealthService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Periodic alert evaluation (FR-BT-HLT-02/03): spike and threshold rules,
 * release gates (alert Release Manager on WARN/BLOCK), delivery of alerts held
 * during quiet hours. With --digest: sends digest-rule summaries.
 * Schedule: every 5 minutes (`*\/5 * * * *  php spark bugtrace:alerts`) and daily
 * (`0 3 * * *  php spark bugtrace:alerts --digest`, 08:30 Asia/Colombo).
 */
class BugTraceAlerts extends BaseCommand
{
    protected $group       = 'BugTrace';
    protected $name        = 'bugtrace:alerts';
    protected $description = 'Evaluate Bug Trace spike/threshold rules and release gates; deliver deferred alerts.';
    protected $options     = ['--digest' => 'Send digest summaries for digest rules'];

    public function run(array $params)
    {
        $alerts = new AlertService();
        if (array_key_exists('digest', $params) || CLI::getOption('digest') !== null) {
            CLI::write('bugtrace:alerts — digest items sent: ' . $alerts->sendDigest());

            return;
        }
        $fired = $alerts->evaluateIssueRules();
        $gates = (new ReleaseHealthService())->evaluateGates();
        $sent  = $alerts->deliverPending();
        CLI::write(sprintf('bugtrace:alerts — %d issue alerts, %d gates evaluated (%d not OK), %d deferred delivered', $fired, count($gates), count(array_filter($gates, static fn ($g) => $g['state'] !== 'OK')), $sent));
    }
}

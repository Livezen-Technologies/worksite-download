<?php

declare(strict_types=1);

namespace Modules\Core\Libraries\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark bugtrace:flush` — sends queued Bug Trace events to the Update
 * Center in batches. Cron: `* * * * * php /var/www/worksuite/spark bugtrace:flush`.
 * Expects a `bugtrace` service returning a configured BugTraceReporter.
 */
class BugTraceFlush extends BaseCommand
{
    protected $group       = 'WorkSuite';
    protected $name        = 'bugtrace:flush';
    protected $description = 'Send queued Bug Trace events to the Update Center.';

    public function run(array $params)
    {
        $res = service('bugtrace')->flush();
        CLI::write(sprintf('bugtrace:flush — sent %d, requeued %d, dropped %d', $res['sent'], $res['requeued'], $res['dropped']));
    }
}

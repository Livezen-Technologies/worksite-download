<?php

namespace App\Commands;

use App\Services\ExportService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * §13.3: export configuration, changelogs, manifests and new audit log rows to
 * write-once storage. Runs daily from uc:scheduler; safe to run by hand.
 */
class Export extends BaseCommand
{
    protected $group       = 'Update Center';
    protected $name        = 'uc:export';
    protected $description = 'Export configuration, changelogs, manifests and audit logs to write-once storage.';
    protected $usage       = 'uc:export [--date=YYYY-MM-DD]';

    public function run(array $params)
    {
        $date = CLI::getOption('date');
        if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            CLI::error('--date must be YYYY-MM-DD');

            return EXIT_ERROR;
        }
        $r = (new ExportService())->run($date ?: null);
        CLI::table(array_map(static fn ($o) => [$o['key'], (string) $o['rows'], $o['status'], substr($o['sha256'], 0, 16)], $r['objects']), ['Object', 'Rows', 'Status', 'SHA-256']);

        return EXIT_SUCCESS;
    }
}

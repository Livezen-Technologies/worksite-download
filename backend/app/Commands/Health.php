<?php

namespace App\Commands;

use App\Services\HealthService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class Health extends BaseCommand
{
    protected $group       = 'Update Center';
    protected $name        = 'uc:health';
    protected $description = 'Run component health checks (exit 1 when anything is DOWN).';

    public function run(array $params)
    {
        $c = (new HealthService())->run();
        CLI::table(array_map(static fn ($x) => [$x['component'], $x['status'], (string) $x['latency_ms'], $x['detail']], $c), ['Component', 'Status', 'ms', 'Detail']);

        return in_array('DOWN', array_column($c, 'status'), true) ? EXIT_ERROR : EXIT_SUCCESS;
    }
}

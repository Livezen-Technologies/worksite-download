<?php

namespace App\Commands;

use App\Libraries\Queue;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * update-worker container entrypoint: consumes queued jobs (checksum, scan,
 * metadata, signatures, manifests, CDN purge, notifications, webhooks …).
 */
class Worker extends BaseCommand
{
    protected $group       = 'Update Center';
    protected $name        = 'uc:worker';
    protected $description = 'Run queue workers (processing, default, notifications, webhooks, bugtrace).';
    protected $usage       = 'uc:worker [--queues=processing,default] [--once] [--max-jobs=N] [--sleep=2]';

    public function run(array $params)
    {
        $queues  = explode(',', (string) (CLI::getOption('queues') ?? 'processing,default,notifications,webhooks,bugtrace'));
        $once    = CLI::getOption('once') !== null;
        $max     = (int) (CLI::getOption('max-jobs') ?? 0);
        $sleep   = max(1, (int) (CLI::getOption('sleep') ?? 2));
        $id      = gethostname() . ':' . getmypid();
        $done    = 0;
        $running = true;
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$running) { $running = false; });
            pcntl_signal(SIGINT, static function () use (&$running) { $running = false; });
        }
        CLI::write("Worker {$id} on queues: " . implode(', ', $queues), 'green');
        while ($running) {
            $worked = false;
            foreach ($queues as $q) {
                while ($running && Queue::work(trim($q), $id)) {
                    $worked = true;
                    $done++;
                    if ($max > 0 && $done >= $max) {
                        return;
                    }
                }
            }
            if ($once) {
                break;
            }
            if (! $worked) {
                sleep($sleep);
            }
            db_connect()->reconnect();
        }
        CLI::write("Worker stopped after {$done} jobs");
    }
}

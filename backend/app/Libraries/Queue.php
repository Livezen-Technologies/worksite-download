<?php

namespace App\Libraries;

use App\Jobs\JobInterface;

/**
 * Database-backed job queue consumed by `spark uc:worker` (update-worker
 * container). With updatecenter.queueSync=true jobs run inline (dev/tests).
 */
class Queue
{
    /** @param class-string<JobInterface> $job */
    public static function push(string $job, array $payload = [], string $queue = 'default', int $delaySeconds = 0, int $maxAttempts = 5): ?int
    {
        if (config('UpdateCenter')->queueSync && $delaySeconds === 0) {
            try {
                self::run($job, $payload);
            } catch (\Throwable $e) {
                // Inline mode mirrors the worker: a failing job never breaks the caller.
                log_message('error', 'Inline job {job} failed: {msg}', ['job' => $job, 'msg' => $e->getMessage()]);
            }

            return null;
        }
        $db = db_connect();
        $db->table('jobs')->insert([
            'queue'        => $queue,
            'job'          => $job,
            'payload'      => json_encode($payload),
            'attempts'     => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => gmdate('Y-m-d H:i:s', time() + $delaySeconds),
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    public static function run(string $job, array $payload): void
    {
        if (! is_subclass_of($job, JobInterface::class)) {
            throw new \RuntimeException("Unknown job {$job}");
        }
        (new $job())->handle($payload);
    }

    /** Reserve and run one job. Returns false when the queue is empty. */
    public static function work(string $queue = 'default', string $workerId = 'worker'): bool
    {
        $db  = db_connect();
        $now = gmdate('Y-m-d H:i:s');
        // Release jobs stuck in reservation for > 15 min (§13.4 "job stuck").
        $db->table('jobs')->where('done_at', null)->where('failed_at', null)->where('reserved_at <', gmdate('Y-m-d H:i:s', time() - 900))->update(['reserved_at' => null]);

        $row = $db->table('jobs')->where('queue', $queue)->where('done_at', null)->where('failed_at', null)
            ->where('reserved_at', null)->where('available_at <=', $now)->orderBy('id')->limit(1)->get()->getRowArray();
        if (! $row) {
            return false;
        }
        $db->table('jobs')->where('id', $row['id'])->where('reserved_at', null)->update(['reserved_at' => $now, 'reserved_by' => $workerId, 'attempts' => $row['attempts'] + 1]);
        if ($db->affectedRows() !== 1) {
            return true; // another worker won the race
        }

        try {
            self::run($row['job'], json_decode((string) $row['payload'], true) ?? []);
            $db->table('jobs')->where('id', $row['id'])->update(['done_at' => gmdate('Y-m-d H:i:s'), 'reserved_at' => null]);
        } catch (\Throwable $e) {
            $attempts = $row['attempts'] + 1;
            $update   = ['reserved_at' => null, 'last_error' => substr($e->getMessage(), 0, 2000)];
            if ($attempts >= $row['max_attempts']) {
                $update['failed_at'] = gmdate('Y-m-d H:i:s');
            } else {
                $update['available_at'] = gmdate('Y-m-d H:i:s', time() + min(3600, 2 ** $attempts * 15));
            }
            $db->table('jobs')->where('id', $row['id'])->update($update);
            log_message('error', 'Job {job} failed: {msg}', ['job' => $row['job'], 'msg' => $e->getMessage()]);
        }

        return true;
    }

    /** @return array{backlog:int, oldest_age_s:int, failed_24h:int} */
    public static function stats(): array
    {
        $db      = db_connect();
        $backlog = $db->table('jobs')->where('done_at', null)->where('failed_at', null)->countAllResults();
        $oldest  = $db->table('jobs')->selectMin('available_at')->where('done_at', null)->where('failed_at', null)->get()->getRow('available_at');
        $failed  = $db->table('jobs')->where('failed_at >=', gmdate('Y-m-d H:i:s', time() - 86400))->countAllResults();

        return ['backlog' => $backlog, 'oldest_age_s' => $oldest ? max(0, time() - strtotime($oldest . ' UTC')) : 0, 'failed_24h' => $failed];
    }
}

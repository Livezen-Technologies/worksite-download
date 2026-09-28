<?php

namespace App\Jobs;

interface JobInterface
{
    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void;
}

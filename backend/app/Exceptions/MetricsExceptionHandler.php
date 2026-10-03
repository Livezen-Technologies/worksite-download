<?php

namespace App\Exceptions;

use App\Filters\MetricsFilter;
use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/** Counts unhandled exceptions (5xx) in the request metrics before the default rendering. */
class MetricsExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        MetricsFilter::record($request, $statusCode);
        parent::handle($exception, $request, $response, $statusCode, $exitCode);
    }
}

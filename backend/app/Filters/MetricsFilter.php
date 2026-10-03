<?php

namespace App\Filters;

use App\Libraries\Metrics;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Records request count, status class and latency per route group for the
 * Prometheus exporter (§13.4). Unhandled exceptions are recorded by
 * MetricsExceptionHandler instead, because after-filters do not run for them.
 */
class MetricsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        self::record($request, $response->getStatusCode());

        return null;
    }

    public static function record(RequestInterface $request, int $status): void
    {
        if (! $request instanceof \CodeIgniter\HTTP\IncomingRequest) {
            return;
        }
        $start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        Metrics::record(Metrics::group($request->getUri()->getPath()), $status, max(0.0, microtime(true) - $start));
    }
}

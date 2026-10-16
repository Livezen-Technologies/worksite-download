<?php

namespace App\Exceptions;

use App\Filters\MetricsFilter;
use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Exceptions;
use Throwable;

/**
 * Counts unhandled exceptions (5xx) in the request metrics, then renders them
 * the default way.
 *
 * It wraps CodeIgniter's handler instead of extending it: `ExceptionHandler` is
 * `final` in CodeIgniter 4.7, and extending it was a fatal error the moment any
 * error page was drawn — every 404 on the site became a 500. The tests never
 * render an error page, which is why it went unnoticed.
 */
class MetricsExceptionHandler implements ExceptionHandlerInterface
{
    private ExceptionHandler $inner;

    public function __construct(Exceptions $config)
    {
        $this->inner = new ExceptionHandler($config);
    }

    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        MetricsFilter::record($request, $statusCode);
        $this->inner->handle($exception, $request, $response, $statusCode, $exitCode);
    }
}

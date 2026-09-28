<?php

declare(strict_types=1);

namespace Modules\Core\Libraries;

use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * CodeIgniter 4 exception handler that reports to Bug Trace, then delegates
 * to the normal handler. Register in app/Config/Exceptions.php:
 *
 *   public function handler(int $statusCode, Throwable $exception): ExceptionHandlerInterface
 *   {
 *       return new BugTraceExceptionHandler(new \CodeIgniter\Debug\ExceptionHandler($this), service('bugtrace'));
 *   }
 *
 * Only the route pattern, HTTP method and module are sent — never the URL
 * query, headers, cookies or body.
 */
final class BugTraceExceptionHandler implements ExceptionHandlerInterface
{
    public function __construct(private readonly ExceptionHandlerInterface $inner, private readonly BugTraceReporter $reporter)
    {
    }

    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        if ($statusCode >= 500) {
            $router = function_exists('service') ? service('router') : null;
            $route  = $router !== null && method_exists($router, 'getMatchedRoute') ? ($router->getMatchedRoute()[0] ?? null) : null;
            $this->reporter->capture($exception, [
                'route'  => $route,
                'method' => strtoupper($request->getMethod()),
                'area'   => $route !== null ? explode('/', ltrim((string) $route, '/'))[0] : null,
                'user_id' => function_exists('auth') && auth()->loggedIn() ? auth()->id() : null,
            ]);
        }
        $this->inner->handle($exception, $request, $response, $statusCode, $exitCode);
    }
}

<?php

namespace App\Filters;

use App\Exceptions\ApiException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Per-IP / per-principal limits from §10.7. Per-device limits (device_ref) are
 * applied inside the controllers once the body is parsed.
 */
class RateLimitFilter implements FilterInterface
{
    /** name => [limit, windowSeconds, scope] */
    private const LIMITS = [
        'update'   => [600, 60, 'ip'],
        'manifest' => [120, 60, 'ip'],
        'resolver' => [60, 60, 'ip'],
        'events'   => [600, 60, 'ip'],
        'login'    => [20, 60, 'ip'],
        'admin'    => [600, 60, 'principal'],
        'ingest'   => [3000, 60, 'ip'],
        'public'   => [300, 60, 'ip'],
        'report'   => [10, 3600, 'ip'],
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
        $name = ((array) $arguments)[0] ?? 'public';
        [$limit, $window, $scope] = self::LIMITS[$name] ?? self::LIMITS['public'];
        $ctx = service('authContext');
        if ($scope === 'principal' && $ctx->apiClient !== null) {
            $limit = 1200; // CI 1200/min per client
            $key   = 'client:' . $ctx->apiClient['id'];
        } elseif ($scope === 'principal' && $ctx->user !== null) {
            $key = 'user:' . $ctx->user['id'];
        } else {
            $key = 'ip:' . $request->getIPAddress();
        }

        try {
            service('rateLimiter')->hit($name . ':' . $key, $limit, $window);
        } catch (ApiException $e) {
            return AuthFilter::deny($e->errorCode, $e->getMessage(), 429, $e->headers());
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

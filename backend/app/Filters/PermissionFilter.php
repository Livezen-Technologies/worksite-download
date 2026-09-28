<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route-level RBAC (FR-UC-SEC-03): `perm:release.view` — any listed permission passes.
 */
class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $ctx = service('authContext');
        foreach ((array) $arguments as $perm) {
            if ($ctx->can($perm)) {
                return null;
            }
        }

        return AuthFilter::deny('FORBIDDEN', 'Missing permission ' . implode(' or ', (array) $arguments), 403);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

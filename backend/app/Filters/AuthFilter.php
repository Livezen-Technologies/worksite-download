<?php

namespace App\Filters;

use App\Services\AuthService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Resolves the caller (session cookie or API client bearer) into AuthContext.
 * Arguments: "optional" (anonymous allowed), "staff" (staff user or API client),
 * "user" (any signed-in user), "client" (API client only), "mfa-pending".
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $args = (array) $arguments;
        $ctx  = service('authContext');
        $ctx->reset();
        $svc = new AuthService();

        $authz = $request->getHeaderLine('Authorization');
        if (str_starts_with($authz, 'Bearer ')) {
            $client = $svc->authenticateClient(substr($authz, 7), $request->getIPAddress());
            if ($client === null) {
                return self::deny('UNAUTHENTICATED', 'Invalid API client credentials', 401);
            }
            $ctx->apiClient   = $client;
            $ctx->permissions = json_decode((string) $client['permissions'], true) ?: [];
        } else {
            $token = $request->getCookie(AuthService::COOKIE);
            if (is_string($token) && $token !== '') {
                $svc->loadSession($token, $ctx, in_array('mfa-pending', $args, true));
            }
            // CSRF double-submit for cookie-authenticated state-changing requests (§12.1).
            if ($ctx->session !== null && ! in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true)) {
                $hdr = $request->getHeaderLine('X-CSRF-Token');
                if ($hdr === '' || ! hash_equals((string) $ctx->session['csrf_token'], $hdr)) {
                    return self::deny('CSRF_FAILED', 'Missing or invalid CSRF token', 403);
                }
            }
        }

        if (in_array('optional', $args, true)) {
            return null;
        }
        if (in_array('client', $args, true) && $ctx->apiClient === null) {
            return self::deny('UNAUTHENTICATED', 'API client credentials required', 401);
        }
        if (! $ctx->isAuthenticated()) {
            return self::deny('UNAUTHENTICATED', 'Sign in required', 401);
        }
        if (in_array('staff', $args, true) && $ctx->user !== null && ! $ctx->isStaff()) {
            return self::deny('FORBIDDEN', 'Staff account required', 403);
        }
        if (in_array('staff', $args, true) && $ctx->user !== null && config('UpdateCenter')->requireStaffMfa && ! config('UpdateCenter')->mfaDisabled && empty($ctx->user['mfa_enabled'])) {
            return self::deny('MFA_ENROLLMENT_REQUIRED', 'Enable two-factor authentication to continue', 403);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    public static function deny(string $code, string $message, int $status, array $headers = []): ResponseInterface
    {
        $res = service('response')->setStatusCode($status)->setContentType('application/json')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody(json_encode(['error' => ['code' => $code, 'message' => $message, 'details' => new \stdClass()]]));
        foreach ($headers as $k => $v) {
            $res->setHeader($k, $v);
        }

        return $res;
    }
}

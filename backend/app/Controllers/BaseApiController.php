<?php

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Libraries\Totp;
use App\Services\AuthContext;
use App\Services\AuthService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base for every JSON API controller. All errors are rendered as
 * { "error": { "code", "message", "details" } } (§10).
 */
abstract class BaseApiController extends Controller
{
    protected AuthContext $auth;

    public function _remap(string $method, ...$params)
    {
        $this->auth = service('authContext');
        if (! method_exists($this, $method) || str_starts_with($method, '_')) {
            return $this->error(new ApiException('NOT_FOUND', 'Endpoint not found', 404));
        }

        try {
            $result = $this->{$method}(...$params);
        } catch (ApiException $e) {
            return $this->error($e);
        } catch (\Throwable $e) {
            log_message('error', '[api] {class}: {msg} @ {file}:{line}', ['class' => $e::class, 'msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            $msg = ENVIRONMENT === 'production' ? 'Internal error' : $e->getMessage();

            return $this->error(new ApiException('INTERNAL_ERROR', $msg, 500));
        }
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        return $this->ok($result);
    }

    protected function ok(mixed $data, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setContentType('application/json')
            ->setBody(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    protected function error(ApiException $e): ResponseInterface
    {
        foreach ($e->headers() as $k => $v) {
            $this->response->setHeader($k, $v);
        }

        return $this->ok(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details === [] ? new \stdClass() : $e->details]], $e->status);
    }

    /** @return array<string, mixed> */
    protected function body(): array
    {
        $ct = $this->request->getHeaderLine('Content-Type');
        if (str_contains($ct, 'application/json') || $ct === '') {
            $raw = (string) $this->request->getBody();
            if ($raw === '') {
                return [];
            }
            if ($this->request->getHeaderLine('Content-Encoding') === 'gzip') {
                $raw = @gzdecode($raw) ?: '';
            }
            $data = json_decode($raw, true);
            if (! is_array($data)) {
                throw new ApiException('INVALID_JSON', 'Request body is not valid JSON', 400);
            }

            return $data;
        }

        return $this->request->getPost() ?? [];
    }

    protected function requirePermission(string $permission): void
    {
        $this->auth->require($permission);
    }

    /**
     * Step-up MFA for critical actions (§7.6, FR-UC-SEC-04). Accepts a fresh
     * TOTP/recovery code in X-MFA-Token or a verification within 10 minutes.
     */
    protected function requireStepUp(): void
    {
        $cfg = config('UpdateCenter');
        if ($this->auth->apiClient !== null && $this->auth->user === null) {
            if ($cfg->isProduction()) {
                throw new ApiException('MFA_REQUIRED', 'This action requires an interactive user with MFA', 401);
            }

            return;
        }
        if ($cfg->mfaDisabled && $this->auth->user !== null) {
            return; // two-factor switched off for interactive users (UpdateCenter::$mfaDisabled)
        }
        $token = trim($this->request->getHeaderLine('X-MFA-Token'));
        $user  = $this->auth->user;
        if ($token !== '' && $user !== null) {
            if ((new AuthService())->verifySecondFactor($user, $token)) {
                (new AuthService())->markMfa($this->auth);

                return;
            }

            throw new ApiException('MFA_INVALID', 'MFA code is not valid', 401);
        }
        if ($this->auth->hasRecentMfa()) {
            return;
        }
        if (! $cfg->isProduction() && $user !== null && empty($user['mfa_enabled'])) {
            return; // dev convenience: users without MFA configured outside production
        }

        throw new ApiException('MFA_REQUIRED', 'Step-up MFA needed', 401);
    }

    protected function reason(array $body, bool $required = true): ?string
    {
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($required && $reason === '') {
            throw ApiException::validation(['reason' => 'A reason is required for this action']);
        }

        return $reason === '' ? null : $reason;
    }

    protected function paging(int $defaultLimit = 50): array
    {
        $limit  = max(1, min(500, (int) ($this->request->getGet('limit') ?? $defaultLimit)));
        $offset = max(0, (int) ($this->request->getGet('offset') ?? 0));

        return [$limit, $offset];
    }
}

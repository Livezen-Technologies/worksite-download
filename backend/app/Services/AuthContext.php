<?php

namespace App\Services;

use App\Exceptions\ApiException;

/**
 * Per-request identity: a staff/portal user (cookie session) or an API client
 * (bearer token). Populated by App\Filters\AuthFilter.
 */
class AuthContext
{
    /** @var array<string, mixed>|null */
    public ?array $user = null;

    /** @var array<string, mixed>|null */
    public ?array $session = null;

    /** @var array<string, mixed>|null */
    public ?array $apiClient = null;

    /** @var list<string> */
    public array $permissions = [];

    /** @var list<string> */
    public array $roles = [];

    public function isAuthenticated(): bool
    {
        return $this->user !== null || $this->apiClient !== null;
    }

    public function isStaff(): bool
    {
        return $this->user !== null && ($this->user['kind'] ?? '') === 'STAFF';
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function require(string $permission): void
    {
        if (! $this->isAuthenticated()) {
            throw new ApiException('UNAUTHENTICATED', 'Sign in required', 401);
        }
        if (! $this->can($permission)) {
            throw new ApiException('FORBIDDEN', "Missing permission {$permission}", 403, ['permission' => $permission]);
        }
    }

    public function userId(): ?int
    {
        return $this->user !== null ? (int) $this->user['id'] : null;
    }

    public function clientId(): ?int
    {
        return $this->apiClient !== null ? (int) $this->apiClient['id'] : null;
    }

    public function actorType(): string
    {
        return $this->user !== null ? 'USER' : ($this->apiClient !== null ? 'API_CLIENT' : 'SYSTEM');
    }

    public function actorId(): ?string
    {
        return $this->user['public_id'] ?? $this->apiClient['public_id'] ?? null;
    }

    public function actorLabel(): ?string
    {
        return $this->user['email'] ?? ($this->apiClient !== null ? 'api:' . $this->apiClient['name'] : null);
    }

    /** Step-up MFA done within the configured window (FR-UC-AUTH-03). */
    public function hasRecentMfa(): bool
    {
        if ($this->session === null || empty($this->session['mfa_verified_at'])) {
            return false;
        }

        return strtotime($this->session['mfa_verified_at'] . ' UTC') >= time() - config('UpdateCenter')->stepUpSeconds;
    }

    public function reset(): void
    {
        $this->user = $this->session = $this->apiClient = null;
        $this->permissions = $this->roles = [];
    }
}

<?php

namespace App\Libraries;

/**
 * Resolves secret references (FR-UC-SEC-06). In production references point at
 * the secret service (Vault KV via env injection by the orchestrator); values are
 * never written to the database, logs or UI. Supported reference forms:
 *   NAME            → environment variable NAME
 *   env:NAME        → environment variable NAME
 *   file:/run/x     → contents of a mounted secret file (Docker/K8s secrets)
 *   enc:<cipher>    → value encrypted with the app encryption key (dev/test only)
 */
final class SecretStore
{
    /** @var array<string, string> */
    private static array $overrides = [];

    public static function get(?string $ref, ?string $default = null): ?string
    {
        if ($ref === null || $ref === '') {
            return $default;
        }
        if (array_key_exists($ref, self::$overrides)) {
            return self::$overrides[$ref];
        }
        if (str_starts_with($ref, 'file:')) {
            $path = substr($ref, 5);

            return is_readable($path) ? trim((string) file_get_contents($path)) : $default;
        }
        if (str_starts_with($ref, 'enc:')) {
            try {
                return service('encrypter')->decrypt(base64_decode(substr($ref, 4), true) ?: '');
            } catch (\Throwable) {
                return $default;
            }
        }
        $name = str_starts_with($ref, 'env:') ? substr($ref, 4) : $ref;
        $val  = getenv($name);
        if ($val === false) {
            $val = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }

        return is_string($val) && $val !== '' ? $val : $default;
    }

    /** Store a secret for dev environments, returning an `enc:` reference. */
    public static function seal(string $value): string
    {
        return 'enc:' . base64_encode(service('encrypter')->encrypt($value));
    }

    /** Test helper. */
    public static function override(string $ref, ?string $value): void
    {
        if ($value === null) {
            unset(self::$overrides[$ref]);
        } else {
            self::$overrides[$ref] = $value;
        }
    }

    /** App-wide HMAC secret (derived deterministically in dev when unset). */
    public static function appSecret(): string
    {
        $cfg = config('UpdateCenter');

        return self::get($cfg->appSecretRef) ?? hash('sha256', 'dev-app-secret|' . (config('Encryption')->key ?: 'uc'));
    }
}

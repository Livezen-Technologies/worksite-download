<?php

namespace App\Libraries\Signing;

use App\Libraries\SecretStore;
use RuntimeException;

/**
 * HashiCorp Vault Transit (ed25519 keys). The private key never leaves Vault (Q2).
 */
final class VaultTransitKeyProvider implements KeyProviderInterface
{
    /** @var array<string, string> */
    private array $pubCache = [];

    public function __construct(
        private readonly string $addr,
        private readonly string $tokenRef,
        private readonly string $mount = 'transit',
    ) {
    }

    public function sign(string $keyId, string $message): string
    {
        $res = $this->call('POST', "/v1/{$this->mount}/sign/" . rawurlencode($keyId), ['input' => base64_encode($message)]);
        $sig = $res['data']['signature'] ?? '';
        if (! preg_match('/^vault:v\d+:(.+)$/', $sig, $m)) {
            throw new RuntimeException('Vault returned no signature');
        }

        return base64_decode($m[1], true) ?: throw new RuntimeException('Bad Vault signature encoding');
    }

    public function publicKey(string $keyId): string
    {
        if (isset($this->pubCache[$keyId])) {
            return $this->pubCache[$keyId];
        }
        $res    = $this->call('GET', "/v1/{$this->mount}/keys/" . rawurlencode($keyId));
        $keys   = $res['data']['keys'] ?? [];
        $latest = $res['data']['latest_version'] ?? max(array_keys($keys) ?: [1]);
        $pub    = $keys[$latest]['public_key'] ?? null;
        if (! $pub) {
            throw new RuntimeException('Vault key has no public key');
        }

        return $this->pubCache[$keyId] = base64_decode($pub, true);
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $token = SecretStore::get($this->tokenRef) ?? throw new RuntimeException('Vault token not configured');
        $ch    = curl_init(rtrim($this->addr, '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => ['X-Vault-Token: ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body === null ? null : json_encode($body),
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 300) {
            throw new RuntimeException("Vault request failed ({$code})");
        }

        return json_decode((string) $raw, true) ?? [];
    }
}

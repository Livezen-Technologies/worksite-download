<?php

namespace App\Libraries\Signing;

/**
 * Abstraction over where private key material lives (KMS / Vault / HSM / dev file).
 * Implementations never expose the private key.
 */
interface KeyProviderInterface
{
    /** Ed25519 detached signature (raw 64 bytes) over $message. */
    public function sign(string $keyId, string $message): string;

    /** Raw 32-byte Ed25519 public key. */
    public function publicKey(string $keyId): string;
}

<?php

namespace App\Libraries\Signing;

/**
 * In-process signing for development and tests. Applies exactly the same
 * SigningPolicy as the signing-service container. Never used in production.
 */
final class LocalSigningClient implements SigningClientInterface
{
    public function __construct(
        private readonly KeyProviderInterface $keys,
        private readonly string $releaseKeyId,
        private readonly SigningPolicy $policy,
    ) {
    }

    public function sign(string $kind, array $document, array $evidence): array
    {
        $reasons = $this->policy->check($kind, $document, $evidence);
        if ($reasons !== []) {
            throw new SigningRefusedException($reasons);
        }

        return $kind === 'OS_MANIFEST'
            ? Ed25519::signFlat($document, $this->keys, $this->releaseKeyId)
            : Ed25519::signDocument($document, $this->keys, $this->releaseKeyId);
    }

    public function releasePublicKeys(): array
    {
        return [$this->releaseKeyId => $this->keys->publicKey($this->releaseKeyId)];
    }

    public function health(): array
    {
        try {
            $this->keys->publicKey($this->releaseKeyId);

            return ['ok' => true, 'detail' => 'local signer (dev) · ' . $this->releaseKeyId];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }
}

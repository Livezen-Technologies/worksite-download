<?php

namespace App\Libraries\Signing;

interface SigningClientInterface
{
    /**
     * Ask the Signing Service to sign a manifest with the release key.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $evidence
     *
     * @return array<string, mixed> the signed document (key_id + signature added)
     *
     * @throws SigningRefusedException
     */
    public function sign(string $kind, array $document, array $evidence): array;

    /** @return array<string, string> kid => raw public key of release keys currently accepted */
    public function releasePublicKeys(): array;

    /** @return array{ok: bool, detail: string} */
    public function health(): array;
}

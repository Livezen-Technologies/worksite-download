<?php

namespace App\Libraries\Signing;

use App\Libraries\Base64Url;
use App\Libraries\CanonicalJson;

/**
 * Helpers for signing/verifying JSON documents with detached Ed25519 signatures.
 * A signed document is the canonical JSON of the object *without* its
 * `signature` field; `key_id` is part of the signed bytes.
 */
final class Ed25519
{
    public static function verify(string $message, string $signature, string $publicKey): bool
    {
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed> the document with key_id and signature
     */
    public static function signDocument(array $doc, KeyProviderInterface $keys, string $keyId): array
    {
        unset($doc['signature']);
        $doc['key_id']    = $keyId;
        $doc['signature'] = Base64Url::encode($keys->sign($keyId, CanonicalJson::encode($doc)));

        return $doc;
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, string> $publicKeys kid => raw public key
     */
    public static function verifyDocument(array $doc, array $publicKeys): bool
    {
        $kid = $doc['key_id'] ?? null;
        $sig = $doc['signature'] ?? null;
        if (! is_string($kid) || ! is_string($sig) || ! isset($publicKeys[$kid])) {
            return false;
        }
        unset($doc['signature']);

        return self::verify(CanonicalJson::encode($doc), Base64Url::decode($sig), $publicKeys[$kid]);
    }

    /**
     * WorkSuite OS manifest scheme (services/package-service/src/updates.ts verifyManifest):
     * `signature: "<key_id>:<standard base64 Ed25519>"` over the canonical JSON of the
     * document without `signature`; no separate key_id field.
     *
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed>
     */
    public static function signFlat(array $doc, KeyProviderInterface $keys, string $keyId): array
    {
        unset($doc['signature'], $doc['key_id']);
        $doc['signature'] = $keyId . ':' . base64_encode($keys->sign($keyId, CanonicalJson::encode($doc)));

        return $doc;
    }

    /**
     * @param array<string, mixed>  $doc
     * @param array<string, string> $publicKeys kid => raw public key
     */
    public static function verifyFlat(array $doc, array $publicKeys): bool
    {
        $sig = $doc['signature'] ?? null;
        if (! is_string($sig) || ! str_contains($sig, ':')) {
            return false;
        }
        [$kid, $b64] = explode(':', $sig, 2);
        if (! isset($publicKeys[$kid])) {
            return false;
        }
        unset($doc['signature']);

        return self::verify(CanonicalJson::encode($doc), (string) base64_decode($b64, true), $publicKeys[$kid]);
    }
}

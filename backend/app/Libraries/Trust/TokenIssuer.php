<?php

namespace App\Libraries\Trust;

use App\Libraries\Base64Url;
use App\Libraries\CanonicalJson;

/**
 * Issues trust documents. In production only the license root and tenant
 * servers issue these; the Update Center uses this class for tests, the
 * `uc:dev-tokens` command and the local demo environment.
 */
final class TokenIssuer
{
    /**
     * @param array<string, mixed> $doc
     */
    public static function sign(array $doc, string $secretKey): array
    {
        unset($doc['signature']);
        $doc['signature'] = Base64Url::encode(sodium_crypto_sign_detached(CanonicalJson::encode($doc), $secretKey));

        return $doc;
    }

    /** @return array{0:string,1:string} [secretKey, publicKey] */
    public static function keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();

        return [sodium_crypto_sign_secretkey($kp), sodium_crypto_sign_publickey($kp)];
    }

    public static function cert(string $purpose, string $kid, string $publicKey, string $issuerKid, string $issuerSecret, array $claims = [], int $days = 365): array
    {
        return self::sign([
            'typ'        => 'ws-cert',
            'purpose'    => $purpose,
            'kid'        => $kid,
            'public_key' => Base64Url::encode($publicKey),
            'issuer_kid' => $issuerKid,
            'not_before' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
            'not_after'  => gmdate('Y-m-d\TH:i:s\Z', time() + 86400 * $days),
        ] + $claims, $issuerSecret);
    }

    /** @param list<array<string,mixed>> $chain */
    public static function tpt(array $payload, string $kid, string $policySecret, array $chain): array
    {
        $payload += [
            'issued_at'  => gmdate('Y-m-d\TH:i:s\Z'),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 7 * 86400),
        ];

        return self::sign(['typ' => 'ws-tpt+jws', 'alg' => 'EdDSA', 'kid' => $kid, 'chain' => $chain, 'payload' => $payload], $policySecret);
    }

    public static function licenseToken(array $payload, string $installationKid, string $installationSecret, array $installationCert): array
    {
        $payload += [
            'issued_at'  => gmdate('Y-m-d\TH:i:s\Z'),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * 86400),
        ];

        return self::sign(['typ' => 'ws-license+jws', 'alg' => 'EdDSA', 'kid' => $installationKid, 'chain' => [$installationCert], 'payload' => $payload], $installationSecret);
    }

    public static function encode(array $doc): string
    {
        return Base64Url::encode(json_encode($doc, JSON_UNESCAPED_SLASHES));
    }
}

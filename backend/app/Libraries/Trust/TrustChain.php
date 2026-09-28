<?php

namespace App\Libraries\Trust;

use App\Libraries\Base64Url;
use App\Libraries\CanonicalJson;
use App\Libraries\Signing\Ed25519;

/**
 * Verifies WorkSuite trust documents issued under the license root (§2.2, §2.4):
 *
 *   root (pinned in Update Center + desktop)
 *     └─ installation certificate  (purpose INSTALLATION, signed by root)
 *          ├─ tenant policy key cert (purpose TENANT_POLICY, signed by installation key)
 *          │     └─ Tenant Policy Token (typ ws-tpt+jws)
 *          └─ license tokens        (typ ws-license+jws, signed by installation key)
 *
 * Every document is JSON signed with Ed25519 over its canonical form minus
 * `signature`. Transport encoding is base64url(JSON) or raw JSON.
 */
final class TrustChain
{
    /** @param array<string, string> $roots kid => raw public key */
    public function __construct(private readonly array $roots, private readonly ?int $now = null)
    {
    }

    /** @return array<string, mixed> */
    public static function decode(string|array $token): array
    {
        if (is_array($token)) {
            return $token;
        }
        $token = trim($token);
        $json  = str_starts_with($token, '{') ? $token : Base64Url::decode($token);
        $doc   = json_decode($json, true);
        if (! is_array($doc)) {
            throw new TokenException('Token is not valid JSON');
        }

        return $doc;
    }

    /**
     * Verify a Tenant Policy Token and return its payload.
     *
     * @return array<string, mixed>
     */
    public function verifyTpt(string|array $token): array
    {
        $doc = self::decode($token);
        if (($doc['typ'] ?? '') !== 'ws-tpt+jws' || ($doc['alg'] ?? '') !== 'EdDSA') {
            throw new TokenException('Not a tenant policy token');
        }
        $chain = $doc['chain'] ?? [];
        if (! is_array($chain) || count($chain) !== 2) {
            throw new TokenException('TPT chain must contain the tenant policy cert and the installation cert');
        }
        $installation = $this->verifyInstallationCert(self::decode($chain[1]));
        $policyCert   = self::decode($chain[0]);
        $this->verifyCert($policyCert, 'TENANT_POLICY', [$installation['kid'] => Base64Url::decode($installation['public_key'])]);
        if (($policyCert['installation_id'] ?? null) !== ($installation['installation_id'] ?? null)) {
            throw new TokenException('Tenant policy cert does not belong to the installation');
        }
        if (($doc['kid'] ?? '') !== ($policyCert['kid'] ?? '')) {
            throw new TokenException('TPT kid does not match the tenant policy cert');
        }
        $this->verifySignature($doc, Base64Url::decode($policyCert['public_key']));

        $payload = $doc['payload'] ?? null;
        if (! is_array($payload)) {
            throw new TokenException('TPT has no payload');
        }
        $this->checkWindow($payload['issued_at'] ?? null, $payload['expires_at'] ?? null);
        if (isset($policyCert['tenant_id'], $payload['tenant_id']) && $policyCert['tenant_id'] !== $payload['tenant_id']) {
            throw new TokenException('TPT tenant does not match its certificate');
        }
        $payload['_installation_id'] = $installation['installation_id'] ?? null;
        $payload['_edition']         = $installation['edition'] ?? null;

        return $payload;
    }

    /**
     * Verify a license token (ENH-02) — used for private apps and server packages.
     *
     * @return array<string, mixed> payload incl. installation_id and edition
     */
    public function verifyLicenseToken(string|array $token): array
    {
        $doc = self::decode($token);
        if (($doc['typ'] ?? '') !== 'ws-license+jws') {
            throw new TokenException('Not a license token');
        }
        $chain = $doc['chain'] ?? [];
        if (! is_array($chain) || count($chain) < 1) {
            throw new TokenException('License token chain missing');
        }
        $installation = $this->verifyInstallationCert(self::decode($chain[count($chain) - 1]));
        if (($doc['kid'] ?? '') !== $installation['kid']) {
            throw new TokenException('License token must be signed by the installation key');
        }
        $this->verifySignature($doc, Base64Url::decode($installation['public_key']));
        $payload = $doc['payload'] ?? [];
        $this->checkWindow($payload['issued_at'] ?? null, $payload['expires_at'] ?? null);
        $payload['installation_id'] = $installation['installation_id'];
        $payload['edition']         = strtoupper((string) ($installation['edition'] ?? $payload['edition'] ?? 'FREE'));

        return $payload;
    }

    /** @return array<string, mixed> */
    public function verifyInstallationCert(array $cert): array
    {
        $this->verifyCert($cert, 'INSTALLATION', $this->roots);

        return $cert;
    }

    /** @param array<string, string> $issuers */
    private function verifyCert(array $cert, string $purpose, array $issuers): void
    {
        if (($cert['typ'] ?? '') !== 'ws-cert' || ($cert['purpose'] ?? '') !== $purpose) {
            throw new TokenException("Expected {$purpose} certificate");
        }
        $issuer = (string) ($cert['issuer_kid'] ?? '');
        if (! isset($issuers[$issuer])) {
            throw new TokenException("{$purpose} certificate issuer is not trusted");
        }
        $this->verifySignature($cert, $issuers[$issuer]);
        $this->checkWindow($cert['not_before'] ?? null, $cert['not_after'] ?? null);
        if (strlen(Base64Url::decode((string) ($cert['public_key'] ?? ''))) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new TokenException("{$purpose} certificate public key invalid");
        }
    }

    private function verifySignature(array $doc, string $publicKey): void
    {
        $sig = Base64Url::decode((string) ($doc['signature'] ?? ''));
        unset($doc['signature']);
        if (! Ed25519::verify(CanonicalJson::encode($doc), $sig, $publicKey)) {
            throw new TokenException('Signature invalid');
        }
    }

    private function checkWindow(?string $from, ?string $to): void
    {
        $now = $this->now ?? time();
        if ($from !== null && strtotime($from) !== false && strtotime($from) > $now + 300) {
            throw new TokenException('Token not yet valid');
        }
        if ($to === null || strtotime($to) === false || strtotime($to) < $now) {
            throw new TokenException('Token expired');
        }
    }
}

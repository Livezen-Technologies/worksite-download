<?php

namespace App\Services;

use App\Libraries\Base64Url;

/**
 * Public keys the Update Center trusts or publishes: pinned license roots,
 * CI attestation keys, release keys (ACTIVE + OVERLAP during rotation) and the
 * update-response key. Private material is never stored (§11 signing_keys).
 */
final class KeyRegistry
{
    /** @return array<string, string> kid => raw public key */
    public static function licenseRoots(): array
    {
        return self::merge(config('UpdateCenter')->licenseRootKeys, 'TENANT_ROOT');
    }

    /** @return array<string, string> */
    public static function ciAttestationKeys(): array
    {
        return self::merge(config('UpdateCenter')->ciAttestationKeys, 'CI_ATTESTATION');
    }

    /** @return array<string, string> release keys clients may accept (ACTIVE + OVERLAP) */
    public static function releaseKeys(): array
    {
        $out = [];
        try {
            $out = service('signingClient')->releasePublicKeys();
        } catch (\Throwable) {
        }

        return $out + self::fromDb('RELEASE', ['ACTIVE', 'OVERLAP']);
    }

    /** @return array<string, string> */
    private static function merge(string $json, string $purpose): array
    {
        $out = [];
        foreach ((array) json_decode($json, true) as $kid => $b64) {
            $raw = Base64Url::decode((string) $b64);
            if (strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $out[(string) $kid] = $raw;
            }
        }

        return $out + self::fromDb($purpose, ['ACTIVE', 'OVERLAP']);
    }

    /** @return array<string, string> */
    private static function fromDb(string $purpose, array $statuses): array
    {
        $out = [];
        try {
            $rows = db_connect()->table('signing_keys')->where('purpose', $purpose)->whereIn('status', $statuses)->get()->getResultArray();
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $r) {
            $raw = Base64Url::decode((string) $r['public_key']);
            if (strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $out[$r['key_id']] = $raw;
            }
        }

        return $out;
    }

    /** Ensure dev keys are registered in signing_keys so /keys lists them. */
    public static function registerDevKeys(): void
    {
        $cfg = config('UpdateCenter');
        if ($cfg->isProduction()) {
            return;
        }
        $db  = db_connect();
        $now = gmdate('Y-m-d H:i:s');
        foreach ([[$cfg->releaseKeyId, 'RELEASE', service('signingClient')->releasePublicKeys()[$cfg->releaseKeyId] ?? null], [$cfg->responseKeyId, 'RESPONSE', service('responseKeys')->publicKey($cfg->responseKeyId)]] as [$kid, $purpose, $pub]) {
            if ($pub !== null && $db->table('signing_keys')->where('key_id', $kid)->countAllResults() === 0) {
                $db->table('signing_keys')->insert(['key_id' => $kid, 'purpose' => $purpose, 'kms_ref' => 'file:' . $kid, 'public_key' => Base64Url::encode($pub), 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}

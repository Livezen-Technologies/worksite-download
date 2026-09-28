<?php

namespace App\Libraries\Storage;

use App\Libraries\SecretStore;
use RuntimeException;

/**
 * Builds a provider from a storage_providers row. Credentials are resolved from
 * the secret service by reference and never leave this process (FR-UC-STO-06).
 */
final class StorageFactory
{
    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): ReleaseStorageProviderInterface
    {
        $cfg    = config('UpdateCenter');
        $signer = new CdnUrlSigner(SecretStore::get($cfg->cdnSecretRef) ?? hash('sha256', 'dev-cdn|' . SecretStore::appSecret()));
        $kind   = strtoupper((string) $row['kind']);

        if ($kind === 'LOCAL') {
            if ($cfg->isProduction()) {
                throw new RuntimeException('LOCAL storage provider is not allowed in production');
            }

            return new LocalReleaseProvider(WRITEPATH . 'storage/' . preg_replace('/[^A-Za-z0-9._-]/', '', (string) $row['bucket']), $row['cdn_host'] ?: $cfg->cdnHost, $signer);
        }

        $creds = json_decode((string) SecretStore::get($row['credentials_ref'] ?? null, '{}'), true) ?: [];
        if (empty($creds['access_key']) || empty($creds['secret_key'])) {
            throw new RuntimeException('Storage credentials not configured in the secret service');
        }
        $region   = $row['region'] ?: ($kind === 'R2' ? 'auto' : 'us-east-1');
        $endpoint = $row['endpoint'] ?: match ($kind) {
            'S3'     => "https://s3.{$region}.amazonaws.com",
            'SPACES' => "https://{$region}.digitaloceanspaces.com",
            default  => throw new RuntimeException('Endpoint required for ' . $kind),
        };
        $useHmacCdn = $cfg->cdnSigning === 'hmac' && ! empty($row['cdn_host']);

        return new S3CompatibleReleaseProvider($endpoint, (string) $row['bucket'], $region, $creds['access_key'], $creds['secret_key'], (string) ($row['cdn_host'] ?? ''), $useHmacCdn ? $signer : null);
    }
}

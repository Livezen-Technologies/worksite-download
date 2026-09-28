<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Update Center runtime configuration. Every value can be overridden from the
 * environment (e.g. `updatecenter.environmentName = STAGING`). Secrets are
 * never stored here — only references resolved by App\Libraries\SecretStore.
 */
class UpdateCenter extends BaseConfig
{
    /** PRODUCTION | STAGING | DEV — shown as the admin environment badge (NFR-C14). */
    public string $environmentName = 'DEV';

    public string $publicBaseUrl = 'http://localhost:8080';
    public string $cdnHost = 'http://localhost:8080/_dev-cdn';

    /** Signed download URL lifetime in seconds (§5.3: 15 min). */
    public int $downloadUrlTtl = 900;

    /** Update response lifetime (≤ 15 min). */
    public int $updateResponseTtl = 900;

    /** Update API Redis cache TTL in seconds (FR-UC-API-06). */
    public int $updateCacheTtl = 60;

    /** Session timeouts (FR-UC-AUTH-03). */
    public int $sessionIdleSeconds = 1800;
    public int $sessionAbsoluteSeconds = 43200;
    public int $stepUpSeconds = 600;

    /** MFA required for staff (always true in production). */
    public bool $requireStaffMfa = false;

    /** Require CI attestation for signing (always true in production). */
    public bool $requireAttestation = false;

    /** Require QA + Release Manager approval for Stable (always true in production). */
    public bool $requireApprovals = true;

    /** Enforce separation of duties on production Stable. */
    public bool $enforceSod = true;

    /** Signing driver: local (dev only, in-process key) | remote (signing-service over mTLS). */
    public string $signingDriver = 'local';
    public string $signingServiceUrl = 'https://signing-service:8443';
    public string $signingClientCert = '';
    public string $signingClientKey = '';
    public string $signingCaCert = '';

    /** Key provider for the update-response key: file | vault | env. */
    public string $responseKeyProvider = 'file';
    public string $responseKeyId = 'ws-uc-resp-dev';
    public string $releaseKeyId = 'ws-release-dev';

    /** Directory for dev key files (never used in production). */
    public string $devKeyDir = WRITEPATH . 'keys';

    /** Vault transit settings (response key / release key in signing service). */
    public string $vaultAddr = '';
    public string $vaultTokenRef = 'VAULT_TOKEN';
    public string $vaultTransitMount = 'transit';

    /** Pinned license root public keys (base64url Ed25519) keyed by kid. JSON in env. */
    public string $licenseRootKeys = '{}';

    /** CI attestation public keys (base64url Ed25519) keyed by kid. JSON in env. */
    public string $ciAttestationKeys = '{}';

    /** Upload limits. */
    public int $uploadChunkBytes = 8 * 1024 * 1024;
    public int $uploadMaxBytes = 4 * 1024 * 1024 * 1024;
    public int $serverZipMaxBytes = 2 * 1024 * 1024 * 1024;
    public string $uploadTempDir = WRITEPATH . 'uploads';

    /** Malware scanner: none | clamav. */
    public string $scanner = 'none';
    public string $clamavSocket = 'unix:///var/run/clamav/clamd.ctl';

    /** Signature verification: skip (dev) | tools (osslsigncode, rcodesign, gpg). */
    public string $signatureVerifier = 'skip';
    public string $gpgKeyring = '';
    public string $expectedPublisher = 'Livezen Technologies (Pvt) Ltd';

    /** Rate limits: set false to disable (tests). */
    public bool $rateLimitEnabled = true;

    /** Run queued jobs inline (dev/tests) instead of by uc:worker. */
    public bool $queueSync = false;

    /** Mail sender for Update Center mails. */
    public string $mailFrom = 'updates@worksuitecloud.com';

    /** HMAC secret reference for webhook signing fallback and misc tokens. */
    public string $appSecretRef = 'UC_APP_SECRET';

    /** CDN signing: presign (S3 presigned URL through CDN host) | hmac (edge worker validates exp+sig). */
    public string $cdnSigning = 'hmac';
    public string $cdnSecretRef = 'UC_CDN_SECRET';

    /** CDN purge: none | cloudflare. */
    public string $cdnPurge = 'none';
    public string $cloudflareZoneId = '';
    public string $cloudflareTokenRef = 'CLOUDFLARE_API_TOKEN';

    /** Bug Trace ingest limits. */
    public int $btEnvelopeMaxBytes = 1048576;
    public int $btMinidumpMaxBytes = 20971520;

    public function isProduction(): bool
    {
        return strtoupper($this->environmentName) === 'PRODUCTION';
    }
}

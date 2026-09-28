<?php

namespace Tests\Support;

use App\Libraries\Base64Url;
use App\Libraries\Trust\TokenIssuer;

/**
 * A license root → installation → tenant policy key hierarchy for tests.
 */
final class TrustFixture
{
    public string $rootSecret;
    public string $rootPublic;
    public string $instSecret;
    public string $instPublic;
    public string $policySecret;
    public string $policyPublic;
    public array $installationCert;
    public array $policyCert;

    public function __construct(public string $installationId = 'inst_01J6TEST', public string $tenantId = 'tnt_01J6TEST', public string $edition = 'BUSINESS')
    {
        [$this->rootSecret, $this->rootPublic]     = TokenIssuer::keypair();
        [$this->instSecret, $this->instPublic]     = TokenIssuer::keypair();
        [$this->policySecret, $this->policyPublic] = TokenIssuer::keypair();
        $this->installationCert = TokenIssuer::cert('INSTALLATION', 'inst-key-1', $this->instPublic, 'ws-license-root-test', $this->rootSecret, ['installation_id' => $installationId, 'tenant_id' => $tenantId, 'edition' => $edition]);
        $this->policyCert       = TokenIssuer::cert('TENANT_POLICY', 'tenant-policy-1', $this->policyPublic, 'inst-key-1', $this->instSecret, ['installation_id' => $installationId, 'tenant_id' => $tenantId]);
    }

    public function pinRoot(): void
    {
        config('UpdateCenter')->licenseRootKeys = json_encode(['ws-license-root-test' => Base64Url::encode($this->rootPublic)]);
        \Config\Services::reset(true);
    }

    public function tpt(array $payload): string
    {
        return TokenIssuer::encode(TokenIssuer::tpt($payload + ['tenant_id' => $this->tenantId, 'app_id' => 'lk.worksuite.desktop'], 'tenant-policy-1', $this->policySecret, [$this->policyCert, $this->installationCert]));
    }

    public function license(array $payload = []): string
    {
        return TokenIssuer::encode(TokenIssuer::licenseToken($payload, 'inst-key-1', $this->instSecret, $this->installationCert));
    }
}

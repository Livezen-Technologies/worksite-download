<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    public static function authContext(bool $getShared = true): \App\Services\AuthContext
    {
        if ($getShared) {
            return static::getSharedInstance('authContext');
        }

        return new \App\Services\AuthContext();
    }

    public static function audit(bool $getShared = true): \App\Services\AuditService
    {
        if ($getShared) {
            return static::getSharedInstance('audit');
        }

        return new \App\Services\AuditService(static::authContext());
    }

    public static function rateLimiter(bool $getShared = true): \App\Libraries\RateLimiter
    {
        if ($getShared) {
            return static::getSharedInstance('rateLimiter');
        }

        return new \App\Libraries\RateLimiter();
    }

    public static function notifier(bool $getShared = true): \App\Services\NotificationService
    {
        if ($getShared) {
            return static::getSharedInstance('notifier');
        }

        return new \App\Services\NotificationService();
    }

    /** Key provider for keys the API tier may hold (update-response key). */
    public static function responseKeys(bool $getShared = true): \App\Libraries\Signing\KeyProviderInterface
    {
        if ($getShared) {
            return static::getSharedInstance('responseKeys');
        }
        $cfg = config('UpdateCenter');

        return match ($cfg->responseKeyProvider) {
            'vault' => new \App\Libraries\Signing\VaultTransitKeyProvider($cfg->vaultAddr, $cfg->vaultTokenRef, $cfg->vaultTransitMount),
            default => new \App\Libraries\Signing\FileKeyProvider($cfg->devKeyDir, $cfg->isProduction()),
        };
    }

    public static function signingClient(bool $getShared = true): \App\Libraries\Signing\SigningClientInterface
    {
        if ($getShared) {
            return static::getSharedInstance('signingClient');
        }
        $cfg = config('UpdateCenter');
        if ($cfg->signingDriver === 'remote' || $cfg->isProduction()) {
            return new \App\Libraries\Signing\RemoteSigningClient($cfg->signingServiceUrl, $cfg->signingClientCert, $cfg->signingClientKey, $cfg->signingCaCert);
        }
        $keys = new \App\Libraries\Signing\FileKeyProvider($cfg->devKeyDir, false);

        return new \App\Libraries\Signing\LocalSigningClient(
            $keys,
            $cfg->releaseKeyId,
            new \App\Libraries\Signing\SigningPolicy(
                \App\Services\KeyRegistry::ciAttestationKeys(),
                [$cfg->releaseKeyId => $keys->publicKey($cfg->releaseKeyId)],
                $cfg->requireAttestation || $cfg->isProduction(),
                $cfg->requireApprovals || $cfg->isProduction(),
            ),
        );
    }

    public static function trustChain(bool $getShared = true): \App\Libraries\Trust\TrustChain
    {
        if ($getShared) {
            return static::getSharedInstance('trustChain');
        }

        return new \App\Libraries\Trust\TrustChain(\App\Services\KeyRegistry::licenseRoots());
    }

    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */
}

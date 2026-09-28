<?php

namespace App\Commands;

use App\Libraries\Base64Url;
use App\Services\KeyRegistry;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Show / register public keys (never private material).
 */
class Keys extends BaseCommand
{
    protected $group       = 'Update Center';
    protected $name        = 'uc:keys';
    protected $description = 'List trusted and published public keys; --register-dev records dev keys.';
    protected $usage       = 'uc:keys [--register-dev]';

    public function run(array $params)
    {
        if (CLI::getOption('register-dev') !== null) {
            KeyRegistry::registerDevKeys();
        }
        $cfg  = config('UpdateCenter');
        $rows = [];
        foreach (KeyRegistry::releaseKeys() as $kid => $pub) {
            $rows[] = ['RELEASE', $kid, Base64Url::encode($pub)];
        }
        try {
            $rows[] = ['RESPONSE', $cfg->responseKeyId, Base64Url::encode(service('responseKeys')->publicKey($cfg->responseKeyId))];
        } catch (\Throwable $e) {
            $rows[] = ['RESPONSE', $cfg->responseKeyId, 'unavailable: ' . $e->getMessage()];
        }
        foreach (KeyRegistry::licenseRoots() as $kid => $pub) {
            $rows[] = ['LICENSE_ROOT', $kid, Base64Url::encode($pub)];
        }
        foreach (KeyRegistry::ciAttestationKeys() as $kid => $pub) {
            $rows[] = ['CI_ATTESTATION', $kid, Base64Url::encode($pub)];
        }
        CLI::table($rows, ['Purpose', 'Key id', 'Public key (base64url Ed25519)']);
    }
}

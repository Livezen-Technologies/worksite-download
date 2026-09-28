<?php

namespace App\Libraries\Signing;

use RuntimeException;

/**
 * Dev/test key provider: keys generated on first use under writable/keys.
 * Refuses to operate when the environment is PRODUCTION.
 */
final class FileKeyProvider implements KeyProviderInterface
{
    public function __construct(private readonly string $dir, private readonly bool $production = false)
    {
    }

    public function sign(string $keyId, string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secretKey($keyId));
    }

    public function publicKey(string $keyId): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secretKey($keyId));
    }

    private function secretKey(string $keyId): string
    {
        if ($this->production) {
            throw new RuntimeException('FileKeyProvider is disabled in production; use the signing service / KMS.');
        }
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $keyId)) {
            throw new RuntimeException('Invalid key id');
        }
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
        $path = rtrim($this->dir, '/') . '/' . $keyId . '.key';
        if (! is_file($path)) {
            $kp = sodium_crypto_sign_keypair();
            file_put_contents($path, base64_encode(sodium_crypto_sign_secretkey($kp)));
            chmod($path, 0600);
        }

        return base64_decode(trim((string) file_get_contents($path)), true) ?: throw new RuntimeException('Corrupt key file');
    }
}

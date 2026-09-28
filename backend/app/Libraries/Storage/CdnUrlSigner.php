<?php

namespace App\Libraries\Storage;

use App\Libraries\Base64Url;

/**
 * HMAC-signed CDN URLs validated at the edge (docker/cdn-worker) or by the dev
 * CDN route: sig = HMAC-SHA256(secret, "/<key>\n<exp>\n<filename>").
 */
final class CdnUrlSigner
{
    public function __construct(private readonly string $secret)
    {
    }

    public function sign(string $host, string $key, int $ttl, string $filename, ?int $now = null): string
    {
        $exp  = ($now ?? time()) + $ttl;
        $path = '/' . ltrim($key, '/');
        $sig  = $this->signature($path, $exp, $filename);

        return rtrim($host, '/') . implode('/', array_map('rawurlencode', explode('/', $path)))
            . '?exp=' . $exp . '&fn=' . rawurlencode($filename) . '&sig=' . $sig;
    }

    public function signature(string $path, int $exp, string $filename): string
    {
        return Base64Url::encode(hash_hmac('sha256', $path . "\n" . $exp . "\n" . $filename, $this->secret, true));
    }

    public function verify(string $path, int $exp, string $filename, string $sig, ?int $now = null): bool
    {
        if ($exp < ($now ?? time())) {
            return false;
        }

        return hash_equals($this->signature($path, $exp, $filename), $sig);
    }
}

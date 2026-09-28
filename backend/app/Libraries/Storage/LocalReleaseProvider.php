<?php

namespace App\Libraries\Storage;

use RuntimeException;

/**
 * Filesystem provider — development only (FR-UC-STO-01). Downloads are served
 * by the dev CDN route which validates the same HMAC URL signature as the edge.
 */
final class LocalReleaseProvider implements ReleaseStorageProviderInterface
{
    public function __construct(
        private readonly string $root,
        private readonly string $cdnHost,
        private readonly CdnUrlSigner $signer,
    ) {
        if (! is_dir($this->root)) {
            mkdir($this->root, 0775, true);
        }
    }

    public function path(string $key): string
    {
        $key = ltrim($key, '/');
        if ($key === '' || str_contains($key, '..') || str_contains($key, "\0")) {
            throw new RuntimeException('Invalid object key');
        }

        return rtrim($this->root, '/') . '/' . $key;
    }

    public function initiateMultipart(string $key, string $contentType = 'application/octet-stream'): array
    {
        $id = bin2hex(random_bytes(12));
        @mkdir($this->path('_multipart/' . $id), 0775, true);

        return ['upload_id' => $id, 'key' => $key];
    }

    public function presignUploadPart(string $key, string $uploadId, int $partNumber, int $ttl = 3600): string
    {
        $exp = time() + $ttl;
        $sig = $this->signer->signature('/_multipart/' . $uploadId . '/' . $partNumber, $exp, $key);

        return rtrim(config('UpdateCenter')->publicBaseUrl, '/') . '/_dev-storage/part/' . $uploadId . '/' . $partNumber . '?exp=' . $exp . '&key=' . rawurlencode($key) . '&sig=' . $sig;
    }

    public function writePart(string $uploadId, int $partNumber, string $data): string
    {
        $file = $this->path('_multipart/' . $uploadId . '/' . str_pad((string) $partNumber, 5, '0', STR_PAD_LEFT));
        file_put_contents($file, $data);

        return '"' . md5($data) . '"';
    }

    public function completeMultipart(string $key, string $uploadId, array $parts): void
    {
        $dest = $this->path($key);
        @mkdir(dirname($dest), 0775, true);
        $out = fopen($dest, 'wb');
        usort($parts, static fn ($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);
        foreach ($parts as $p) {
            $file = $this->path('_multipart/' . $uploadId . '/' . str_pad((string) $p['PartNumber'], 5, '0', STR_PAD_LEFT));
            if (! is_file($file)) {
                fclose($out);

                throw new RuntimeException('Missing part ' . $p['PartNumber']);
            }
            $in = fopen($file, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            unlink($file);
        }
        fclose($out);
        @rmdir($this->path('_multipart/' . $uploadId));
    }

    public function abortMultipart(string $key, string $uploadId): void
    {
        foreach (glob($this->path('_multipart/' . $uploadId) . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->path('_multipart/' . $uploadId));
    }

    public function putFile(string $key, string $localPath, string $contentType = 'application/octet-stream'): void
    {
        $dest = $this->path($key);
        @mkdir(dirname($dest), 0775, true);
        if (! @rename($localPath, $dest) && ! copy($localPath, $dest)) {
            throw new RuntimeException('Could not store object');
        }
    }

    public function head(string $key): ?array
    {
        $p = $this->path($key);
        if (! is_file($p)) {
            return null;
        }
        clearstatcache(true, $p);

        return ['size' => (int) filesize($p), 'etag' => null, 'last_modified' => gmdate('c', (int) filemtime($p))];
    }

    public function stream(string $key)
    {
        $h = @fopen($this->path($key), 'rb');
        if ($h === false) {
            throw new RuntimeException('Object not found: ' . $key);
        }

        return $h;
    }

    public function copy(string $from, string $to): void
    {
        $dest = $this->path($to);
        @mkdir(dirname($dest), 0775, true);
        if (! copy($this->path($from), $dest)) {
            throw new RuntimeException('Copy failed');
        }
    }

    public function move(string $from, string $to): void
    {
        $dest = $this->path($to);
        @mkdir(dirname($dest), 0775, true);
        if (! rename($this->path($from), $dest)) {
            throw new RuntimeException('Move failed');
        }
    }

    public function delete(string $key): void
    {
        $p = $this->path($key);
        if (is_file($p)) {
            unlink($p);
        }
    }

    public function signedDownloadUrl(string $key, int $ttl, string $filename): string
    {
        return $this->signer->sign($this->cdnHost, $key, $ttl, $filename);
    }

    public function testConnection(): array
    {
        $t  = microtime(true);
        $ok = is_dir($this->root) && is_writable($this->root);

        return ['ok' => $ok, 'detail' => $ok ? 'Local filesystem (dev only)' : 'Storage root not writable', 'latency_ms' => (int) ((microtime(true) - $t) * 1000)];
    }

    public function usage(string $prefix = ''): array
    {
        $bytes = $objects = 0;
        $dir   = $prefix === '' ? $this->root : $this->path($prefix);
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $bytes += $f->getSize();
                    $objects++;
                }
            }
        }

        return ['bytes' => $bytes, 'objects' => $objects];
    }
}

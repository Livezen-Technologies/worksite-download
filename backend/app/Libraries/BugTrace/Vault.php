<?php

namespace App\Libraries\BugTrace;

use App\Libraries\SecretStore;
use App\Libraries\Storage\ReleaseStorageProviderInterface;
use App\Libraries\Storage\StorageFactory;
use RuntimeException;

/**
 * Private, encrypted object storage for Bug Trace blobs (FR-BT-PRV-07,
 * FR-BT-ING-04): minidumps, screenshots, logs, recordings and debug symbols.
 *
 * Objects live under the `bugtrace/` prefix of the active storage provider and
 * are encrypted client-side (XChaCha20-Poly1305 secretstream, 64 KiB chunks)
 * with a key from the secret service (BUGTRACE_BLOB_KEY, else derived from the
 * app secret). Objects are never exposed through the CDN; staff read them
 * through short-lived HMAC-signed API URLs that also require a session.
 */
final class Vault
{
    private const MAGIC = "BTE1";
    private const CHUNK = 65536;

    private static ?ReleaseStorageProviderInterface $provider = null;

    public static function setProvider(?ReleaseStorageProviderInterface $p): void
    {
        self::$provider = $p;
    }

    public static function provider(): ReleaseStorageProviderInterface
    {
        if (self::$provider !== null) {
            return self::$provider;
        }
        $row = db_connect()->table('storage_providers')->where('is_active', 1)->orderBy('id')->get()->getRowArray();
        if (! $row) {
            throw new RuntimeException('No active storage provider');
        }

        return StorageFactory::fromRow($row);
    }

    public static function key(): string
    {
        $k = SecretStore::get('BUGTRACE_BLOB_KEY');
        if ($k !== null && strlen($k) >= 32) {
            return hash('sha256', $k, true);
        }

        return hash_hkdf('sha256', SecretStore::appSecret(), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 'bugtrace-blob-v1');
    }

    /** @return array{size: int, sha256: string} size/hash of the plaintext */
    public static function putBytes(string $key, string $bytes): array
    {
        $tmp = self::tmp();
        file_put_contents($tmp, $bytes);
        try {
            return self::putFile($key, $tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array{size: int, sha256: string} */
    public static function putFile(string $key, string $localPath): array
    {
        self::assertKey($key);
        $enc  = self::tmp();
        $info = self::encryptFile($localPath, $enc);
        try {
            self::provider()->putFile($key, $enc, 'application/octet-stream');
        } finally {
            @unlink($enc);
        }

        return $info;
    }

    public static function getBytes(string $key): string
    {
        self::assertKey($key);
        $in = self::provider()->stream($key);
        try {
            return self::decryptStream($in);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
    }

    /** Raw stored (encrypted) bytes — used by tests to prove encryption at rest. */
    public static function rawBytes(string $key): string
    {
        $in  = self::provider()->stream($key);
        $raw = stream_get_contents($in);
        fclose($in);

        return (string) $raw;
    }

    public static function delete(string $key): void
    {
        try {
            self::provider()->delete($key);
        } catch (\Throwable $e) {
            log_message('warning', 'bugtrace blob delete failed {key}: {msg}', ['key' => $key, 'msg' => $e->getMessage()]);
        }
    }

    /** @return array{size: int, sha256: string} */
    public static function encryptFile(string $src, string $dst): array
    {
        $in  = fopen($src, 'rb');
        $out = fopen($dst, 'wb');
        if (! $in || ! $out) {
            throw new RuntimeException('Cannot open files for encryption');
        }
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push(self::key());
        fwrite($out, self::MAGIC . $header);
        $hash = hash_init('sha256');
        $size = 0;
        $buf  = fread($in, self::CHUNK);
        do {
            $next = feof($in) ? '' : fread($in, self::CHUNK);
            $last = $next === '' || $next === false;
            hash_update($hash, (string) $buf);
            $size += strlen((string) $buf);
            fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, (string) $buf, '', $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE));
            $buf = $next;
        } while (! $last);
        fclose($in);
        fclose($out);

        return ['size' => $size, 'sha256' => hash_final($hash)];
    }

    /** @param resource $in */
    public static function decryptStream($in): string
    {
        $head = fread($in, 4 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if ($head === false || substr($head, 0, 4) !== self::MAGIC) {
            throw new RuntimeException('Not a Bug Trace encrypted object');
        }
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($head, 4), self::key());
        $plain = '';
        $csize = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        while (! feof($in)) {
            $chunk = '';
            while (strlen($chunk) < $csize && ! feof($in)) {
                $part = fread($in, $csize - strlen($chunk));
                if ($part === false || $part === '') {
                    break;
                }
                $chunk .= $part;
            }
            if ($chunk === '') {
                break;
            }
            $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
            if ($res === false) {
                throw new RuntimeException('Encrypted object is corrupt');
            }
            [$msg, $tag] = $res;
            $plain .= $msg;
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                break;
            }
        }

        return $plain;
    }

    // ------------------------------------------------------------ signed URLs

    public static function signedPath(string $attachmentId, int $ttl = 300): string
    {
        $exp = time() + $ttl;

        return '/api/v1/admin/bugtrace/attachments/' . rawurlencode($attachmentId) . '/download?exp=' . $exp . '&sig=' . self::signature($attachmentId, $exp);
    }

    public static function signature(string $attachmentId, int $exp): string
    {
        return hash_hmac('sha256', 'bt-att|' . $attachmentId . '|' . $exp, SecretStore::appSecret());
    }

    public static function verify(string $attachmentId, int $exp, string $sig): bool
    {
        return $exp >= time() && $exp <= time() + 3600 && hash_equals(self::signature($attachmentId, $exp), $sig);
    }

    private static function assertKey(string $key): void
    {
        if (! str_starts_with($key, 'bugtrace/') || str_contains($key, '..')) {
            throw new RuntimeException('Bug Trace objects must live under bugtrace/');
        }
    }

    private static function tmp(): string
    {
        $dir = WRITEPATH . 'uploads/bugtrace';
        if (! is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        return tempnam($dir, 'bt');
    }
}

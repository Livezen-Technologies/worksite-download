<?php

namespace App\Libraries\Storage;

/**
 * Object storage provider for release binaries (§13.1). Object keys are
 * attributes, never public identifiers. PHP never streams package bytes to
 * clients: downloads always go through signedDownloadUrl() on the CDN host.
 */
interface ReleaseStorageProviderInterface
{
    /** @return array{upload_id: string, key: string} */
    public function initiateMultipart(string $key, string $contentType = 'application/octet-stream'): array;

    public function presignUploadPart(string $key, string $uploadId, int $partNumber, int $ttl = 3600): string;

    /** @param list<array{PartNumber:int, ETag:string}> $parts */
    public function completeMultipart(string $key, string $uploadId, array $parts): void;

    public function abortMultipart(string $key, string $uploadId): void;

    /** Upload a local file (workers / tus completion only). */
    public function putFile(string $key, string $localPath, string $contentType = 'application/octet-stream'): void;

    /** @return array{size:int, etag:?string, last_modified:?string}|null */
    public function head(string $key): ?array;

    /** @return resource read stream — workers only */
    public function stream(string $key);

    public function copy(string $from, string $to): void;

    public function move(string $from, string $to): void;

    public function delete(string $key): void;

    /** Short-lived URL on the CDN host with Content-Disposition: attachment. */
    public function signedDownloadUrl(string $key, int $ttl, string $filename): string;

    /** @return array{ok: bool, detail: string, latency_ms: int} */
    public function testConnection(): array;

    /** @return array{bytes: int, objects: int} */
    public function usage(string $prefix = ''): array;
}

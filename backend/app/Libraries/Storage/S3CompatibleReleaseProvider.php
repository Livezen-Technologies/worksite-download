<?php

namespace App\Libraries\Storage;

use RuntimeException;

/**
 * AWS Signature V4 client for S3-compatible object storage: Cloudflare R2,
 * AWS S3, DigitalOcean Spaces and MinIO (FR-UC-STO-01). Path-style addressing.
 */
class S3CompatibleReleaseProvider implements ReleaseStorageProviderInterface
{
    public function __construct(
        protected readonly string $endpoint,
        protected readonly string $bucket,
        protected readonly string $region,
        protected readonly string $accessKey,
        protected readonly string $secretKey,
        protected readonly string $cdnHost,
        protected readonly ?CdnUrlSigner $cdnSigner = null,
    ) {
    }

    public function initiateMultipart(string $key, string $contentType = 'application/octet-stream'): array
    {
        [$code, $body] = $this->request('POST', $key, ['uploads' => ''], ['content-type' => $contentType]);
        if ($code !== 200 || ! preg_match('#<UploadId>([^<]+)</UploadId>#', $body, $m)) {
            throw new RuntimeException("InitiateMultipart failed ({$code})");
        }

        return ['upload_id' => $m[1], 'key' => $key];
    }

    public function presignUploadPart(string $key, string $uploadId, int $partNumber, int $ttl = 3600): string
    {
        return $this->presign('PUT', $key, $ttl, ['partNumber' => (string) $partNumber, 'uploadId' => $uploadId]);
    }

    public function completeMultipart(string $key, string $uploadId, array $parts): void
    {
        usort($parts, static fn ($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);
        $xml = '<CompleteMultipartUpload>';
        foreach ($parts as $p) {
            $xml .= '<Part><PartNumber>' . (int) $p['PartNumber'] . '</PartNumber><ETag>' . htmlspecialchars($p['ETag'], ENT_XML1) . '</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';
        [$code, $body] = $this->request('POST', $key, ['uploadId' => $uploadId], ['content-type' => 'application/xml'], $xml);
        if ($code !== 200 || str_contains($body, '<Error>')) {
            throw new RuntimeException("CompleteMultipart failed ({$code})");
        }
    }

    public function abortMultipart(string $key, string $uploadId): void
    {
        $this->request('DELETE', $key, ['uploadId' => $uploadId]);
    }

    public function putFile(string $key, string $localPath, string $contentType = 'application/octet-stream'): void
    {
        $size = filesize($localPath);
        if ($size > 100 * 1024 * 1024) {
            $this->putLarge($key, $localPath, $contentType);

            return;
        }
        [$code] = $this->request('PUT', $key, [], ['content-type' => $contentType], null, $localPath);
        if ($code !== 200) {
            throw new RuntimeException("PutObject failed ({$code})");
        }
    }

    private function putLarge(string $key, string $localPath, string $contentType): void
    {
        $mp    = $this->initiateMultipart($key, $contentType);
        $in    = fopen($localPath, 'rb');
        $parts = [];
        $n     = 1;
        try {
            while (! feof($in)) {
                $chunk = fread($in, 64 * 1024 * 1024);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                [$code, , $headers] = $this->request('PUT', $key, ['partNumber' => (string) $n, 'uploadId' => $mp['upload_id']], [], $chunk);
                if ($code !== 200) {
                    throw new RuntimeException("UploadPart {$n} failed ({$code})");
                }
                $parts[] = ['PartNumber' => $n, 'ETag' => $headers['etag'] ?? ''];
                $n++;
            }
            $this->completeMultipart($key, $mp['upload_id'], $parts);
        } catch (\Throwable $e) {
            $this->abortMultipart($key, $mp['upload_id']);

            throw $e;
        } finally {
            fclose($in);
        }
    }

    public function head(string $key): ?array
    {
        [$code, , $headers] = $this->request('HEAD', $key);
        if ($code === 404) {
            return null;
        }
        if ($code !== 200) {
            throw new RuntimeException("HEAD failed ({$code})");
        }

        return ['size' => (int) ($headers['content-length'] ?? 0), 'etag' => $headers['etag'] ?? null, 'last_modified' => $headers['last-modified'] ?? null];
    }

    public function stream(string $key)
    {
        $url = $this->presign('GET', $key, 3600);
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 600]]);
        $h   = @fopen($url, 'rb', false, $ctx);
        if ($h === false) {
            throw new RuntimeException('Could not open object stream: ' . $key);
        }

        return $h;
    }

    public function copy(string $from, string $to): void
    {
        [$code, $body] = $this->request('PUT', $to, [], ['x-amz-copy-source' => '/' . $this->bucket . '/' . $this->encodeKey($from)]);
        if ($code !== 200 || str_contains($body, '<Error>')) {
            throw new RuntimeException("CopyObject failed ({$code})");
        }
    }

    public function move(string $from, string $to): void
    {
        $this->copy($from, $to);
        $this->delete($from);
    }

    public function delete(string $key): void
    {
        $this->request('DELETE', $key);
    }

    public function signedDownloadUrl(string $key, int $ttl, string $filename): string
    {
        if ($this->cdnSigner !== null && $this->cdnHost !== '') {
            return $this->cdnSigner->sign($this->cdnHost, $key, $ttl, $filename);
        }

        return $this->presign('GET', $key, $ttl, [
            'response-content-disposition' => 'attachment; filename="' . str_replace('"', '', $filename) . '"',
        ]);
    }

    public function testConnection(): array
    {
        $t = microtime(true);
        try {
            [$code] = $this->request('GET', '', ['list-type' => '2', 'max-keys' => '1']);

            return ['ok' => $code === 200, 'detail' => $code === 200 ? 'Bucket reachable' : "HTTP {$code}", 'latency_ms' => (int) ((microtime(true) - $t) * 1000)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage(), 'latency_ms' => (int) ((microtime(true) - $t) * 1000)];
        }
    }

    public function usage(string $prefix = ''): array
    {
        $bytes = $objects = 0;
        $token = null;
        for ($page = 0; $page < 200; $page++) {
            $q = ['list-type' => '2', 'prefix' => $prefix];
            if ($token) {
                $q['continuation-token'] = $token;
            }
            [$code, $body] = $this->request('GET', '', $q);
            if ($code !== 200) {
                break;
            }
            preg_match_all('#<Size>(\d+)</Size>#', $body, $m);
            $objects += count($m[1]);
            $bytes += array_sum(array_map('intval', $m[1]));
            if (! preg_match('#<NextContinuationToken>([^<]+)</NextContinuationToken>#', $body, $t)) {
                break;
            }
            $token = $t[1];
        }

        return ['bytes' => $bytes, 'objects' => $objects];
    }

    // ---- SigV4 ---------------------------------------------------------

    protected function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
    }

    /** @return array{0:string,1:string} [host, canonicalUri] */
    protected function target(string $key): array
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST) . (parse_url($this->endpoint, PHP_URL_PORT) ? ':' . parse_url($this->endpoint, PHP_URL_PORT) : '');
        $uri  = '/' . $this->bucket . ($key === '' ? '' : '/' . $this->encodeKey($key));

        return [$host, $uri];
    }

    /** @param array<string,string> $query */
    protected function canonicalQuery(array $query): string
    {
        ksort($query, SORT_STRING);
        $out = [];
        foreach ($query as $k => $v) {
            $out[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }

        return implode('&', $out);
    }

    /** @param array<string,string> $extra */
    public function presign(string $method, string $key, int $ttl, array $extra = [], ?int $now = null): string
    {
        [$host, $uri] = $this->target($key);
        $t            = $now ?? time();
        $amzDate      = gmdate('Ymd\THis\Z', $t);
        $date         = gmdate('Ymd', $t);
        $scope        = "{$date}/{$this->region}/s3/aws4_request";
        $query        = $extra + [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $this->accessKey . '/' . $scope,
            'X-Amz-Date'          => $amzDate,
            'X-Amz-Expires'       => (string) min($ttl, 604800),
            'X-Amz-SignedHeaders' => 'host',
        ];
        $cq        = $this->canonicalQuery($query);
        $canonical = "{$method}\n{$uri}\n{$cq}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
        $sts       = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonical);
        $sig       = hash_hmac('sha256', $sts, $this->signingKey($date));
        $scheme    = parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$host}{$uri}?{$cq}&X-Amz-Signature={$sig}";
    }

    protected function signingKey(string $date): string
    {
        $k = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $k = hash_hmac('sha256', $this->region, $k, true);
        $k = hash_hmac('sha256', 's3', $k, true);

        return hash_hmac('sha256', 'aws4_request', $k, true);
    }

    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers
     *
     * @return array{0:int,1:string,2:array<string,string>}
     */
    protected function request(string $method, string $key, array $query = [], array $headers = [], ?string $body = null, ?string $file = null): array
    {
        [$host, $uri] = $this->target($key);
        $t            = time();
        $amzDate      = gmdate('Ymd\THis\Z', $t);
        $date         = gmdate('Ymd', $t);
        $payloadHash  = $file !== null ? hash_file('sha256', $file) : hash('sha256', $body ?? '');
        $headers      = array_change_key_case($headers) + ['host' => $host, 'x-amz-date' => $amzDate, 'x-amz-content-sha256' => $payloadHash];
        ksort($headers);
        $canonHeaders = '';
        foreach ($headers as $k => $v) {
            $canonHeaders .= $k . ':' . trim((string) $v) . "\n";
        }
        $signed    = implode(';', array_keys($headers));
        $cq        = $this->canonicalQuery($query);
        $canonical = "{$method}\n{$uri}\n{$cq}\n{$canonHeaders}\n{$signed}\n{$payloadHash}";
        $scope     = "{$date}/{$this->region}/s3/aws4_request";
        $sts       = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonical);
        $sig       = hash_hmac('sha256', $sts, $this->signingKey($date));
        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signed}, Signature={$sig}";

        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https';
        $url    = "{$scheme}://{$host}{$uri}" . ($cq !== '' ? '?' . $cq : '');
        $ch     = curl_init($url);
        $hdrs   = [];
        foreach ($headers as $k => $v) {
            if ($k !== 'host') {
                $hdrs[] = $k . ': ' . $v;
            }
        }
        $respHeaders = [];
        $opts        = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_TIMEOUT        => 900,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$respHeaders) {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }

                return strlen($line);
            },
        ];
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        if ($file !== null) {
            $fh                       = fopen($file, 'rb');
            $opts[CURLOPT_UPLOAD]     = true;
            $opts[CURLOPT_INFILE]     = $fh;
            $opts[CURLOPT_INFILESIZE] = filesize($file);
        } elseif ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (isset($fh)) {
            fclose($fh);
        }
        if ($resp === false) {
            throw new RuntimeException('Storage request failed: ' . $err);
        }

        return [$code, (string) $resp, $respHeaders];
    }
}

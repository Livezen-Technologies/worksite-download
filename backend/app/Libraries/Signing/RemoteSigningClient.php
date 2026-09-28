<?php

namespace App\Libraries\Signing;

use App\Libraries\Base64Url;
use RuntimeException;

/**
 * Talks to the signing-service container over mTLS (§3.5). The API tier holds no
 * release key material.
 */
final class RemoteSigningClient implements SigningClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientCert = '',
        private readonly string $clientKey = '',
        private readonly string $caCert = '',
    ) {
    }

    public function sign(string $kind, array $document, array $evidence): array
    {
        [$code, $body] = $this->request('POST', '/sign', ['kind' => $kind, 'document' => $document, 'evidence' => $evidence]);
        if ($code === 409) {
            throw new SigningRefusedException((array) ($body['reasons'] ?? ['refused']));
        }
        if ($code !== 200 || ! isset($body['document'])) {
            throw new RuntimeException('Signing service error (' . $code . ')');
        }

        return $body['document'];
    }

    public function releasePublicKeys(): array
    {
        [$code, $body] = $this->request('GET', '/keys');
        if ($code !== 200) {
            throw new RuntimeException('Signing service unavailable');
        }
        $out = [];
        foreach ((array) ($body['keys'] ?? []) as $k) {
            $out[$k['key_id']] = Base64Url::decode($k['public_key']);
        }

        return $out;
    }

    public function health(): array
    {
        try {
            [$code, $body] = $this->request('GET', '/health');

            return ['ok' => $code === 200, 'detail' => (string) ($body['detail'] ?? "HTTP {$code}")];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch   = curl_init(rtrim($this->baseUrl, '/') . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        if ($this->clientCert !== '') {
            $opts[CURLOPT_SSLCERT] = $this->clientCert;
            $opts[CURLOPT_SSLKEY]  = $this->clientKey;
        }
        if ($this->caCert !== '') {
            $opts[CURLOPT_CAINFO] = $this->caCert;
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Signing service unreachable: ' . $err);
        }

        return [$code, json_decode((string) $raw, true) ?? []];
    }
}

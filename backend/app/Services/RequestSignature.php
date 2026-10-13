<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Libraries\SecretStore;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * Signed installation requests (IMPL-LIC-01 §4.4): after activation an installation proves
 * every call with an HMAC over the request instead of sending its key.
 *
 *   Authorization: WS-HMAC-SHA256 inst=<installation_id>, ts=<unix>, nonce=<16-64 [A-Za-z0-9_-]>, sig=<base64url>
 *   sig = HMAC-SHA256(request_secret, "WS1\n" . METHOD . "\n" . path . "\n" . ts . "\n" . nonce . "\n" . sha256_hex(body))
 *
 * The timestamp must be within five minutes, and a nonce is accepted once per installation.
 */
final class RequestSignature
{
    public const SCHEME = 'WS-HMAC-SHA256 ';

    public const SKEW = 300;

    public const SECRET_PREFIX = 'wsrs_';

    public static function stringToSign(string $method, string $path, int $ts, string $nonce, string $body): string
    {
        return "WS1\n" . strtoupper($method) . "\n" . '/' . ltrim($path, '/') . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    public static function sign(string $secret, string $method, string $path, int $ts, string $nonce, string $body): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', self::stringToSign($method, $path, $ts, $nonce, $body), $secret, true)), '+/', '-_'), '=');
    }

    /** A new request secret and its sealed reference for server_installations.request_secret_ref. @return array{0:string,1:string} */
    public static function issue(): array
    {
        $secret = self::SECRET_PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$secret, SecretStore::seal($secret)];
    }

    public static function applies(string $header): bool
    {
        return str_starts_with($header, self::SCHEME);
    }

    /**
     * The installation the request comes from, with the `license` shape the update API uses.
     *
     * @return array<string, mixed>
     */
    public static function verify(IncomingRequest $request, ?int $now = null): array
    {
        $now ??= time();
        $p   = self::parse($request->getHeaderLine('Authorization'));
        if (abs($now - $p['ts']) > self::SKEW) {
            throw new ApiException('REQUEST_EXPIRED', 'The request timestamp is more than five minutes from the Download Center\'s clock; check the server clock.', 401, ['server_time' => gmdate('Y-m-d\TH:i:s\Z', $now)]);
        }
        $db  = db_connect();
        $row = $db->table('server_installations')->where('installation_id', $p['inst'])->get()->getRowArray();
        $secret = $row ? SecretStore::get((string) ($row['request_secret_ref'] ?? '')) : null;
        $path   = '/' . ltrim($request->getUri()->getPath(), '/');
        $expect = $secret ? self::sign($secret, $request->getMethod(), $path, $p['ts'], $p['nonce'], (string) $request->getBody()) : null;
        if ($expect === null || ! hash_equals($expect, $p['sig'])) {
            throw new ApiException('REQUEST_SIGNATURE_INVALID', 'The request signature is not valid for this installation.', 401);
        }
        try {
            $db->table('license_nonces')->insert(['installation_id' => $p['inst'], 'nonce' => $p['nonce'], 'created_at' => Catalog::now()]);
        } catch (\Throwable) {
            throw new ApiException('REQUEST_REPLAYED', 'This request was already received.', 401);
        }
        if ($db->affectedRows() < 1) {
            throw new ApiException('REQUEST_REPLAYED', 'This request was already received.', 401);
        }
        if (random_int(1, 200) === 1) {
            $db->table('license_nonces')->where('created_at <', gmdate('Y-m-d H:i:s', $now - 7 * 86400))->delete();
        }

        return $row + ['license' => ['installation_id' => $row['installation_id'], 'edition' => strtoupper((string) $row['edition'])], 'signed_request' => true];
    }

    /** @return array{inst: string, ts: int, nonce: string, sig: string} */
    private static function parse(string $header): array
    {
        $out = [];
        foreach (explode(',', substr($header, strlen(self::SCHEME))) as $part) {
            [$k, $v] = array_map('trim', explode('=', $part, 2) + [1 => '']);
            $out[$k] = $v;
        }
        if (! isset($out['inst'], $out['ts'], $out['nonce'], $out['sig']) || ! ctype_digit($out['ts']) || ! preg_match('/^[A-Za-z0-9_-]{16,64}$/', $out['nonce']) || ! preg_match('/^[A-Za-z0-9_.:-]{6,64}$/', $out['inst'])) {
            throw new ApiException('REQUEST_SIGNATURE_INVALID', 'Malformed WS-HMAC-SHA256 authorization.', 401);
        }

        return ['inst' => $out['inst'], 'ts' => (int) $out['ts'], 'nonce' => $out['nonce'], 'sig' => $out['sig']];
    }
}

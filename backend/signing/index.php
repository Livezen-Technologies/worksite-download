<?php

/**
 * WorkSuite Update Center — Signing Service (§2.2, §3.5).
 *
 * Minimal internal HTTP service. It is the only process with access to the
 * release key (KMS / Vault Transit / HSM). Reachable only from update-api over
 * mTLS (enforced by the fronting nginx: ssl_verify_client on); no internet ingress.
 *
 *   GET  /health  → {ok, detail}
 *   GET  /keys    → {keys: [{key_id, public_key}]}
 *   POST /sign    → {kind: RELEASE|CHANNEL|ROTATION, document, evidence}
 *                   200 {document} | 409 {reasons} | 400
 *
 * Environment:
 *   SIGNING_KEY_PROVIDER   vault | file            (file = secret key mounted as a Docker/K8s secret)
 *   SIGNING_RELEASE_KEY_ID e.g. ws-release-2026a
 *   SIGNING_KEY_FILE       path to base64 Ed25519 secret key (provider=file)
 *   VAULT_ADDR, VAULT_TOKEN_REF (e.g. file:/run/secrets/vault_token), VAULT_TRANSIT_MOUNT
 *   CI_ATTESTATION_KEYS    JSON {kid: base64url public key}
 *   OVERLAP_RELEASE_KEYS   JSON {kid: base64url public key} of keys still accepted as evidence
 *   REQUIRE_ATTESTATION=1  REQUIRE_APPROVALS=1  (both default to 1)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Libraries\Base64Url;
use App\Libraries\Signing\Ed25519;
use App\Libraries\Signing\KeyProviderInterface;
use App\Libraries\Signing\SigningPolicy;
use App\Libraries\Signing\VaultTransitKeyProvider;

final class MountedKeyProvider implements KeyProviderInterface
{
    private string $secret;

    public function __construct(string $file)
    {
        $raw = is_readable($file) ? base64_decode(trim((string) file_get_contents($file)), true) : false;
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Signing key file missing or invalid');
        }
        $this->secret = $raw;
    }

    public function sign(string $keyId, string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secret);
    }

    public function publicKey(string $keyId): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secret);
    }
}

function respond(int $code, array $body): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function auditLine(array $entry): void
{
    // Every sign request is logged (shipped to the write-once audit store by the log pipeline).
    file_put_contents('php://stderr', json_encode(['ts' => gmdate('c'), 'svc' => 'signing'] + $entry, JSON_UNESCAPED_SLASHES) . "\n");
}

function keysFromEnv(string $name): array
{
    $out = [];
    foreach ((array) json_decode((string) getenv($name), true) as $kid => $b64) {
        $raw = Base64Url::decode((string) $b64);
        if (strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $out[(string) $kid] = $raw;
        }
    }

    return $out;
}

$keyId = (string) (getenv('SIGNING_RELEASE_KEY_ID') ?: 'ws-release-dev');
try {
    $keys = match (getenv('SIGNING_KEY_PROVIDER') ?: 'file') {
        'vault' => new VaultTransitKeyProvider((string) getenv('VAULT_ADDR'), (string) (getenv('VAULT_TOKEN_REF') ?: 'VAULT_TOKEN'), (string) (getenv('VAULT_TRANSIT_MOUNT') ?: 'transit')),
        default => new MountedKeyProvider((string) (getenv('SIGNING_KEY_FILE') ?: '/run/secrets/release_key')),
    };
    $releaseKeys = [$keyId => $keys->publicKey($keyId)] + keysFromEnv('OVERLAP_RELEASE_KEYS');
} catch (Throwable $e) {
    auditLine(['event' => 'startup_error', 'error' => $e->getMessage()]);
    respond(503, ['ok' => false, 'detail' => 'Signing key unavailable']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($method === 'GET' && $path === '/health') {
    respond(200, ['ok' => true, 'detail' => 'signing-service · ' . $keyId]);
}
if ($method === 'GET' && $path === '/keys') {
    respond(200, ['keys' => array_map(static fn ($k, $p) => ['key_id' => $k, 'public_key' => Base64Url::encode($p)], array_keys($releaseKeys), $releaseKeys)]);
}
if ($method !== 'POST' || $path !== '/sign') {
    respond(404, ['error' => 'not found']);
}

$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 2_000_000) {
    respond(413, ['error' => 'request too large']);
}
$req = json_decode($raw, true);
if (! is_array($req) || ! is_array($req['document'] ?? null) || ! is_string($req['kind'] ?? null)) {
    respond(400, ['error' => 'kind and document are required']);
}
$policy = new SigningPolicy(
    keysFromEnv('CI_ATTESTATION_KEYS'),
    $releaseKeys,
    (getenv('REQUIRE_ATTESTATION') ?: '1') === '1',
    (getenv('REQUIRE_APPROVALS') ?: '1') === '1',
);
$reasons = $policy->check($req['kind'], $req['document'], (array) ($req['evidence'] ?? []));
$subject = ['kind' => $req['kind'], 'app' => $req['document']['app'] ?? null, 'version' => $req['document']['version'] ?? ($req['document']['current']['version'] ?? null), 'channel' => $req['document']['channel'] ?? null, 'client' => $_SERVER['SSL_CLIENT_S_DN'] ?? $_SERVER['HTTP_X_SSL_CLIENT_S_DN'] ?? null];
if ($reasons !== []) {
    auditLine(['event' => 'sign_refused', 'reasons' => $reasons] + $subject);
    respond(409, ['reasons' => $reasons]);
}
$signed = Ed25519::signDocument($req['document'], $keys, $keyId);
auditLine(['event' => 'signed', 'key_id' => $keyId, 'sha256' => hash('sha256', \App\Libraries\CanonicalJson::encode($signed))] + $subject);
respond(200, ['document' => $signed]);

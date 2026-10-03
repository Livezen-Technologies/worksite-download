<?php

namespace Tests\Feature;

use App\Libraries\Base64Url;
use App\Services\AuthContext;
use App\Services\AuthService;
use Tests\Support\UcTestCase;

/**
 * Passkey sign-in with a software authenticator (P-256, "packed" assertions).
 *
 * Synced passkeys — iCloud Keychain, Google Password Manager — always report a
 * signature counter of 0. The server used to store "previous + 1" after each
 * sign-in, so the second sign-in compared 1 ≥ 0 and was refused as a cloned
 * key ("Passkey verification failed").
 */
final class PasskeyTest extends UcTestCase
{
    private \OpenSSLAsymmetricKey $key;
    private string $credentialId;
    private string $rpId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key          = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->credentialId = Base64Url::encode(random_bytes(16));
        $this->rpId         = parse_url(config('UpdateCenter')->publicBaseUrl, PHP_URL_HOST) ?: 'localhost';
    }

    public function testASyncedPasskeyWithCounterZeroSignsInAgainAndAgain(): void
    {
        $user = $this->makeUser(['super_admin']);
        $this->register($user);

        foreach ([1, 2, 3] as $attempt) {
            $this->assertNull($this->signIn($user, 0), "sign-in {$attempt}");
        }
        $this->assertSame(0, (int) $this->row($user)['sign_count'], 'stores the counter the authenticator reported');
    }

    public function testASecurityKeyCounterIsStoredAndAReplayedCounterIsRefused(): void
    {
        $user = $this->makeUser(['super_admin']);
        $this->register($user);

        $this->assertNull($this->signIn($user, 5));
        $this->assertSame(5, (int) $this->row($user)['sign_count']);
        $this->assertNull($this->signIn($user, 6));
        $this->assertSame('PASSKEY_INVALID', $this->signIn($user, 6), 'a counter that did not move is a cloned key');
    }

    private function register(array $user): void
    {
        openssl_pkey_export($this->key, $unused);
        db_connect()->table('webauthn_credentials')->insert([
            'user_id'       => $user['id'],
            'credential_id' => $this->credentialId,
            'public_key'    => openssl_pkey_get_details($this->key)['key'],
            'sign_count'    => 0,
            'name'          => 'Test passkey',
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function row(array $user): array
    {
        return db_connect()->table('webauthn_credentials')->where('user_id', $user['id'])->get()->getRowArray();
    }

    /** One sign-in at the given authenticator counter: null on success, else the error code. */
    private function signIn(array $user, int $counter): ?string
    {
        $challenge = random_bytes(32);
        cache()->save('wa_get_' . $user['id'], Base64Url::encode($challenge), 120);

        $clientData = json_encode([
            'type' => 'webauthn.get', 'challenge' => Base64Url::encode($challenge),
            'origin' => 'https://' . $this->rpId, 'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
        // rpIdHash ‖ flags (user present + verified) ‖ big-endian counter
        $authData = hash('sha256', $this->rpId, true) . chr(0x05) . pack('N', $counter);
        openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        $ctx          = new AuthContext();
        $ctx->user    = $user;
        $ctx->session = null;

        try {
            (new AuthService())->passkeyAssert($ctx, [
                'id' => $this->credentialId,
                'clientDataJSON' => Base64Url::encode($clientData),
                'authenticatorData' => Base64Url::encode($authData),
                'signature' => Base64Url::encode($signature),
            ]);
        } catch (\App\Exceptions\ApiException $e) {
            return $e->errorCode;
        }

        return null;
    }
}

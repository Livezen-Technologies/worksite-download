<?php

namespace Tests\Unit;

use App\Libraries\Base64Url;
use App\Libraries\CanonicalJson;
use App\Libraries\Signing\Ed25519;
use App\Libraries\Signing\FileKeyProvider;
use App\Libraries\Signing\LocalSigningClient;
use App\Libraries\Signing\SigningPolicy;
use App\Libraries\Signing\SigningRefusedException;
use App\Libraries\Trust\TokenException;
use App\Libraries\Trust\TokenIssuer;
use App\Libraries\Trust\TrustChain;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\TrustFixture;

final class TrustAndSigningTest extends CIUnitTestCase
{
    public function testValidTptVerifies(): void
    {
        $f     = new TrustFixture();
        $chain = new TrustChain(['ws-license-root-test' => $f->rootPublic]);
        $p     = $chain->verifyTpt($f->tpt(['channel' => 'beta', 'org_id' => 12]));
        $this->assertSame('beta', $p['channel']);
        $this->assertSame('inst_01J6TEST', $p['_installation_id']);
    }

    public function testTamperedTptIsRejected(): void
    {
        $f   = new TrustFixture();
        $doc = json_decode(Base64Url::decode($f->tpt(['channel' => 'stable'])), true);
        $doc['payload']['channel'] = 'dev';
        $this->expectException(TokenException::class);
        (new TrustChain(['ws-license-root-test' => $f->rootPublic]))->verifyTpt($doc);
    }

    public function testUnpinnedRootIsRejected(): void
    {
        $f     = new TrustFixture();
        $other = new TrustFixture();
        $this->expectException(TokenException::class);
        (new TrustChain(['ws-license-root-test' => $other->rootPublic]))->verifyTpt($f->tpt([]));
    }

    public function testExpiredTptIsRejected(): void
    {
        $f = new TrustFixture();
        $this->expectExceptionMessage('expired');
        (new TrustChain(['ws-license-root-test' => $f->rootPublic], time() + 8 * 86400))->verifyTpt($f->tpt([]));
    }

    public function testPolicyKeyFromAnotherInstallationIsRejected(): void
    {
        $f     = new TrustFixture();
        $other = new TrustFixture('inst_OTHER');
        // Policy cert issued by the other installation's key, chained to this installation cert.
        $tpt = TokenIssuer::tpt(['tenant_id' => $f->tenantId], 'tenant-policy-1', $other->policySecret, [$other->policyCert, $f->installationCert]);
        $this->expectException(TokenException::class);
        (new TrustChain(['ws-license-root-test' => $f->rootPublic]))->verifyTpt($tpt);
    }

    public function testLicenseTokenCarriesEdition(): void
    {
        $f = new TrustFixture(edition: 'ENTERPRISE');
        $p = (new TrustChain(['ws-license-root-test' => $f->rootPublic]))->verifyLicenseToken($f->license(['apps' => ['lk.worksuite.server']]));
        $this->assertSame('ENTERPRISE', $p['edition']);
        $this->assertSame('inst_01J6TEST', $p['installation_id']);
    }

    private function signer(bool $requireAttestation, array $ciKeys = []): LocalSigningClient
    {
        $keys = new FileKeyProvider(sys_get_temp_dir() . '/uc-unit-keys', false);

        return new LocalSigningClient($keys, 'ws-release-unit', new SigningPolicy($ciKeys, ['ws-release-unit' => $keys->publicKey('ws-release-unit')], $requireAttestation, true));
    }

    private function manifest(string $sha = 'aa'): array
    {
        return ['schema' => 2, 'type' => 'release', 'app' => 'lk.worksuite.desktop', 'version' => '2.7.0', 'build' => 27001, 'channel' => 'stable', 'packages' => [['file_name' => 'a.exe', 'sha256' => str_repeat($sha, 32)]]];
    }

    private const APPROVALS = [['kind' => 'QA', 'decision' => 'PASS', 'user_id' => '1'], ['kind' => 'RELEASE', 'decision' => 'APPROVED', 'user_id' => '2']];

    public function testSigningRefusesWithoutAttestation(): void
    {
        try {
            $this->signer(true)->sign('RELEASE', $this->manifest(), ['approvals' => self::APPROVALS, 'attestation' => null]);
            $this->fail('should refuse');
        } catch (SigningRefusedException $e) {
            $this->assertStringContainsString('attestation missing', implode(' ', $e->reasons));
        }
    }

    public function testSigningRefusesSamePersonApproval(): void
    {
        $this->expectException(SigningRefusedException::class);
        $this->signer(false)->sign('RELEASE', $this->manifest(), ['approvals' => [['kind' => 'QA', 'decision' => 'PASS', 'user_id' => '7'], ['kind' => 'RELEASE', 'decision' => 'APPROVED', 'user_id' => '7']]]);
    }

    public function testSigningRequiresHashesInAttestation(): void
    {
        $kp      = sodium_crypto_sign_keypair();
        $payload = ['app_id' => 'lk.worksuite.desktop', 'version' => '2.7.0', 'build' => 27001, 'files' => [['name' => 'a.exe', 'sha256' => str_repeat('bb', 32)]]];
        $att     = ['payload' => $payload, 'key_id' => 'ci-1', 'signature' => Base64Url::encode(sodium_crypto_sign_detached(CanonicalJson::encode($payload), sodium_crypto_sign_secretkey($kp)))];
        $signer  = $this->signer(true, ['ci-1' => sodium_crypto_sign_publickey($kp)]);
        try {
            $signer->sign('RELEASE', $this->manifest('aa'), ['approvals' => self::APPROVALS, 'attestation' => $att]);
            $this->fail('mismatched hash must be refused (AC-C36)');
        } catch (SigningRefusedException $e) {
            $this->assertStringContainsString('not in CI attestation', implode(' ', $e->reasons));
        }
        $signed = $signer->sign('RELEASE', $this->manifest('bb'), ['approvals' => self::APPROVALS, 'attestation' => $att]);
        $this->assertTrue(Ed25519::verifyDocument($signed, $signer->releasePublicKeys()));
        $signed['version'] = '2.7.1';
        $this->assertFalse(Ed25519::verifyDocument($signed, $signer->releasePublicKeys()));
    }

    public function testChannelManifestMustReferenceSignedRelease(): void
    {
        $signer  = $this->signer(false);
        $release = $signer->sign('RELEASE', $this->manifest(), ['approvals' => self::APPROVALS]);
        $channel = ['type' => 'channel', 'current' => ['version' => '2.7.0', 'release_manifest_sha256' => hash('sha256', CanonicalJson::encode($release))]];
        $this->assertArrayHasKey('signature', $signer->sign('CHANNEL', $channel, ['release_manifest' => $release]));
        $channel['current']['release_manifest_sha256'] = str_repeat('0', 64);
        $this->expectException(SigningRefusedException::class);
        $signer->sign('CHANNEL', $channel, ['release_manifest' => $release]);
    }

    public function testCanonicalJsonIsOrderIndependent(): void
    {
        $this->assertSame(CanonicalJson::encode(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]), CanonicalJson::encode(['a' => ['x' => 1, 'y' => 2], 'b' => 1]));
        $this->assertSame('{"a":"/ä"}', CanonicalJson::encode(['a' => '/ä']));
    }
}

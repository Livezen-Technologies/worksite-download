<?php

namespace Tests\Feature;

use App\Libraries\Base64Url;
use App\Libraries\CanonicalJson;
use Tests\Support\Fixtures;
use Tests\Support\UcTestCase;

/**
 * The Sparkle appcast WorkSuite Office's macOS updater reads (SparkleAppcastService): what it offers, with
 * the signature CI attested, and what it refuses.
 */
final class SparkleAppcastTest extends UcTestCase
{
    private array $dev;
    private array $qa;
    private array $rm;

    /** The application's Sparkle key pair: the public half is SUPublicEDKey in the app. */
    private string $sparkleSecret;
    private string $sparklePublic;

    /** CI's attestation key, pinned as ws-ci-test. */
    private string $ciSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dev = $this->makeUser(['developer'], 'dev@livezen.lk');
        $this->qa  = $this->makeUser(['qa'], 'qa@livezen.lk');
        $this->rm  = $this->makeUser(['release_manager'], 'rm@livezen.lk');

        $sparkle             = sodium_crypto_sign_keypair();
        $this->sparkleSecret = sodium_crypto_sign_secretkey($sparkle);
        $this->sparklePublic = sodium_crypto_sign_publickey($sparkle);

        $ci             = sodium_crypto_sign_keypair();
        $this->ciSecret = sodium_crypto_sign_secretkey($ci);
        config('UpdateCenter')->ciAttestationKeys = json_encode(['ws-ci-test' => Base64Url::encode(sodium_crypto_sign_publickey($ci))]);
    }

    /* The same helpers as ReleaseLifecycleTest: sign in, call, upload through tus, publish with QA and RM. */

    private function api(array $user, string $method, string $uri, array $body = []): array
    {
        $res = $this->withHeaders($this->actingAs($user))->withBodyFormat('json')->call($method, $uri, $body);

        return [$res->response()->getStatusCode(), $this->json($res)];
    }

    private function upload(string $releaseId, string $platform, string $arch, string $type, string $name, string $bytes): void
    {
        [$s, $j] = $this->api($this->dev, 'POST', "api/v1/admin/releases/{$releaseId}/packages", ['platform' => $platform, 'architecture' => $arch, 'package_type' => $type, 'file_name' => $name, 'size' => strlen($bytes)]);
        $this->assertSame(201, $s, json_encode($j));
        $uploadId = $j['upload']['id'];
        $csrf     = $this->actingAs($this->dev)['X-CSRF-Token'];
        $this->bodyFormat  = '';
        $this->requestBody = '';
        $res = $this->withHeaders(['X-CSRF-Token' => $csrf, 'Tus-Resumable' => '1.0.0', 'Upload-Length' => (string) strlen($bytes), 'Upload-Metadata' => 'upload_id ' . base64_encode($uploadId)])->post('api/v1/uploads/tus');
        $this->assertSame(201, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $res = $this->withHeaders(['X-CSRF-Token' => $csrf, 'Tus-Resumable' => '1.0.0', 'Upload-Offset' => '0', 'Content-Type' => 'application/offset+octet-stream'])->withBody($bytes)->patch("api/v1/uploads/tus/{$uploadId}");
        $this->assertSame(204, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $this->requestBody = '';
    }

    /**
     * An Office release with a Mac build per architecture, attested by CI — with a Sparkle signature for the
     * arm64 build only — published and fully rolled out. Returns [release id, arm64 bytes].
     *
     * @return array{0: string, 1: string}
     */
    private function release(string $version, int $build, string $type = 'RECOMMENDED', string $channel = 'STABLE', int $percentage = 100): array
    {
        [$s, $j] = $this->api($this->dev, 'POST', 'api/v1/admin/releases', ['app_id' => 'office', 'version' => $version, 'build' => $build, 'channel' => $channel, 'release_type' => $type, 'minimum_no_change' => true]);
        $this->assertSame(201, $s, json_encode($j));
        $id  = $j['id'];
        $arm = Fixtures::dmg();
        $x64 = Fixtures::dmg();

        $payload = ['app_id' => 'lk.worksuite.office', 'version' => $version, 'build' => $build, 'pipeline' => 'test', 'commit' => 'abc1234', 'files' => [
            ['name' => "WorkSuite-Office-{$version}-arm64.dmg", 'sha256' => hash('sha256', $arm), 'size' => strlen($arm), 'sparkle_ed_signature' => base64_encode(sodium_crypto_sign_detached($arm, $this->sparkleSecret))],
            ['name' => "WorkSuite-Office-{$version}-x64.dmg", 'sha256' => hash('sha256', $x64), 'size' => strlen($x64)],
        ]];
        [$s, $j] = $this->api($this->dev, 'POST', "api/v1/admin/releases/{$id}/attestation", ['payload' => $payload, 'signature' => Base64Url::encode(sodium_crypto_sign_detached(CanonicalJson::encode($payload), $this->ciSecret)), 'key_id' => 'ws-ci-test']);
        $this->assertSame(200, $s, json_encode($j));
        $this->assertTrue($j['verified']);

        $this->upload($id, 'macos', 'arm64', 'dmg', "WorkSuite-Office-{$version}-arm64.dmg", $arm);
        $this->upload($id, 'macos', 'x64', 'dmg', "WorkSuite-Office-{$version}-x64.dmg", $x64);
        [$s, $j] = $this->api($this->dev, 'PUT', "api/v1/admin/releases/{$id}/changelog", ['summary' => "WorkSuite Office {$version}", 'highlights' => [], 'markdown' => "## New Features\n- Drive files on the home screen <b>bold</b>"]);
        $this->assertSame(200, $s, json_encode($j));

        foreach ([[$this->dev, 'submit-test', []], [$this->qa, 'qa', ['decision' => 'PASS', 'comment' => 'ok']], [$this->rm, 'approve', ['comment' => 'ok']], [$this->rm, 'publish', ['reason' => 'test']]] as [$who, $step, $body]) {
            [$s, $j] = $this->api($who, 'POST', "api/v1/admin/releases/{$id}/{$step}", $body);
            $this->assertSame(200, $s, $step . ' ' . json_encode($j));
        }
        $this->assertSame('PUBLISHED', $j['status']);

        $internal = (int) db_connect()->table('releases')->where('public_id', $id)->get()->getRow('id');
        db_connect()->table('release_rollouts')->where('release_id', $internal)->update(['percentage' => $percentage, 'status' => $percentage >= 100 ? 'DONE' : 'ACTIVE']);

        return [$id, $arm];
    }

    private function appcast(string $query, string $channel = 'stable', string $app = 'lk.worksuite.office'): array
    {
        $res = $this->get("api/v1/apps/{$app}/channels/{$channel}/appcast.xml?{$query}");

        return [$res->response()->getStatusCode(), (string) $res->response()->getBody(), $res];
    }

    /** @return list<\SimpleXMLElement> */
    private function items(string $xml): array
    {
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc, $xml);

        return iterator_to_array($doc->channel->item, false);
    }

    public function testOffersTheMacBuildWithTheSignatureCiAttested(): void
    {
        [, $arm] = $this->release('9.3.2', 1042);

        [$status, $xml, $res] = $this->appcast('arch=arm64');
        $this->assertSame(200, $status, $xml);
        $this->assertStringStartsWith('application/rss+xml', $res->response()->getHeaderLine('Content-Type'));
        $this->assertSame('public, max-age=60', $res->response()->getHeaderLine('Cache-Control'));

        $items = $this->items($xml);
        $this->assertCount(1, $items);
        $sp = $items[0]->children('http://www.andymatuschak.org/xml-namespaces/sparkle');
        $this->assertSame('1042', (string) $sp->version, 'the build number, which the build stamps into CFBundleVersion');
        $this->assertSame('9.3.2', (string) $sp->shortVersionString);
        $this->assertSame('11.0', (string) $sp->minimumSystemVersion);
        $this->assertFalse(isset($sp->criticalUpdate));
        $this->assertStringContainsString('Drive files on the home screen', (string) $items[0]->description);
        $this->assertStringNotContainsString('<b>', (string) $items[0]->description, 'the notes as the site sanitises them');

        $enc = $items[0]->enclosure;
        $this->assertSame('http://example.com/download/office/9.3.2/macos/arm64/dmg', (string) $enc['url']);
        $this->assertSame((string) strlen($arm), (string) $enc['length']);
        $sig = base64_decode((string) $enc->attributes('http://www.andymatuschak.org/xml-namespaces/sparkle')->edSignature, true);
        $this->assertTrue(sodium_crypto_sign_verify_detached($sig, $arm, $this->sparklePublic), 'Sparkle verifies the archive against SUPublicEDKey');

        // The enclosure is the public download of that very build.
        $this->get('download/office/9.3.2/macos/arm64/dmg')->assertStatus(302);

        // No Sparkle signature attested for the Intel build: not offered, as Sparkle would refuse it.
        [$status, $xml] = $this->appcast('arch=x64');
        $this->assertSame(200, $status);
        $this->assertSame([], $this->items($xml));
    }

    public function testOffersOnlyWhatAnAnonymousUpdateCheckWouldBeOffered(): void
    {
        $this->release('9.3.2', 1042);
        // Staged at 25 %: a feed has no device to put in the cohort, so the fully rolled-out release stays.
        $this->release('9.3.3', 1043, 'RECOMMENDED', 'STABLE', 25);

        $items = $this->items($this->appcast('arch=arm64')[1]);
        $this->assertCount(1, $items);
        $this->assertSame('9.3.2', (string) $items[0]->children('http://www.andymatuschak.org/xml-namespaces/sparkle')->shortVersionString);

        // A critical release is critical to Sparkle too: no "Skip This Version".
        $this->release('9.3.4', 1044, 'CRITICAL');
        $items = $this->items($this->appcast('arch=arm64&type=dmg')[1]);
        $sp    = $items[0]->children('http://www.andymatuschak.org/xml-namespaces/sparkle');
        $this->assertSame('9.3.4', (string) $sp->shortVersionString);
        $this->assertTrue(isset($sp->criticalUpdate));
    }

    public function testRefusesWhatSparkleCannotUse(): void
    {
        $this->assertSame(422, $this->appcast('')[0], 'an architecture is required');
        $this->assertSame(422, $this->appcast('arch=universal')[0]);
        $this->assertSame(422, $this->appcast('arch=arm64&type=pkg')[0], 'an installer package is not an archive Sparkle swaps in');
        $this->assertSame(404, $this->appcast('arch=arm64', 'stable', 'lk.worksuite.nothing')[0]);

        // Sparkle sends no credentials: a channel that is not public does not answer.
        [$status, $body] = $this->appcast('arch=arm64', 'dev');
        $this->assertSame(403, $status, $body);
        $this->assertSame('CHANNEL_FORBIDDEN', json_decode($body, true)['error']['code']);

        // Nothing published yet: an empty feed, which Sparkle reads as "up to date".
        [$status, $xml] = $this->appcast('arch=arm64');
        $this->assertSame(200, $status);
        $this->assertSame([], $this->items($xml));
    }
}

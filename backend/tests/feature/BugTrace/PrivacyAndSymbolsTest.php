<?php

namespace Tests\Feature\BugTrace;

require_once __DIR__ . '/BugTraceTestCase.php';
require_once __DIR__ . '/../../unit/BugTrace/SourceMapTest.php';

use App\Libraries\BugTrace\Vault;
use App\Libraries\SecretStore;
use App\Libraries\Storage\CdnUrlSigner;
use App\Libraries\Totp;
use App\Services\BugTrace\PrivacyService;
use Tests\Unit\BugTrace\SourceMapTest;

/**
 * AC-C74 (deletion by user ref / installation, audited, MFA) and AC-C75
 * (source maps / debug symbols never publicly downloadable), FR-BT-PRV-04.
 */
final class PrivacyAndSymbolsTest extends BugTraceTestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n0000";

    public function testPrivacyDeleteRemovesEventsReportsAttachmentsAndIsAudited(): void
    {
        $sdk = ['X-BT-Key' => self::WEB_KEY];
        $att = $this->json($this->h($sdk + ['Content-Type' => 'image/png'], '', self::PNG)->post('api/v1/bugtrace/attachments?kind=screenshot'));
        $this->envelope(['events' => [$this->webError(['user_ref' => 'u_hmac:gone']), $this->webError(['user_ref' => 'u_hmac:keep', 'installation_ref' => 'inst_hmac:other'])], 'sessions' => [['sid' => 'a', 'init' => true, 'release' => '3.4.0', 'user_ref' => 'u_hmac:gone']]])->assertStatus(202);
        $this->h($sdk, 'json')->post('api/v1/bugtrace/reports', ['title' => 'x', 'severity' => 'MINOR', 'user_ref' => 'u_hmac:gone', 'attachments' => [['kind' => 'screenshot', 'upload_id' => $att['id']]]])->assertStatus(201);
        $key = db_connect()->table('bt_attachments')->get()->getRow('storage_key');

        // Without bugtrace.settings → 403.
        $dev = $this->staff(['developer']);
        $this->h($dev, 'json')->post('api/v1/admin/bugtrace/privacy/delete', ['user_ref' => 'u_hmac:gone', 'reason' => 'DSR-1'])->assertStatus(403);

        // Super admin with MFA enabled but no recent step-up → MFA_REQUIRED.
        $user   = $this->makeUser(['super_admin']);
        $secret = Totp::generateSecret();
        db_connect()->table('users')->where('id', $user['id'])->update(['mfa_enabled' => 1, 'mfa_totp_secret' => SecretStore::seal($secret)]);
        $h = $this->actingAs($user);
        db_connect()->table('user_sessions')->where('user_id', $user['id'])->update(['mfa_verified_at' => null]);
        $res = $this->h($h, 'json')->post('api/v1/admin/bugtrace/privacy/delete', ['user_ref' => 'u_hmac:gone', 'reason' => 'DSR-1']);
        $this->assertSame(401, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $this->assertSame('MFA_REQUIRED', $this->json($res)['error']['code']);
        $this->h($h + ['X-MFA-Token' => Totp::code($secret)], 'json')->post('api/v1/admin/bugtrace/privacy/delete', ['user_ref' => 'u_hmac:gone'])->assertStatus(422); // reason required

        $res = $this->h($h + ['X-MFA-Token' => Totp::code($secret)], 'json')->post('api/v1/admin/bugtrace/privacy/delete', ['user_ref' => 'u_hmac:gone', 'reason' => 'PDPA request DSR-1']);
        $this->assertSame(200, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $j = $this->json($res);
        $this->assertSame(['events' => 1, 'reports' => 1, 'attachments' => 1, 'sessions' => 1, 'issues_touched' => 1], $j['deleted']);

        $db = db_connect();
        $this->assertSame(0, $db->table('bt_events')->where('user_ref', 'u_hmac:gone')->countAllResults());
        $this->assertSame(1, $db->table('bt_events')->where('user_ref', 'u_hmac:keep')->countAllResults());
        $this->assertSame(0, $db->table('bt_reports')->countAllResults());
        $this->assertSame(0, $db->table('bt_attachments')->countAllResults());
        $this->assertSame(0, $db->table('bt_issue_users')->where('ref', 'u_hmac:gone')->countAllResults());
        $this->assertSame(1, (int) $db->table('bt_issues')->get()->getRow('users_count'));
        $this->expectBlobGone($key);
        $audit = $db->table('audit_logs')->where('action', 'bugtrace.privacy.deleted')->get()->getRowArray();
        $this->assertNotNull($audit);
        $this->assertSame('PDPA request DSR-1', $audit['reason']);
        $this->assertStringNotContainsString('u_hmac:gone', json_encode($audit));

        // Deletion by installation ref.
        $this->h($h + ['X-MFA-Token' => Totp::code($secret)], 'json')->post('api/v1/admin/bugtrace/privacy/delete', ['installation_ref' => 'inst_hmac:other', 'reason' => 'DSR-2'])->assertStatus(200);
        $this->assertSame(0, $db->table('bt_events')->countAllResults());
    }

    private function expectBlobGone(string $key): void
    {
        try {
            Vault::getBytes($key);
            $this->fail('attachment blob still exists');
        } catch (\Throwable $e) {
            $this->assertNotSame('attachment blob still exists', $e->getMessage());
        }
    }

    public function testSymbolsAreNeverPubliclyDownloadable(): void
    {
        $release = $this->release('server', '3.4.0', 34012);
        $map     = json_encode(SourceMapTest::mailMap());

        // Anonymous / non-CI callers cannot upload.
        $this->h(['Content-Type' => 'application/json'], '', $map)->post('api/v1/admin/bugtrace/symbols?release_id=' . $release['public_id'])->assertStatus(401);
        $viewer = $this->makeClient(['release.view']);
        $this->h($viewer + ['Content-Type' => 'application/json'], '', $map)->post('api/v1/admin/bugtrace/symbols?release_id=' . $release['public_id'])->assertStatus(403);

        $ci  = $this->makeClient(['bugtrace.symbols.upload']);
        $res = $this->h($ci + ['Content-Type' => 'application/json'], '', $map)->post('api/v1/admin/bugtrace/symbols?release_id=' . $release['public_id'] . '&file_name=mail-8c1.js.map');
        $this->assertSame(201, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $this->assertSame('mail-8c1.js', $this->json($res)['artifact']);
        $this->assertSame('85314830-023f-4cf1-a267-535f4e37bb17', $this->json($res)['debug_id']);
        $this->h($ci + ['Content-Type' => 'application/json'], '', '{"version":3')->post('api/v1/admin/bugtrace/symbols?release_id=' . $release['public_id'] . '&file_name=x.map')->assertStatus(422);

        $row = db_connect()->table('bt_symbols')->get()->getRowArray();
        $this->assertStringStartsWith('bugtrace/symbols/', $row['storage_key']);
        $raw = Vault::rawBytes($row['storage_key']);
        $this->assertStringNotContainsString('sourcesContent', $raw, 'stored encrypted');
        $this->assertStringNotContainsString('folderId', $raw);

        // Listing exposes metadata only.
        $list = $this->json($this->h($ci)->get('api/v1/admin/bugtrace/symbols?release_id=' . $release['public_id']));
        $this->assertSame(1, $list['total']);
        $this->assertArrayNotHasKey('storage_key', $list['data'][0]);
        $this->assertStringNotContainsString('mappings', json_encode($list));

        // Even a correctly signed dev-CDN URL for the object is refused, and no public route serves it.
        $signer = new CdnUrlSigner(SecretStore::get(config('UpdateCenter')->cdnSecretRef) ?? hash('sha256', 'dev-cdn|' . SecretStore::appSecret()));
        $url    = $signer->sign(config('UpdateCenter')->cdnHost, '/' . $row['storage_key'], 300, 'x.map');
        $path   = ltrim((string) parse_url($url, PHP_URL_PATH), '/') . '?' . parse_url($url, PHP_URL_QUERY);
        $this->setCookie('uc_session', null);
        $status = $this->h()->get($path)->response()->getStatusCode();
        $this->assertContains($status, [403, 404]);
        foreach (['api/v1/bugtrace/symbols', 'api/v1/admin/bugtrace/symbols/1', 'api/v1/admin/bugtrace/symbols/sym_1/download'] as $p) {
            try {
                $code = $this->h()->get($p)->response()->getStatusCode();
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $code = 404; // no such route
            }
            $this->assertContains($code, [401, 403, 404, 405]);
        }
    }

    public function testRetentionPurgesOldData(): void
    {
        $this->envelope(['events' => [$this->webError()]])->assertStatus(202);
        $db = db_connect();
        $db->table('bt_events')->update(['timestamp' => gmdate('Y-m-d H:i:s', time() - 91 * 86400)]);
        $old = $this->json($this->h(['X-BT-Key' => self::WEB_KEY, 'Content-Type' => 'image/png'], '', self::PNG)->post('api/v1/bugtrace/attachments?kind=screenshot'));
        $db->table('bt_attachments')->where('public_id', $old['id'])->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $out = (new PrivacyService())->applyRetention();
        $this->assertSame(1, $out['events']);
        $this->assertSame(1, $out['attachments']);
        $this->assertSame(1, $db->table('bt_issues')->countAllResults(), 'issues are kept 13 months');
        $db->table('bt_issues')->update(['last_seen' => gmdate('Y-m-d H:i:s', time() - 400 * 86400)]);
        $this->assertSame(1, (new PrivacyService())->applyRetention()['issues']);
        $this->assertSame(0, $db->table('bt_issue_releases')->countAllResults());
    }
}

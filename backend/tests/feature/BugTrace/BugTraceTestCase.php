<?php

namespace Tests\Feature\BugTrace;

use App\Libraries\BugTrace\Vault;
use App\Libraries\Storage\CdnUrlSigner;
use App\Libraries\Storage\LocalReleaseProvider;
use App\Libraries\Semver;
use App\Libraries\Trust\TokenIssuer;
use App\Libraries\Trust\TrustChain;
use App\Libraries\Ulid;
use App\Services\BugTrace\EventProcessor;
use App\Services\NotificationService;
use Config\Services;
use Tests\Support\UcTestCase;

/**
 * Shared helpers for Bug Trace feature tests.
 */
abstract class BugTraceTestCase extends UcTestCase
{
    public const WEB_KEY     = 'pk_web_dev_0000000000000001';
    public const DESKTOP_KEY = 'pk_desktop_dev_00000000000001';
    public const SERVER_KEY  = 'pk_server_dev_000000000000001';

    protected SpyNotifier $notifier;

    private string $blobDir = '';

    /** @var array{0:string,1:string}|null */
    private ?array $root = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Encrypted blobs go to a throw-away directory, not the dev storage bucket.
        $this->blobDir = sys_get_temp_dir() . '/bt-blobs-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Vault::setProvider(new LocalReleaseProvider($this->blobDir, 'http://example.com/_dev-cdn', new CdnUrlSigner('test-cdn-secret')));
        EventProcessor::flushCache();
        $this->notifier = new SpyNotifier();
        Services::injectMock('notifier', $this->notifier);
        $this->root = TokenIssuer::keypair();
        Services::injectMock('trustChain', new TrustChain(['test-root' => $this->root[1]]));
    }

    protected function tearDown(): void
    {
        Vault::setProvider(null);
        if ($this->blobDir !== '' && is_dir($this->blobDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->blobDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->blobDir);
        }
        parent::tearDown();
    }

    /** Reset per-request state (FeatureTestTrait keeps headers/body/format between calls). */
    protected function h(array $headers = [], string $format = '', string $body = ''): static
    {
        $this->headers     = $headers;
        $this->bodyFormat  = $format;
        $this->requestBody = $body;

        return $this;
    }

    /** Insert a release row for an application (by slug). */
    protected function release(string $appSlug, string $version, int $build, string $status = 'PUBLISHED', string $channel = 'STABLE', ?string $publishedAt = null): array
    {
        $db    = db_connect();
        $appId = (int) $db->table('applications')->where('slug', $appSlug)->get()->getRow('id');
        $chId  = (int) $db->table('release_channels')->where('code', $channel)->where('application_id', null)->get()->getRow('id');
        $p     = Semver::parse($version);
        $row   = [
            'public_id' => Ulid::prefixed('rel'), 'application_id' => $appId, 'channel_id' => $chId, 'version' => $version,
            'version_major' => $p['major'], 'version_minor' => $p['minor'], 'version_patch' => $p['patch'], 'version_pre' => $p['pre'],
            'version_sort' => Semver::sortKey($version), 'build_number' => $build, 'status' => $status,
            'published_at' => $status === 'PUBLISHED' || $status === 'SUPERSEDED' ? ($publishedAt ?? gmdate('Y-m-d H:i:s')) : null,
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $db->table('releases')->insert($row);

        return $db->table('releases')->where('id', $db->insertID())->get()->getRowArray();
    }

    /** A Tenant Policy Token with an optional `bugtrace` claim, verifiable by the injected trust chain. */
    protected function tpt(?array $bugtrace, string $tenant = 'tnt_01TEST', string $edition = 'BUSINESS'): string
    {
        [$instSk, $instPk] = TokenIssuer::keypair();
        [$polSk, $polPk]   = TokenIssuer::keypair();
        $inst  = TokenIssuer::cert('INSTALLATION', 'inst-key-1', $instPk, 'test-root', $this->root[0], ['installation_id' => 'inst_01TEST', 'edition' => $edition]);
        $pol   = TokenIssuer::cert('TENANT_POLICY', 'tpol-key-1', $polPk, 'inst-key-1', $instSk, ['installation_id' => 'inst_01TEST', 'tenant_id' => $tenant]);
        $payload = ['tenant_id' => $tenant, 'org_id' => 12, 'device_ref' => 'hmac:6f1c', 'app_id' => 'lk.worksuite.desktop', 'channel' => 'stable'];
        if ($bugtrace !== null) {
            $payload['bugtrace'] = $bugtrace;
        }

        return TokenIssuer::encode(TokenIssuer::tpt($payload, 'tpol-key-1', $polSk, [$pol, $inst]));
    }

    /** POST an envelope (gzip by default). */
    protected function envelope(array $body, string $key = self::WEB_KEY, ?string $tpt = null, bool $gzip = true): \CodeIgniter\Test\TestResponse
    {
        $raw     = json_encode($body);
        $headers = ['X-BT-Key' => $key, 'Content-Type' => 'application/json'];
        if ($gzip) {
            $raw                         = gzencode($raw);
            $headers['Content-Encoding'] = 'gzip';
        }
        if ($tpt !== null) {
            $headers['X-WorkSuite-Tenant-Policy'] = $tpt;
        }

        return $this->h($headers, '', $raw)->post('api/v1/bugtrace/envelope');
    }

    protected function webError(array $o = []): array
    {
        return array_replace_recursive([
            'event_id'    => str_replace('_', '', Ulid::generate()),
            'type'        => 'crash',
            'app'         => 'lk.worksuite.web',
            'platform'    => 'web',
            'release'     => '3.4.0+34012',
            'environment' => 'production',
            'installation_ref' => 'inst_hmac:aa01',
            'user_ref'    => 'u_hmac:3a9f01',
            'session_id'  => 'sess-1',
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
            'exception'   => [[
                'type'       => 'TypeError',
                'value'      => "Cannot read properties of undefined (reading 'folderId')",
                'stacktrace' => ['frames' => [
                    ['file' => 'https://mail.tenant.lk/assets/mail-8c1.js', 'line' => 1, 'col' => 40101, 'function' => 'e', 'in_app' => true],
                    ['file' => 'https://mail.tenant.lk/assets/mail-8c1.js', 'line' => 1, 'col' => 40211, 'function' => 'r', 'in_app' => true],
                ]],
            ]],
            'contexts'    => ['os' => ['name' => 'Windows', 'version' => '11'], 'browser' => ['name' => 'Chrome', 'version' => '129'], 'app' => ['area' => 'Mail', 'route' => '/app/mail/:folder']],
            'breadcrumbs' => [['t' => '2026-09-27T09:41:02Z', 'category' => 'navigation', 'data' => ['to' => '/app/mail/inbox']]],
            'tags'        => ['update_channel' => 'stable', 'locale' => 'si-LK'],
        ], $o);
    }

    protected function staff(array $roles = ['super_admin']): array
    {
        return $this->actingAs($this->makeUser($roles));
    }
}

/** Records notifications instead of queueing them. */
final class SpyNotifier extends NotificationService
{
    /** @var list<array{kind:string, payload:array, users:?array, email:bool}> */
    public array $notifications = [];

    /** @var list<array{to:string, subject:string, body:string}> */
    public array $emails = [];

    public function notify(string $kind, array $payload, ?array $userIds = null, bool $email = true): int
    {
        $this->notifications[] = ['kind' => $kind, 'payload' => $payload, 'users' => $userIds, 'email' => $email];

        return 1;
    }

    public function email(string $to, string $subject, string $body): void
    {
        $this->emails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }

    public function kinds(): array
    {
        return array_column($this->notifications, 'kind');
    }
}

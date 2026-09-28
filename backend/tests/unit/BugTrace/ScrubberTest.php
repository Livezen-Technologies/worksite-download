<?php

namespace Tests\Unit\BugTrace;

use App\Libraries\BugTrace\Scrubber;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * AC-C71 / NFR-C20: seeded e-mails, tokens, passwords, NIC and card numbers
 * never survive scrubbing.
 */
final class ScrubberTest extends CIUnitTestCase
{
    /** Secrets and PII seeded into events; none may survive. */
    public const SEEDS = [
        'kasun.perera@example.lk',
        'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U',
        'S3cr3tPassw0rd!',
        'hunter2hunter2',
        '901234567V',
        '199012345678',
        '4111111111111111',
        '5500 0000 0000 0004',
        'GB82WEST12345698765432',
        '+94 77 123 4567',
        '0771234567',
        '192.168.10.20',
        '2001:db8:85a3::8a2e:370:7334',
        'sk_live_51HxYzAbCdEfGhIjKlMnOp',
        'AKIAIOSFODNN7EXAMPLE',
        'abcDEF123ghiJKL456mnoPQR789stuVWX0',
        'sess=deadbeefcafe',
        'Bearer abc.def.ghi-token-value',
        'ws_tenant_secret_value',
    ];

    public static function seededEvent(): array
    {
        return [
            'event_id'  => '01J8ZSCRUBTEST0000000000001',
            'type'      => 'error',
            'release'   => '3.4.0+34012',
            'user_ref'  => 'u_hmac:3a9f00',
            'message'   => 'Login failed for kasun.perera@example.lk with password=S3cr3tPassw0rd! from 192.168.10.20',
            'exception' => [[
                'type'       => 'TypeError',
                'value'      => "Cannot read properties of undefined (reading 'folderId') token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U",
                'stacktrace' => ['frames' => [[
                    'file' => 'https://mail.tenant.lk/assets/mail-8c1.js?session=deadbeef&email=kasun.perera@example.lk', 'line' => 1, 'col' => 40211, 'function' => 'r', 'in_app' => true,
                    'vars' => ['password' => 'hunter2hunter2'],
                ]]],
            ]],
            'contexts' => [
                'app'    => ['area' => 'Mail', 'route' => '/app/mail/:folder', 'url' => 'https://mail.tenant.lk/app/mail/88/messages/3f2a8c1e-1b2c-4d5e-8f90-123456789abc?q=salary'],
                'os'     => ['name' => 'Windows', 'version' => '10.0.19045'],
                'user'   => ['nic' => '901234567V', 'note' => 'new NIC 199012345678, card 4111111111111111 / 5500 0000 0000 0004'],
                'bank'   => ['iban' => 'GB82WEST12345698765432', 'text' => 'Pay to IBAN GB82WEST12345698765432 or call +94 77 123 4567 / 0771234567'],
                'server' => ['client_ip' => '2001:db8:85a3::8a2e:370:7334', 'note' => 'upstream 2001:db8:85a3::8a2e:370:7334'],
                'keys'   => ['note' => 'stripe sk_live_51HxYzAbCdEfGhIjKlMnOp aws AKIAIOSFODNN7EXAMPLE opaque abcDEF123ghiJKL456mnoPQR789stuVWX0'],
            ],
            'request' => [
                'method'  => 'GET',
                'url'     => 'https://mail.tenant.lk/api/mail/folders/88?token=abc',
                'headers' => ['Authorization' => 'Bearer abc.def.ghi-token-value', 'Cookie' => 'sess=deadbeefcafe', 'X-WS-Tenant' => 'ws_tenant_secret_value', 'User-Agent' => 'Mozilla/5.0'],
                'data'    => ['subject' => 'Salary review', 'body' => 'confidential'],
            ],
            'user'        => ['email' => 'kasun.perera@example.lk', 'ip_address' => '192.168.10.20'],
            'breadcrumbs' => [
                ['t' => '2026-09-27T09:41:02Z', 'category' => 'navigation', 'data' => ['from' => '/app/mail/inbox', 'to' => '/app/mail/folders/88']],
                ['t' => '2026-09-27T09:41:03Z', 'category' => 'http', 'data' => ['method' => 'GET', 'path' => '/api/mail/folders/88/messages?page=2', 'status' => 200, 'ms' => 184]],
                ['t' => '2026-09-27T09:41:09Z', 'category' => 'ui.input', 'data' => ['value' => 'hunter2hunter2', 'label' => 'Password']],
            ],
            'tags' => ['locale' => 'si-LK', 'update_channel' => 'stable', 'mail' => 'kasun.perera@example.lk'],
        ];
    }

    public function testSeededSecretsNeverSurvive(): void
    {
        $s   = new Scrubber();
        $out = $s->scrubEvent(self::seededEvent());
        $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach (self::SEEDS as $seed) {
            $this->assertStringNotContainsString($seed, $json, "Seed leaked: {$seed}");
        }
        $this->assertStringNotContainsString('salary', strtolower($json));
        $this->assertStringNotContainsString('confidential', $json);
        $this->assertGreaterThanOrEqual(15, $s->count());
        $summary = $s->summary();
        foreach (['email', 'token', 'password', 'nic', 'card', 'iban', 'phone', 'ip', 'query'] as $cat) {
            $this->assertArrayHasKey($cat, $summary, "category {$cat} counted");
        }
    }

    public function testUsefulContextIsKept(): void
    {
        $out = (new Scrubber())->scrubEvent(self::seededEvent());
        $this->assertSame('3.4.0+34012', $out['release']);
        $this->assertSame('u_hmac:3a9f00', $out['user_ref']);
        $this->assertSame('10.0.19045', $out['contexts']['os']['version']);
        $this->assertSame('/app/mail/:folder', $out['contexts']['app']['route']);
        $this->assertSame('https://mail.tenant.lk/app/mail/:id/messages/:uuid', $out['contexts']['app']['url']);
        $this->assertStringContainsString("Cannot read properties of undefined (reading 'folderId')", $out['exception'][0]['value']);
        $this->assertSame('https://mail.tenant.lk/assets/mail-8c1.js', $out['exception'][0]['stacktrace']['frames'][0]['file']);
        $this->assertArrayNotHasKey('vars', $out['exception'][0]['stacktrace']['frames'][0]);
        $this->assertSame('/api/mail/folders/:id/messages', $out['breadcrumbs'][1]['data']['path']);
        $this->assertSame('/app/mail/folders/:id', $out['breadcrumbs'][0]['data']['to']);
        $this->assertArrayNotHasKey('value', $out['breadcrumbs'][2]['data']);
        $this->assertSame('Password', $out['breadcrumbs'][2]['data']['label']);
        $this->assertSame('[Filtered]', $out['contexts']['request']['headers']['Authorization']);
        $this->assertSame('Mozilla/5.0', $out['contexts']['request']['headers']['User-Agent']);
        $this->assertSame('https://mail.tenant.lk/api/mail/folders/:id', $out['contexts']['request']['url']);
        $this->assertSame('si-LK', $out['tags']['locale']);
    }

    public function testPatternsIndividually(): void
    {
        $s = new Scrubber();
        $this->assertSame('mail [Filtered] now', $s->scrubString('mail a.b+c@x.co.uk now'));
        $this->assertSame('card [Filtered] end', $s->scrubString('card 4242 4242 4242 4242 end'));
        $this->assertSame('not a card 4242 4242 4242 4241', $s->scrubString('not a card 4242 4242 4242 4241')); // Luhn fails
        $this->assertSame('build 1727430070123', $s->scrubString('build 1727430070123'));                       // starts with 1: not a card
        $this->assertSame('NIC [Filtered] and [Filtered]', $s->scrubString('NIC 853400937v and 200012345678'));
        $this->assertSame('call [Filtered]', $s->scrubString('call +94771234567'));
        $this->assertSame('call [Filtered]', $s->scrubString('call 011 234 5678'));
        $this->assertSame('version 2.7.0 and 10.0.19045.1 ok', $s->scrubString('version 2.7.0 and 10.0.19045.1 ok'));
        $this->assertSame('ip [Filtered] ok', $s->scrubString('ip 10.1.2.3 ok'));
        $this->assertSame('at 09:41:10 std::vector ok', $s->scrubString('at 09:41:10 std::vector ok'));
        $this->assertSame('password: [Filtered]', $s->scrubString('password: hunter2'));
        $this->assertSame('Authorization: Bearer [Filtered]', $s->scrubString('Authorization: Bearer abcdef123456'));
        $this->assertSame('account no: [Filtered]', $s->scrubString('account no: 1234 5678 9012'));
        $this->assertSame('https://x.lk/a', $s->scrubString('https://x.lk/a?token=1&b=2'));
        $this->assertSame('IBAN GB82 WEST 1234 5698 7654 33 not valid', $s->scrubString('IBAN GB82 WEST 1234 5698 7654 33 not valid'));
    }

    public function testCustomRules(): void
    {
        $s = new Scrubber([['pattern' => 'EMP-\d{5}', 'replacement' => '[EMP]'], ['pattern' => '/order #\d+/i', 'replacement' => null]]);
        $this->assertSame('employee [EMP] placed [Filtered]', $s->scrubString('employee EMP-12345 placed Order #991'));
        $this->assertSame(['custom' => 2], $s->summary());
    }

    public function testHelpers(): void
    {
        $this->assertTrue(Scrubber::luhn('4111111111111111'));
        $this->assertFalse(Scrubber::luhn('4111111111111112'));
        $this->assertTrue(Scrubber::ibanValid('GB82 WEST 1234 5698 7654 32'));
        $this->assertFalse(Scrubber::ibanValid('GB82 WEST 1234 5698 7654 33'));
        $this->assertSame('/api/users/:id/files/:uuid/v/:id', Scrubber::templatePath('/api/users/42/files/3f2a8c1e-1b2c-4d5e-8f90-123456789abc/v/01J8ZABCDEFGHJKMNPQRSTVWXY'));
        $this->assertSame('/api/mail/folders', Scrubber::templatePath('/api/mail/folders'));
    }
}

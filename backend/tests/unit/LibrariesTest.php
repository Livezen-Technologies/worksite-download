<?php

namespace Tests\Unit;

use App\Libraries\Scanner\BinaryInspector;
use App\Libraries\Scanner\ServerPackageValidator;
use App\Libraries\Semver;
use App\Libraries\Storage\CdnUrlSigner;
use App\Libraries\Storage\S3CompatibleReleaseProvider;
use App\Services\AuthService;
use App\Services\ChangelogService;
use App\Services\RolloutService;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Fixtures;

final class LibrariesTest extends CIUnitTestCase
{
    public function testSemverOrdering(): void
    {
        $this->assertTrue(Semver::lt('2.6.0-beta.2', '2.6.0'));
        $this->assertTrue(Semver::lt('2.6.0-beta.2', '2.6.0-beta.10'));
        $this->assertTrue(Semver::lt('2.6.0-alpha', '2.6.0-beta'));
        $this->assertSame(0, Semver::compare('v2.7.0', '2.7.0'));
        $this->assertSame('2.8.0', Semver::max('2.7.0', null, '2.8.0', '2.5.0'));
        $this->assertTrue(Semver::satisfies('2.7.3', '2.6.0 - 2.8.x'));
        $this->assertFalse(Semver::satisfies('2.9.0', '2.6.0 - 2.8.x'));
        $this->assertTrue(Semver::satisfies('2.3.9', '<2.4.0'));
        $this->assertTrue(Semver::satisfies('3.4.1', '3.4.x'));
        $this->assertSame('2.6.0', Semver::normalize('2.6'));
        $this->assertLessThan(Semver::sortKey('2.10.0'), Semver::sortKey('2.9.0'));
        $this->assertLessThan(Semver::sortKey('2.9.0'), Semver::sortKey('2.9.0-rc.1'));
    }

    public function testCohortIsDeterministicAndProportional(): void
    {
        $this->assertSame(RolloutService::cohortBucket('rel_A', 'hmac:1'), RolloutService::cohortBucket('rel_A', 'hmac:1'));
        $in = 0;
        for ($i = 0; $i < 10000; $i++) {
            $in += RolloutService::inCohort('rel_X', "hmac:{$i}", 25) ? 1 : 0;
        }
        $this->assertEqualsWithDelta(2500, $in, 200);
        $this->assertTrue(RolloutService::inCohort('rel_X', null, 100));
        $this->assertFalse(RolloutService::inCohort('rel_X', null, 50));
    }

    public function testRolloutRules(): void
    {
        $ctx = ['tenant' => 'tnt_1', 'org' => '12', 'platform' => 'windows', 'arch' => 'x64', 'current_version' => '2.6.5'];
        $this->assertTrue(RolloutService::matchesRules([], $ctx));
        $this->assertTrue(RolloutService::matchesRules(['platform' => ['windows', 'macos'], 'version_range' => ['<2.7.0']], $ctx));
        $this->assertFalse(RolloutService::matchesRules(['tenant' => ['tnt_2']], $ctx));
    }

    public function testBinaryInspector(): void
    {
        $this->assertSame(['x64'], BinaryInspector::inspect(Fixtures::pe('x64'))['architectures']);
        $this->assertSame(['arm64'], BinaryInspector::inspect(Fixtures::pe('arm64'))['architectures']);
        $this->assertSame('appimage', BinaryInspector::inspect(Fixtures::appImage())['format']);
        $this->assertSame(['arm64'], BinaryInspector::inspect(Fixtures::machO('arm64'))['architectures']);
        $dmg = Fixtures::dmg();
        $this->assertSame('dmg', BinaryInspector::inspect(substr($dmg, 0, 1024), substr($dmg, -512))['format']);
        $this->assertFalse(BinaryInspector::matches(BinaryInspector::inspect(Fixtures::pe('arm64')), 'exe', 'x64')['ok']);
        $this->assertFalse(BinaryInspector::matches(BinaryInspector::inspect(Fixtures::appImage()), 'exe', 'x64')['ok']);
        $this->assertTrue(BinaryInspector::matches(BinaryInspector::inspect(Fixtures::pe('x64')), 'exe', 'x64')['ok']);
        // An NSIS installer is a 32-bit x86 stub whatever it installs: accepted as an exe in x64 and arm64 slots…
        $this->assertSame(['x86'], BinaryInspector::inspect(Fixtures::pe('x86'))['architectures']);
        $this->assertTrue(BinaryInspector::matches(BinaryInspector::inspect(Fixtures::pe('x86')), 'exe', 'x64')['ok']);
        $this->assertTrue(BinaryInspector::matches(BinaryInspector::inspect(Fixtures::pe('x86')), 'exe', 'arm64')['ok']);
        // …but not as an msi, and a 64-bit PE for the other CPU is still refused.
        $this->assertFalse(BinaryInspector::matches(BinaryInspector::inspect(Fixtures::pe('x64')), 'exe', 'arm64')['ok']);
    }

    private const MODULES = ['FREE' => ['core', 'mail', 'chat'], 'BUSINESS' => ['crm', 'hr'], 'ENTERPRISE' => ['payroll', 'fleet']];

    private function validate(string $edition, array $modules, array $extra = [], array $over = [], ?callable $mutate = null): array
    {
        $path = sys_get_temp_dir() . '/uc-zip-' . bin2hex(random_bytes(4)) . '.zip';
        Fixtures::serverZip($path, '3.4.0', 34012, $edition, $modules, $extra, $over, $mutate);
        try {
            return (new ServerPackageValidator())->validate($path, ['version' => '3.4.0', 'build' => 34012, 'channel' => 'STABLE', 'edition' => $edition], self::MODULES);
        } finally {
            unlink($path);
        }
    }

    public function testServerZipHappyPath(): void
    {
        $r = $this->validate('BUSINESS', ['core', 'mail', 'crm']);
        $this->assertTrue($r['ok'], json_encode($r['checks']));
        $this->assertSame('business', $r['package_json']['edition']);
    }

    public function testServerZipRejectsForbiddenAndUnsafeContent(): void
    {
        $this->assertSame('FORBIDDEN_PATH', $this->validate('FREE', ['core'], ['.env' => 'DB_PASSWORD=x'])['reject_code']);
        $this->assertSame('FORBIDDEN_PATH', $this->validate('FREE', ['core'], ['writable/cache/x' => 'y'])['reject_code']);
        $this->assertSame('ZIP_UNSAFE_ENTRY', $this->validate('FREE', ['core'], [], [], static fn (\ZipArchive $z) => $z->addFromString('../../etc/passwd', 'x'))['reject_code']);
        $symlink = $this->validate('FREE', ['core'], [], [], static function (\ZipArchive $z) {
            $z->addFromString('public/link', '/etc/shadow');
            $z->setExternalAttributesName('public/link', \ZipArchive::OPSYS_UNIX, (0120777 << 16));
        });
        $this->assertSame('ZIP_UNSAFE_ENTRY', $symlink['reject_code']);
    }

    public function testServerZipRejectsEditionViolationAndHashMismatch(): void
    {
        // AC-C44: Business package containing Enterprise-only modules.
        $this->assertSame('EDITION_CONTENT_VIOLATION', $this->validate('BUSINESS', ['core', 'crm', 'payroll'])['reject_code']);
        $this->assertSame('METADATA_MISMATCH', $this->validate('FREE', ['core'], [], ['version' => '3.4.1'])['reject_code']);
        $tampered = $this->validate('FREE', ['core'], [], [], static fn (\ZipArchive $z) => $z->addFromString('spark', 'tampered'));
        $this->assertSame('FILE_HASH_MISMATCH', $tampered['reject_code']);
        $this->assertSame('POST_UPDATE_NOT_ALLOWED', $this->validate('FREE', ['core'], [], ['post_update' => ['db:wipe']])['reject_code']);
    }

    public function testCdnUrlSigner(): void
    {
        $s   = new CdnUrlSigner('secret');
        $url = $s->sign('https://cdn-updates.worksuitecloud.com', 'desktop/stable/2.7.0/windows/x64/WorkSuite Setup.exe', 900, 'WorkSuite Setup.exe', 1000);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertTrue($s->verify('/desktop/stable/2.7.0/windows/x64/WorkSuite Setup.exe', (int) $q['exp'], $q['fn'], $q['sig'], 1500));
        $this->assertFalse($s->verify('/desktop/stable/2.7.0/windows/x64/WorkSuite Setup.exe', (int) $q['exp'], $q['fn'], $q['sig'], 2000));
        $this->assertFalse($s->verify('/desktop/stable/2.7.0/windows/x64/other.exe', (int) $q['exp'], $q['fn'], $q['sig'], 1500));
    }

    public function testS3PresignShape(): void
    {
        $p   = new S3CompatibleReleaseProvider('https://acc.r2.cloudflarestorage.com', 'worksuite-releases', 'auto', 'AKID', 'SECRET', '');
        $url = $p->presign('GET', 'desktop/stable/2.7.0/a.exe', 900, [], 1_700_000_000);
        $this->assertStringStartsWith('https://acc.r2.cloudflarestorage.com/worksuite-releases/desktop/stable/2.7.0/a.exe?', $url);
        $this->assertMatchesRegularExpression('/X-Amz-Signature=[a-f0-9]{64}$/', $url);
        $this->assertStringContainsString('X-Amz-Credential=AKID%2F20231114%2Fauto%2Fs3%2Faws4_request', $url);
        $this->assertSame($url, $p->presign('GET', 'desktop/stable/2.7.0/a.exe', 900, [], 1_700_000_000));
    }

    public function testChangelogMarkdownParsing(): void
    {
        $s = ChangelogService::parseMarkdown("## New Features\n- A\n- B\n\n### Fixed\n* C\n\n## Unknown\n- ignored");
        $this->assertSame(['A', 'B'], $s['new_features']);
        $this->assertSame(['C'], $s['bug_fixes']);
        $this->assertStringContainsString('## New Features', ChangelogService::toMarkdown($s));
    }

    public function testAppVersionReadsVersionFile(): void
    {
        $this->assertSame(trim((string) file_get_contents(ROOTPATH . '../VERSION')), \App\Libraries\AppVersion::current());
        $this->assertTrue(Semver::isValid(\App\Libraries\AppVersion::current()));
    }

    public function testIpAllowList(): void
    {
        $this->assertTrue(AuthService::ipAllowed('10.1.2.3', ['10.0.0.0/8']));
        $this->assertFalse(AuthService::ipAllowed('11.1.2.3', ['10.0.0.0/8']));
        $this->assertTrue(AuthService::ipAllowed('2001:db8::1', ['2001:db8::/32']));
        $this->assertTrue(AuthService::ipAllowed('192.168.1.5', ['192.168.1.5']));
    }
}

<?php

namespace Tests\Unit\BugTrace;

use App\Libraries\BugTrace\ReleaseLinker;
use App\Services\BugTrace\EventProcessor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * AC-C68 / FR-BT-ING-06: an issue resolved in version X that recurs in a
 * version ≥ X regresses; older versions still in the field do not.
 */
final class RegressionTest extends CIUnitTestCase
{
    private static function issue(array $o): array
    {
        return $o + ['status' => 'RESOLVED', 'resolution' => 'VERSION', 'resolve_version' => null, 'resolved_last_release' => null, 'resolved_at' => '2026-09-27 10:00:00'];
    }

    public function testResolvedInVersion(): void
    {
        $i = self::issue(['resolve_version' => '3.4.1']);
        $this->assertFalse(EventProcessor::isRegression($i, '3.4.0+34012'));
        $this->assertTrue(EventProcessor::isRegression($i, '3.4.1+34101'));
        $this->assertTrue(EventProcessor::isRegression($i, 'lk.worksuite.web@3.5.0'));
        $this->assertFalse(EventProcessor::isRegression($i, null));
        $this->assertFalse(EventProcessor::isRegression($i, '3.4.1-beta.2'), 'pre-release of X is older than X');
    }

    public function testResolvedInNextReleaseBeforePublication(): void
    {
        $i = self::issue(['resolution' => 'NEXT_RELEASE', 'resolved_last_release' => '3.4.0+34012']);
        $this->assertFalse(EventProcessor::isRegression($i, '3.4.0+34013'));
        $this->assertTrue(EventProcessor::isRegression($i, '3.4.1+34101'));
        // After publication the hook sets resolve_version.
        $this->assertTrue(EventProcessor::isRegression(['resolve_version' => '3.4.1'] + $i, '3.4.1'));
    }

    public function testResolvedNow(): void
    {
        $i = self::issue(['resolution' => 'NOW', 'resolved_last_release' => '3.4.0']);
        $this->assertFalse(EventProcessor::isRegression($i, '3.3.9', '2026-09-27 11:00:00'));
        $this->assertTrue(EventProcessor::isRegression($i, '3.4.0', '2026-09-27 11:00:00'));
        $this->assertFalse(EventProcessor::isRegression($i, '3.4.0', '2026-09-27 09:00:00'), 'events from before the resolution never regress');
        $this->assertTrue(EventProcessor::isRegression(self::issue(['resolution' => 'NOW']), null, '2026-09-27 10:00:01'));
    }

    public function testIgnoreConditions(): void
    {
        $base = ['status' => 'IGNORED', 'events_count' => 10, 'users_count' => 2];
        $this->assertFalse(EventProcessor::ignoreConditionMet($base + ['ignore_until' => null], '1.0.0'));
        $this->assertTrue(EventProcessor::ignoreConditionMet($base + ['ignore_until' => json_encode(['count' => 5, 'base_events' => 6])], '1.0.0'));
        $this->assertFalse(EventProcessor::ignoreConditionMet($base + ['ignore_until' => json_encode(['count' => 50, 'base_events' => 6])], '1.0.0'));
        $this->assertTrue(EventProcessor::ignoreConditionMet($base + ['ignore_until' => json_encode(['until' => '2000-01-01T00:00:00Z'])], '1.0.0'));
        $this->assertTrue(EventProcessor::ignoreConditionMet($base + ['ignore_until' => json_encode(['next_release' => true, 'base_release' => '2.7.0+27001'])], '2.7.1+27101'));
        $this->assertFalse(EventProcessor::ignoreConditionMet($base + ['ignore_until' => json_encode(['next_release' => true, 'base_release' => '2.7.0+27001'])], '2.7.0+27002'));
    }

    public function testReleaseParsing(): void
    {
        $this->assertSame(['version' => '2.7.0', 'build' => 27001], ReleaseLinker::parse('2.7.0+27001'));
        $this->assertSame(['version' => '2.7.0', 'build' => 27001], ReleaseLinker::parse('lk.worksuite.desktop@2.7.0+27001'));
        $this->assertSame(['version' => '3.4.0', 'build' => 34012], ReleaseLinker::parse('3.4.0 (34012)'));
        $this->assertSame(['version' => '3.4.0-beta.1', 'build' => null], ReleaseLinker::parse('v3.4.0-beta.1'));
        $this->assertSame(['version' => null, 'build' => null], ReleaseLinker::parse('dev'));
        $this->assertSame(-1, ReleaseLinker::compare('2.7.0+2', '2.10.0'));
    }
}

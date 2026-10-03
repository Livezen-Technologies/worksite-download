<?php

namespace Tests\Feature;

use App\Libraries\Metrics;
use App\Libraries\SecretStore;
use Tests\Support\UcTestCase;

/**
 * §13.4 monitoring: Prometheus exporter (FR-UC-OPS-05).
 */
final class MetricsTest extends UcTestCase
{
    protected function tearDown(): void
    {
        SecretStore::override('UC_METRICS_TOKEN', null);
        parent::tearDown();
    }

    public function testDisabledWithoutTokenAndRequiresBearer(): void
    {
        SecretStore::override('UC_METRICS_TOKEN', '');
        $this->assertSame(404, $this->get('metrics')->response()->getStatusCode());
        SecretStore::override('UC_METRICS_TOKEN', 'scrape-secret');
        $this->assertSame(401, $this->get('metrics')->response()->getStatusCode());
        $this->assertSame(401, $this->withHeaders(['Authorization' => 'Bearer wrong'])->get('metrics')->response()->getStatusCode());
    }

    public function testExposesRequestAndOperationalSignals(): void
    {
        Metrics::reset();
        $this->get('api/v1/site')->assertStatus(200);
        Metrics::record('update_check', 503, 0.3);
        Metrics::count('signing_refused');
        db_connect()->table('jobs')->insert(['queue' => 'default', 'job' => 'x', 'payload' => '{}', 'attempts' => 0, 'max_attempts' => 3, 'available_at' => gmdate('Y-m-d H:i:s', time() - 900)]);

        SecretStore::override('UC_METRICS_TOKEN', 'scrape-secret');
        $res  = $this->withHeaders(['Authorization' => 'Bearer scrape-secret'])->get('metrics');
        $res->assertStatus(200);
        $body = (string) $res->response()->getBody();

        $this->assertStringContainsString('uc_http_requests_total{group="public_api",code="2xx"} 1', $body);
        $this->assertStringContainsString('uc_http_requests_total{group="update_check",code="5xx"} 1', $body);
        $this->assertStringContainsString('uc_http_request_duration_seconds_bucket{group="update_check",le="0.4"} 1', $body);
        $this->assertStringContainsString('uc_http_request_duration_seconds_bucket{group="update_check",le="0.2"} 0', $body);
        $this->assertStringContainsString('uc_events_total{event="signing_refused"} 1', $body);
        $this->assertStringContainsString('uc_queue_backlog 1', $body);
        $this->assertMatchesRegularExpression('/uc_queue_oldest_age_seconds (9\d\d|1\d{3})/', $body);
        $this->assertStringContainsString('uc_build_info{version="', $body);
        // Every sample belongs to a declared metric family.
        foreach (explode("\n", trim($body)) as $line) {
            $this->assertMatchesRegularExpression('/^(# (HELP|TYPE) uc_\w+ .+|uc_\w+(\{[^}]*\})? -?[\d.e+]+)$/', $line);
        }
    }

    public function testRouteGroups(): void
    {
        $this->assertSame('update_check', Metrics::group('/api/v1/desktop/update'));
        $this->assertSame('manifest', Metrics::group('/api/v1/apps/desktop/channels/stable/manifest'));
        $this->assertSame('admin', Metrics::group('/api/v1/admin/releases/r1/manifest'));
        $this->assertSame('resolver', Metrics::group('/download/desktop/latest/windows'));
        $this->assertSame('telemetry', Metrics::group('/api/v1/server/update-events'));
    }
}

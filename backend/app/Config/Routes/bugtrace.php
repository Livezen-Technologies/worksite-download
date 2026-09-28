<?php

/**
 * Bug Trace (§18.12).
 *
 * @var CodeIgniter\Router\RouteCollection $routes
 */

// Public SDK ingest — per-project key (X-BT-Key) + optional TPT; CORS, no cookies needed.
$routes->group('api/v1/bugtrace', ['namespace' => 'App\Controllers\BugTrace'], static function ($routes) {
    $routes->options('(:any)', 'IngestController::preflight');
    $routes->post('envelope', 'IngestController::envelope', ['filter' => 'rl:ingest']);
    $routes->post('minidump', 'IngestController::minidump', ['filter' => 'rl:ingest']);
    $routes->post('attachments', 'IngestController::attachments', ['filter' => 'rl:ingest']);
    $routes->put('attachments/(:segment)/upload', 'IngestController::upload/$1', ['filter' => 'rl:ingest']);
    $routes->post('reports/public', 'IngestController::publicReport', ['filter' => ['auth:optional', 'rl:report']]);
    $routes->post('reports', 'IngestController::reports', ['filter' => ['auth:optional', 'rl:ingest']]);
    $routes->get('reports/(:segment)/status', 'IngestController::reportStatus/$1', ['filter' => 'rl:public']);
    $routes->get('policy', 'IngestController::policy', ['filter' => 'rl:public']);
});

// Admin / CI API.
$routes->group('api/v1/admin/bugtrace', ['namespace' => 'App\Controllers\BugTrace', 'filter' => ['auth:staff', 'rl:admin']], static function ($routes) {
    // Issues (B1/B2)
    $routes->get('issues', 'IssuesController::index', ['filter' => 'perm:bugtrace.view']);
    $routes->post('issues/bulk', 'IssuesController::bulk', ['filter' => 'perm:bugtrace.triage']);
    $routes->get('issues/(:segment)/events', 'IssuesController::events/$1', ['filter' => 'perm:bugtrace.view']);
    $routes->post('issues/(:segment)/merge', 'IssuesController::merge/$1', ['filter' => 'perm:bugtrace.triage']);
    $routes->post('issues/(:segment)/split', 'IssuesController::split/$1', ['filter' => 'perm:bugtrace.triage']);
    $routes->post('issues/(:segment)/comments', 'IssuesController::comment/$1', ['filter' => 'perm:bugtrace.triage']);
    $routes->post('issues/(:segment)/links', 'IssuesController::link/$1', ['filter' => 'perm:bugtrace.triage']);
    $routes->get('issues/(:segment)', 'IssuesController::show/$1', ['filter' => 'perm:bugtrace.view']);
    $routes->patch('issues/(:segment)', 'IssuesController::update/$1', ['filter' => 'perm:bugtrace.triage']);

    // User reports inbox (B3)
    $routes->get('reports', 'ReportsController::index', ['filter' => 'perm:bugtrace.triage']);
    $routes->post('reports/(:segment)/reply', 'ReportsController::reply/$1', ['filter' => 'perm:bugtrace.triage']);
    $routes->post('reports/(:segment)/create-issue', 'ReportsController::createIssue/$1', ['filter' => 'perm:bugtrace.triage']);
    $routes->get('reports/(:segment)', 'ReportsController::show/$1', ['filter' => 'perm:bugtrace.triage']);
    $routes->patch('reports/(:segment)', 'ReportsController::update/$1', ['filter' => 'perm:bugtrace.triage']);

    // Attachments: short-lived signed URLs, staff with bugtrace.triage only
    $routes->get('attachments/(:segment)/url', 'AttachmentsController::url/$1', ['filter' => 'perm:bugtrace.triage']);
    $routes->get('attachments/(:segment)/download', 'AttachmentsController::download/$1', ['filter' => 'perm:bugtrace.triage']);

    // Release health (B5) — bugtrace.view or CI with release.view
    $routes->get('health/gate-rules', 'HealthController::rules', ['filter' => 'perm:bugtrace.view,release.view']);
    $routes->put('health/gate-rules', 'HealthController::saveRules', ['filter' => 'perm:bugtrace.manage']);
    $routes->get('health', 'HealthController::index', ['filter' => 'perm:bugtrace.view,release.view']);

    // Symbols (CI) — upload only; content is never served
    $routes->post('symbols', 'SymbolsController::upload', ['filter' => 'perm:bugtrace.symbols.upload']);
    $routes->get('symbols', 'SymbolsController::index', ['filter' => 'perm:bugtrace.view,bugtrace.symbols.upload']);

    // Alerts
    $routes->get('alerts', 'SettingsController::alerts', ['filter' => 'perm:bugtrace.view']);
    $routes->post('alerts', 'SettingsController::createAlert', ['filter' => 'perm:bugtrace.manage']);
    $routes->get('alerts/(:segment)', 'SettingsController::showAlert/$1', ['filter' => 'perm:bugtrace.view']);
    $routes->patch('alerts/(:segment)', 'SettingsController::updateAlert/$1', ['filter' => 'perm:bugtrace.manage']);
    $routes->delete('alerts/(:segment)', 'SettingsController::deleteAlert/$1', ['filter' => 'perm:bugtrace.manage']);

    // Projects
    $routes->get('projects', 'SettingsController::projects', ['filter' => 'perm:bugtrace.view']);
    $routes->patch('projects/(:segment)', 'SettingsController::updateProject/$1', ['filter' => 'perm:bugtrace.manage']);

    // Tenant policies, privacy, scrubbing
    $routes->get('policies', 'SettingsController::policies', ['filter' => 'perm:bugtrace.settings,bugtrace.tenant.view']);
    $routes->put('policies/(:segment)', 'SettingsController::putPolicy/$1', ['filter' => 'perm:bugtrace.settings']);
    $routes->post('privacy/delete', 'SettingsController::privacyDelete', ['filter' => 'perm:bugtrace.settings']);
    $routes->post('privacy/export', 'SettingsController::privacyExport', ['filter' => 'perm:bugtrace.settings']);
    $routes->get('retention', 'SettingsController::retention', ['filter' => 'perm:bugtrace.settings']);
    $routes->put('retention', 'SettingsController::saveRetention', ['filter' => 'perm:bugtrace.settings']);
    $routes->get('scrub-rules', 'SettingsController::scrubRules', ['filter' => 'perm:bugtrace.settings']);
    $routes->post('scrub-rules', 'SettingsController::createScrubRule', ['filter' => 'perm:bugtrace.settings']);
    $routes->delete('scrub-rules/(:num)', 'SettingsController::deleteScrubRule/$1', ['filter' => 'perm:bugtrace.settings']);
    $routes->get('fingerprint-rules', 'SettingsController::fingerprintRules', ['filter' => 'perm:bugtrace.view']);
    $routes->post('fingerprint-rules', 'SettingsController::createFingerprintRule', ['filter' => 'perm:bugtrace.manage']);
    $routes->delete('fingerprint-rules/(:num)', 'SettingsController::deleteFingerprintRule/$1', ['filter' => 'perm:bugtrace.manage']);
});

// Portal "My bug reports" (FR-BT-REP-07).
$routes->get('api/v1/portal/bug-reports', 'PortalReportsController::index', ['namespace' => 'App\Controllers\BugTrace', 'filter' => ['auth:user', 'rl:admin']]);

<?php

/** @var CodeIgniter\Router\RouteCollection $routes */

// WorkSuite Server installations (§17.10) — authorised by the installation key
// (`Authorization: Installation <key>`, WS-CORE-SYSUPDATE §10) or the installation license token.
$routes->group('api/v1/server', ['namespace' => 'App\Controllers\Server', 'filter' => 'rl:update'], static function ($routes) {
    $routes->get('update', 'ServerController::update');
    $routes->post('downloads/grant', 'ServerController::grant');
    $routes->post('installations/report', 'ServerController::report');
    $routes->post('update-events', 'ServerController::events');
    $routes->post('advisories/ack', 'ServerController::ack');
    // WS-CORE-SYSUPDATE-SRS-001 §10 (new)
    $routes->post('installations/register', 'ServerController::register');
    // Licences: activate with a key, revalidate with the installation key
    $routes->post('licenses/activate', 'ServerController::activateLicense');
    $routes->post('licenses/validate', 'ServerController::validateLicense');
    $routes->post('licenses/inspect', 'ServerController::inspectLicense');
    $routes->post('licenses/deactivate', 'ServerController::deactivateLicense');
    // Offline activation: licence key + request file (.wsr) in, signed licence file (.wsl) out
    $routes->post('licenses/offline', 'ServerController::offlineLicense');
    // The one-command installer's download, by licence key (IMPL-LIC-01 §7)
    $routes->post('install/package', 'ServerController::installPackage');
    // Signed list of revoked licences, voided installations and revoked signing keys (IMPL-LIC-01 §4.3)
    $routes->get('revocations/latest', 'ServerController::revocations');
    $routes->get('auto-update/plan', 'ServerController::plan');
    $routes->get('auto-update/policy', 'ServerController::getPolicy');
    $routes->put('auto-update/policy', 'ServerController::putPolicy');
});

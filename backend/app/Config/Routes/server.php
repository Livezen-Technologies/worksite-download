<?php

/** @var CodeIgniter\Router\RouteCollection $routes */

// WorkSuite Server installations (§17.10) — authorised by installation license token.
$routes->group('api/v1/server', ['namespace' => 'App\Controllers\Server', 'filter' => 'rl:update'], static function ($routes) {
    $routes->get('update', 'ServerController::update');
    $routes->post('downloads/grant', 'ServerController::grant');
    $routes->post('installations/report', 'ServerController::report');
    $routes->post('update-events', 'ServerController::events');
    $routes->post('advisories/ack', 'ServerController::ack');
});

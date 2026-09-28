<?php

/** @var CodeIgniter\Router\RouteCollection $routes */

// Desktop client + tenant endpoints (§10.1, §2.3–2.7).
$routes->group('api/v1', ['namespace' => 'App\Controllers\Desktop'], static function ($routes) {
    $routes->get('desktop/update', 'DesktopController::update', ['filter' => ['auth:optional', 'rl:update']]);
    $routes->post('downloads/grant', 'DesktopController::grant', ['filter' => 'rl:update']);
    $routes->post('update-events', 'DesktopController::events', ['filter' => 'rl:events']);
    $routes->post('device-versions', 'DesktopController::heartbeat', ['filter' => 'rl:events']);
    $routes->get('releases/(:segment)/manifest', 'DesktopController::releaseManifest/$1', ['filter' => ['auth:optional', 'rl:manifest']]);
    $routes->get('releases/(:segment)/state', 'DesktopController::releaseState/$1', ['filter' => 'rl:manifest']);
    $routes->get('apps/(:segment)/channels/(:segment)/manifest', 'DesktopController::channelManifest/$1/$2', ['filter' => ['auth:optional', 'rl:manifest']]);
    $routes->get('catalog/changes', 'DesktopController::catalogChanges', ['filter' => ['auth:client', 'rl:admin']]);
});

// Resumable uploads (tus 1.0, FR-UC-UPL-01).
$routes->group('api/v1/uploads', ['namespace' => 'App\Controllers\Admin', 'filter' => ['auth:staff', 'perm:release.upload,release.file.upload,release.file.replace']], static function ($routes) {
    $routes->options('tus', 'TusController::options');
    $routes->post('tus', 'TusController::create');
    $routes->options('tus/(:segment)', 'TusController::options');
    $routes->head('tus/(:segment)', 'TusController::head/$1');
    $routes->get('tus/(:segment)', 'TusController::head/$1');
    $routes->patch('tus/(:segment)', 'TusController::patch/$1');
    $routes->delete('tus/(:segment)', 'TusController::terminate/$1');
});

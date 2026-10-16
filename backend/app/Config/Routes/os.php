<?php

/** @var CodeIgniter\Router\RouteCollection $routes */

// WorkSuite OS (§18). Public reads: integrity comes from signatures, not transport.
$routes->group('', ['namespace' => 'App\Controllers\Os', 'filter' => ['auth:optional', 'rl:manifest']], static function ($routes) {
    $routes->get('os/manifests/(:segment)/(:segment)', 'OsController::manifest/$1/$2');
    $routes->get('desktop/v1/releases/manifest', 'OsController::desktopManifest');
    $routes->get('os/apt/(:any)', 'OsController::apt/$1');
    $routes->get('os/download/(:segment)/(:segment)/(:segment)', 'OsController::download/$1/$2/$3');
    $routes->get('api/v1/os/releases', 'OsController::releases');
    $routes->get('api/v1/os/keys', 'OsController::keys');
});

// CI publishing (API client token with os.publish; UPDATE_CENTER_TOKEN in the OS repo).
$routes->group('api/v1/os', ['namespace' => 'App\Controllers\Os', 'filter' => ['auth', 'perm:os.publish', 'rl:admin']], static function ($routes) {
    $routes->put('artifacts/(:segment)/(:segment)/(:segment)', 'OsController::putArtifact/$1/$2/$3');
    $routes->put('apt', 'OsController::putApt');
    $routes->post('releases', 'OsController::publish');
});

// Release management: pause / resume / pull / rollout / promote / emergency.
$routes->group('api/v1/admin/os', ['namespace' => 'App\Controllers\Os', 'filter' => ['auth:staff', 'rl:admin']], static function ($routes) {
    $routes->get('releases', 'OsController::list', ['filter' => 'perm:release.view']);
    $routes->post('releases/(:segment)/(:segment)/(:segment)', 'OsController::action/$1/$2/$3', ['filter' => 'perm:release.publish']);
    $routes->post('emergency', 'OsController::emergency', ['filter' => 'perm:release.publish']);
});

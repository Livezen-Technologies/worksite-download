<?php

/** @var CodeIgniter\Router\RouteCollection $routes */
$routes->group('api/v1/portal', ['namespace' => 'App\Controllers\Portal', 'filter' => ['auth:user', 'rl:admin']], static function ($routes) {
    $routes->get('downloads', 'PortalController::downloads');
    $routes->get('history', 'PortalController::history');
    $routes->put('profile', 'PortalController::profile');
    $routes->post('beta', 'PortalController::beta');
    $routes->get('subscriptions', 'PortalController::subscriptions');
    $routes->put('subscriptions', 'PortalController::saveSubscriptions');
});

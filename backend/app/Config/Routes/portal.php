<?php

/** @var CodeIgniter\Router\RouteCollection $routes */
$routes->group('api/v1/portal', ['namespace' => 'App\Controllers\Portal', 'filter' => ['auth:user', 'rl:admin']], static function ($routes) {
    $routes->get('downloads', 'PortalController::downloads');
    $routes->get('history', 'PortalController::history');
    $routes->put('profile', 'PortalController::profile');
    $routes->post('beta', 'PortalController::beta');
    $routes->get('subscriptions', 'PortalController::subscriptions');
    $routes->put('subscriptions', 'PortalController::saveSubscriptions');
    // My licences (WorkSuite Server) — only those whose contact email is the signed-in, verified user's
    $routes->get('licenses', 'LicensesController::index');
    $routes->post('licenses/trial', 'LicensesController::trial');
    $routes->get('licenses/(:segment)', 'LicensesController::show/$1');
    $routes->post('licenses/(:segment)/reveal', 'LicensesController::reveal/$1');
    $routes->post('licenses/(:segment)/replace-key', 'LicensesController::replaceKey/$1');
    $routes->post('licenses/(:segment)/activations/(:num)/deactivate', 'LicensesController::deactivate/$1/$2');
    $routes->post('licenses/(:segment)/offline', 'LicensesController::offline/$1');
    $routes->get('licenses/(:segment)/edition-preview', 'LicensesController::editionPreview/$1');
});

<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 *
 * Route groups live in app/Config/Routes/*.php so modules stay independent.
 */
$routes->setAutoRoute(false);

foreach (['public', 'auth', 'desktop', 'admin', 'portal', 'server', 'bugtrace', 'os'] as $group) {
    $file = __DIR__ . '/Routes/' . $group . '.php';
    if (is_file($file)) {
        require $file;
    }
}

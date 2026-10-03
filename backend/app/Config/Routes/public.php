<?php

/** @var CodeIgniter\Router\RouteCollection $routes */

// Public catalog API (§10.1).
$routes->group('api/v1', ['namespace' => 'App\Controllers\PublicSite', 'filter' => ['auth:optional', 'rl:public']], static function ($routes) {
    $routes->get('site', 'CatalogController::site');
    $routes->get('version', 'CatalogController::version');
    $routes->get('apps', 'CatalogController::apps');
    $routes->get('apps/(:segment)/releases/(:segment)', 'CatalogController::release/$1/$2');
    $routes->get('apps/(:segment)/releases', 'CatalogController::releases/$1');
    $routes->get('apps/(:segment)/versions', 'CatalogController::versions/$1');
    $routes->get('apps/(:segment)/changelog', 'CatalogController::appChangelog/$1');
    $routes->get('changelog', 'CatalogController::changelog');
    $routes->get('channels', 'CatalogController::channels');
    $routes->post('subscriptions', 'CatalogController::subscribe');
    $routes->get('subscriptions/confirm', 'CatalogController::confirmSubscription');
    $routes->get('subscriptions/unsubscribe', 'CatalogController::unsubscribe');
    $routes->get('keys', 'CatalogController::keys');
});
$routes->get('api/v1/apps/(:segment)', 'PublicSite\CatalogController::app/$1', ['filter' => ['auth:optional', 'rl:public']]);

// Web routes served by the API container (resolver, feeds, SEO).
$routes->group('', ['namespace' => 'App\Controllers\PublicSite', 'filter' => 'auth:optional'], static function ($routes) {
    $routes->get('download/(:any)', 'WebController::download/$1', ['filter' => 'rl:resolver']);
    $routes->get('apps/(:segment)/releases/(:segment)/checksums.txt', 'WebController::checksums/$1/$2');
    $routes->get('changelog/feed', 'WebController::rss');
    $routes->get('changelog/atom', 'WebController::atom');
    $routes->get('apps/(:segment)/changelog/feed', 'WebController::rss/$1');
    $routes->get('apps/(:segment)/changelog/atom', 'WebController::atom/$1');
    $routes->get('sitemap.xml', 'WebController::sitemap');
    $routes->get('robots.txt', 'WebController::robots');
    $routes->get('api-docs', 'WebController::apiDocs');
    $routes->get('api-docs/openapi.yaml', 'WebController::openapi');
    // Development-only CDN + presigned-part emulation for the LOCAL provider.
    $routes->get('_dev-cdn/(:any)', 'WebController::devCdn/$1');
    $routes->put('_dev-storage/part/(:segment)/(:num)', 'WebController::devPart/$1/$2');
});

// Prometheus scrape endpoint (§13.4) — bearer token, see MetricsController.
$routes->get('metrics', 'MetricsController::index');

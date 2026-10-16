<?php

/** @var CodeIgniter\Router\RouteCollection $routes */
$routes->group('api/v1/admin', ['namespace' => 'App\Controllers\Admin', 'filter' => ['auth:staff', 'rl:admin']], static function ($routes) {
    // Shell
    $routes->get('dashboard', 'DashboardController::index', ['filter' => 'perm:release.view']);
    $routes->get('notifications', 'DashboardController::notifications');
    $routes->post('notifications/read-all', 'DashboardController::readAll');
    $routes->post('notifications/(:num)/read', 'DashboardController::read/$1');
    $routes->get('health', 'DashboardController::health');
    $routes->post('content/preview', 'ApplicationsController::preview');

    // Applications, channels, platforms
    $routes->get('applications', 'ApplicationsController::index', ['filter' => 'perm:application.view']);
    $routes->post('applications', 'ApplicationsController::create', ['filter' => 'perm:application.manage']);
    $routes->get('applications/(:segment)/minimum-versions/impact', 'ApplicationsController::impact/$1', ['filter' => 'perm:minimum_version.manage,devices.view']);
    $routes->get('applications/(:segment)/minimum-versions', 'ApplicationsController::minimumVersions/$1', ['filter' => 'perm:application.view']);
    $routes->put('applications/(:segment)/minimum-versions', 'ApplicationsController::setMinimumVersion/$1', ['filter' => 'perm:minimum_version.manage']);
    $routes->get('applications/(:segment)/download-page', 'ApplicationsController::downloadPage/$1', ['filter' => 'perm:application.view']);
    $routes->put('applications/(:segment)/download-page', 'ApplicationsController::saveDownloadPage/$1', ['filter' => 'perm:content.manage']);
    $routes->post('applications/(:segment)/download-page/publish', 'ApplicationsController::publishDownloadPage/$1', ['filter' => 'perm:content.manage']);
    $routes->get('applications/(:segment)', 'ApplicationsController::show/$1', ['filter' => 'perm:application.view']);
    $routes->patch('applications/(:segment)', 'ApplicationsController::update/$1', ['filter' => 'perm:application.manage']);
    $routes->get('channels', 'ApplicationsController::channels', ['filter' => 'perm:application.view']);
    $routes->patch('channels/(:num)', 'ApplicationsController::updateChannel/$1', ['filter' => 'perm:application.manage']);
    $routes->get('platforms', 'ApplicationsController::platforms', ['filter' => 'perm:application.view']);

    // Releases
    $routes->get('releases', 'ReleasesController::index', ['filter' => 'perm:release.view']);
    $routes->post('releases', 'ReleasesController::create', ['filter' => 'perm:release.create']);
    $routes->get('releases/(:segment)/checklist', 'ReleasesController::checklist/$1', ['filter' => 'perm:release.view']);
    $routes->put('releases/(:segment)/changelog', 'ReleasesController::changelog/$1', ['filter' => 'perm:changelog.manage']);
    $routes->post('releases/(:segment)/submit-test', 'ReleasesController::submitTest/$1', ['filter' => 'perm:release.edit']);
    $routes->post('releases/(:segment)/start-testing', 'ReleasesController::startTesting/$1', ['filter' => 'perm:release.approve.qa,release.edit']);
    $routes->post('releases/(:segment)/qa', 'ReleasesController::qa/$1', ['filter' => 'perm:release.approve.qa']);
    $routes->post('releases/(:segment)/approve-qa', 'ReleasesController::qa/$1', ['filter' => 'perm:release.approve.qa']);
    $routes->post('releases/(:segment)/approve', 'ReleasesController::approve/$1', ['filter' => 'perm:release.approve']);
    $routes->get('releases/(:segment)/publish-summary', 'ReleasesController::publishSummary/$1', ['filter' => 'perm:release.view']);
    $routes->post('releases/(:segment)/publish', 'ReleasesController::publish/$1', ['filter' => 'perm:release.publish']);
    $routes->post('releases/(:segment)/pause', 'ReleasesController::pause/$1', ['filter' => 'perm:release.publish']);
    $routes->post('releases/(:segment)/resume', 'ReleasesController::resume/$1', ['filter' => 'perm:release.publish']);
    $routes->post('releases/(:segment)/withdraw', 'ReleasesController::withdraw/$1', ['filter' => 'perm:release.withdraw']);
    $routes->post('releases/(:segment)/supersede', 'ReleasesController::supersede/$1', ['filter' => 'perm:release.publish']);
    $routes->put('releases/(:segment)/policy', 'ReleasesController::policy/$1', ['filter' => 'perm:minimum_version.manage']);
    $routes->put('releases/(:segment)/support-state', 'ReleasesController::supportState/$1', ['filter' => 'perm:minimum_version.manage']);
    $routes->get('releases/(:segment)/preview', 'ReleasesController::preview/$1', ['filter' => 'perm:release.view']);
    $routes->get('releases/(:segment)/manifest', 'ReleasesController::manifest/$1', ['filter' => 'perm:release.view']);
    $routes->post('releases/(:segment)/manifest/regenerate', 'ReleasesController::regenerate/$1', ['filter' => 'perm:release.publish']);
    $routes->get('releases/(:segment)/rollout', 'ReleasesController::rollout/$1', ['filter' => 'perm:rollout.view']);
    $routes->put('releases/(:segment)/rollout', 'ReleasesController::configureRollout/$1', ['filter' => 'perm:rollout.manage']);
    $routes->post('releases/(:segment)/rollout/(:segment)', 'ReleasesController::rolloutAction/$1/$2', ['filter' => 'perm:rollout.manage']);
    $routes->get('releases/(:segment)/downloads', 'ReleasesController::downloads/$1', ['filter' => 'perm:downloads.view']);
    $routes->get('releases/(:segment)/devices', 'ReleasesController::devices/$1', ['filter' => 'perm:devices.view']);
    $routes->get('releases/(:segment)/activity', 'ReleasesController::activity/$1', ['filter' => 'perm:release.view']);
    $routes->post('releases/(:segment)/attestation', 'ReleasesController::attestation/$1', ['filter' => 'perm:release.upload']);
    $routes->post('releases/(:segment)/packages', 'ReleasesController::createPackage/$1', ['filter' => 'perm:release.upload,release.file.upload,release.file.replace']);
    $routes->post('releases/(:segment)/packages/(:segment)/complete', 'ReleasesController::completePackage/$1/$2', ['filter' => 'perm:release.upload,release.file.upload,release.file.replace']);
    $routes->post('releases/(:segment)/packages/(:segment)/replace', 'ReleasesController::replacePackage/$1/$2', ['filter' => 'perm:release.file.replace,release.file.upload']);
    $routes->get('releases/(:segment)/packages/(:segment)', 'ReleasesController::package/$1/$2', ['filter' => 'perm:release.view']);
    $routes->delete('releases/(:segment)/packages/(:segment)', 'ReleasesController::deletePackage/$1/$2', ['filter' => 'perm:release.file.delete']);
    $routes->get('releases/(:segment)/server-meta', 'ReleasesController::serverMeta/$1', ['filter' => 'perm:release.view']);
    $routes->put('releases/(:segment)/server-meta', 'ReleasesController::saveServerMeta/$1', ['filter' => 'perm:release.edit']);
    $routes->get('releases/(:segment)/campaign', 'ReleasesController::campaign/$1', ['filter' => 'perm:release.view']);
    $routes->put('releases/(:segment)/campaign', 'ReleasesController::saveCampaign/$1', ['filter' => 'perm:campaign.manage']);
    $routes->get('releases/(:segment)', 'ReleasesController::show/$1', ['filter' => 'perm:release.view']);
    $routes->patch('releases/(:segment)', 'ReleasesController::update/$1', ['filter' => 'perm:release.edit']);

    // Server release console (WS-CORE-SYSUPDATE-SRS-001 DL-1..DL-4)
    $routes->get('server/releases', 'ServerReleasesController::index', ['filter' => 'perm:release.view']);
    $routes->post('server/releases', 'ServerReleasesController::create', ['filter' => 'perm:release.create']);
    $routes->post('server/releases/(:segment)/publish', 'ServerReleasesController::publish/$1', ['filter' => 'perm:release.publish']);
    $routes->get('server/releases/(:segment)/rollout', 'ServerReleasesController::rollout/$1', ['filter' => 'perm:rollout.view']);
    $routes->post('server/releases/(:segment)/rollout/(pause|resume|extend)', 'ServerReleasesController::rolloutAction/$1/$2', ['filter' => 'perm:release.publish']);
    $routes->patch('server/releases/(:segment)', 'ServerReleasesController::update/$1', ['filter' => 'perm:release.edit']);
    $routes->put('server/installations/(:segment)/override', 'ServerReleasesController::override/$1', ['filter' => 'perm:fleet.manage']);

    // Server installations & managed fleet
    // WorkSuite Server licences (the Download Center is the licence authority)
    $routes->get('licenses', 'LicensesController::index', ['filter' => 'perm:license.view']);
    $routes->post('licenses', 'LicensesController::create', ['filter' => 'perm:license.manage']);
    $routes->get('license-risk', 'LicenseRiskController::index', ['filter' => 'perm:license.view']);
    $routes->post('license-risk/(:num)/(acknowledge|resolve|reopen)', 'LicenseRiskController::status/$1/$2', ['filter' => 'perm:license.manage']);
    $routes->get('entitlements', 'EntitlementsController::index', ['filter' => 'perm:license.view']);
    $routes->post('entitlements', 'EntitlementsController::define', ['filter' => 'perm:license.manage']);
    $routes->put('entitlements/editions/(:segment)', 'EntitlementsController::edition/$1', ['filter' => 'perm:license.manage']);
    $routes->get('licenses/(:segment)/entitlements', 'EntitlementsController::licenseShow/$1', ['filter' => 'perm:license.view']);
    $routes->put('licenses/(:segment)/entitlements', 'EntitlementsController::licenseUpdate/$1', ['filter' => 'perm:license.manage']);
    $routes->get('licenses/(:segment)', 'LicensesController::show/$1', ['filter' => 'perm:license.view']);
    $routes->patch('licenses/(:segment)', 'LicensesController::update/$1', ['filter' => 'perm:license.manage']);
    $routes->post('licenses/(:segment)/renew', 'LicensesController::renew/$1', ['filter' => 'perm:license.manage']);
    $routes->post('licenses/(:segment)/(suspend|resume|revoke)', 'LicensesController::status/$1/$2', ['filter' => 'perm:license.manage']);
    $routes->post('licenses/(:segment)/activations/(:num)/deactivate', 'LicensesController::deactivate/$1/$2', ['filter' => 'perm:license.manage']);
    $routes->get('installations/stats', 'InstallationsController::stats', ['filter' => 'perm:installations.view']);
    $routes->get('installations', 'InstallationsController::index', ['filter' => 'perm:installations.view']);
    $routes->get('fleet', 'InstallationsController::fleetIndex', ['filter' => 'perm:installations.view']);
    $routes->post('fleet/(:segment)/(:segment)', 'InstallationsController::fleet/$1/$2', ['filter' => 'perm:fleet.manage']);

    // Analytics
    $routes->get('analytics/downloads', 'AnalyticsController::downloads', ['filter' => 'perm:downloads.view']);
    $routes->get('analytics/devices', 'AnalyticsController::devices', ['filter' => 'perm:devices.view']);
    $routes->get('analytics/update-status', 'AnalyticsController::updateStatus', ['filter' => 'perm:devices.view']);
    $routes->get('rollouts', 'AnalyticsController::rollouts', ['filter' => 'perm:rollout.view']);

    // Administration
    $routes->get('users', 'AdministrationController::users', ['filter' => 'perm:user.manage']);
    $routes->post('users', 'AdministrationController::createUser', ['filter' => 'perm:user.manage']);
    $routes->patch('users/(:segment)', 'AdministrationController::updateUser/$1', ['filter' => 'perm:user.manage']);
    $routes->post('users/(:segment)/reset-mfa', 'AdministrationController::resetMfa/$1', ['filter' => 'perm:user.manage']);
    $routes->get('roles', 'AdministrationController::roles');
    $routes->get('api-clients', 'AdministrationController::apiClients', ['filter' => 'perm:api_client.manage']);
    $routes->post('api-clients', 'AdministrationController::createApiClient', ['filter' => 'perm:api_client.manage']);
    $routes->patch('api-clients/(:segment)', 'AdministrationController::updateApiClient/$1', ['filter' => 'perm:api_client.manage']);
    $routes->post('api-clients/(:segment)/rotate', 'AdministrationController::rotateApiClient/$1', ['filter' => 'perm:api_client.manage']);
    $routes->post('api-clients/(:segment)/revoke', 'AdministrationController::revokeApiClient/$1', ['filter' => 'perm:api_client.manage']);
    $routes->get('tenants', 'AdministrationController::tenants', ['filter' => 'perm:api_client.manage']);
    $routes->post('tenants', 'AdministrationController::createTenant', ['filter' => 'perm:api_client.manage']);
    $routes->post('tenants/(:segment)/webhooks', 'AdministrationController::addWebhook/$1', ['filter' => 'perm:api_client.manage']);
    $routes->get('storage', 'AdministrationController::storage', ['filter' => 'perm:storage.manage,release.publish']);
    $routes->post('storage', 'AdministrationController::addStorage', ['filter' => 'perm:storage.manage']);
    $routes->post('storage/probe', 'AdministrationController::probeStorage', ['filter' => 'perm:storage.manage,release.publish']);
    $routes->post('storage/(:num)/test', 'AdministrationController::testStorage/$1', ['filter' => 'perm:storage.manage']);
    $routes->post('storage/(:num)/activate', 'AdministrationController::activateStorage/$1', ['filter' => 'perm:storage.manage']);
    $routes->get('signing-keys', 'AdministrationController::signingKeys', ['filter' => 'perm:security.manage,signing_key.rotate']);
    $routes->post('signing-keys/rotate', 'AdministrationController::rotateKey', ['filter' => 'perm:signing_key.rotate']);
    $routes->get('audit/verify', 'AdministrationController::verifyAudit', ['filter' => 'perm:audit.view']);
    $routes->get('audit', 'AdministrationController::audit', ['filter' => 'perm:audit.view']);
    $routes->get('settings', 'AdministrationController::settings', ['filter' => 'perm:security.manage']);
    $routes->put('settings', 'AdministrationController::saveSettings', ['filter' => 'perm:security.manage']);
});

// CI aliases (§10.6): same controllers, bearer API clients.

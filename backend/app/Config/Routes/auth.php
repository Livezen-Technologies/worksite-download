<?php

/** @var CodeIgniter\Router\RouteCollection $routes */
$routes->group('api/v1/auth', ['namespace' => 'App\Controllers\Auth'], static function ($routes) {
    $routes->post('login', 'AuthController::login', ['filter' => 'rl:login']);
    $routes->post('mfa', 'AuthController::mfa', ['filter' => 'auth:mfa-pending']);
    $routes->post('passkey/options', 'AuthController::passkeyOptions', ['filter' => 'auth:mfa-pending']);
    $routes->post('passkey/verify', 'AuthController::passkeyVerify', ['filter' => 'auth:mfa-pending']);
    $routes->post('logout', 'AuthController::logout', ['filter' => 'auth:optional']);
    $routes->get('me', 'AuthController::me', ['filter' => 'auth']);
    $routes->post('step-up', 'AuthController::stepUp', ['filter' => 'auth']);
    $routes->post('mfa/totp/setup', 'AuthController::totpSetup', ['filter' => 'auth']);
    $routes->post('mfa/totp/confirm', 'AuthController::totpConfirm', ['filter' => 'auth']);
    $routes->post('passkeys/register/options', 'AuthController::passkeyRegisterOptions', ['filter' => 'auth']);
    $routes->post('passkeys/register', 'AuthController::passkeyRegister', ['filter' => 'auth']);
    $routes->post('password/forgot', 'AuthController::forgot', ['filter' => 'rl:login']);
    $routes->post('password/reset', 'AuthController::reset', ['filter' => 'rl:login']);
    $routes->post('register', 'AuthController::register', ['filter' => 'rl:login']);
    $routes->post('verify-email', 'AuthController::verifyEmail', ['filter' => 'rl:login']);
});

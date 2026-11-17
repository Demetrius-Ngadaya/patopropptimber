<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [
    // API tokens only (the Next.js server holds the token in an httpOnly cookie).
    'stateful' => [],

    'guard' => [],

    // No fixed lifetime from login: each token has its own expiry, which
    // EnforceSessionTimeout pushes forward while the person keeps working, and the
    // admin's inactivity timeout ends sessions that are left alone.
    'expiration' => env('SANCTUM_EXPIRATION') ? (int) env('SANCTUM_EXPIRATION') : null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'mzp_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];

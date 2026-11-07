<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [
    // API tokens only (the Next.js server holds the token in an httpOnly cookie).
    'stateful' => [],

    'guard' => [],

    // Absolute token lifetime in minutes (safety net). Idle timeout is enforced
    // separately by the EnforceSessionTimeout middleware using the admin setting.
    'expiration' => 60 * 24 * 7,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'mzp_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];

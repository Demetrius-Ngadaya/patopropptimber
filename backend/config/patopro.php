<?php

/*
|--------------------------------------------------------------------------
| How this copy of PatoPro runs
|--------------------------------------------------------------------------
| single   – one business on its own domain (PP Timber, Dablos, Metro…).
|            Nothing below "platform" is used. This is the default, so
|            existing installs behave exactly as before.
| platform – many businesses on patopro.shezoni.com, each with its own
|            database. The central database lists the businesses and the
|            packages (see app/Platform).
*/

return [
    'mode' => env('PATOPRO_MODE', 'single'),

    /*
    | Single mode: what this install may use. Set by the Software Engineer.
    | features: ['*'] for everything, or a list of feature keys
    |           (see App\Platform\Support\Features).
    | limits:   null or a number per key (users, branches, products,
    |           sms_per_month). null means no limit.
    */
    'single' => [
        'plan_name' => env('PATOPRO_PLAN_NAME', 'PatoPro'),
        'features' => array_filter(array_map('trim', explode(',', env('PATOPRO_FEATURES', '*')))),
        'limits' => [
            'users' => env('PATOPRO_LIMIT_USERS'),
            'branches' => env('PATOPRO_LIMIT_BRANCHES'),
            'products' => env('PATOPRO_LIMIT_PRODUCTS'),
            'sms_per_month' => env('PATOPRO_LIMIT_SMS'),
        ],
    ],

    'platform' => [
        // The central database: businesses, packages, payments, directory.
        'connection' => 'platform',
        'database' => [
            'host' => env('PLATFORM_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('PLATFORM_DB_PORT', env('DB_PORT', '3306')),
            'database' => env('PLATFORM_DB_DATABASE'),
            'username' => env('PLATFORM_DB_USERNAME', env('DB_USERNAME')),
            'password' => env('PLATFORM_DB_PASSWORD', env('DB_PASSWORD')),
        ],

        // Each business's own database. One MySQL user is granted all of them,
        // unless a business has its own user stored on its record.
        'business_database' => [
            'prefix' => env('PLATFORM_BUSINESS_DB_PREFIX', 'pp_'),
            'username' => env('PLATFORM_BUSINESS_DB_USERNAME', env('DB_USERNAME')),
            'password' => env('PLATFORM_BUSINESS_DB_PASSWORD', env('DB_PASSWORD')),
        ],

        // The web address of a business is patopro.shezoni.com/<slug>.
        'url' => rtrim((string) env('PLATFORM_URL', env('FRONTEND_URL', 'http://localhost:3000')), '/'),

        // Words that can never be a business address.
        'reserved_slugs' => [
            'admin', 'api', 'app', 'apps', 'auth', 'login', 'logout', 'signin', 'signup', 'register', 'jisajili', 'ingia',
            'pricing', 'bei', 'vifurushi', 'packages', 'help', 'msaada', 'support', 'docs', 'blog', 'about', 'contact',
            'terms', 'privacy', 'static', 'assets', 'storage', 'public', 'images', 'img', 'css', 'js', '_next', 'next',
            'shop', 'duka', 'platform', 'patopro', 'shezoni', 'www', 'mail', 'email', 'sms', 'status', 'health', 'up',
            'dashboard', 'account', 'billing', 'pay', 'lipa', 'payments', 'webhook', 'webhooks', 'j', 'join', 'qr',
            'download', 'install', 'manifest', 'robots', 'sitemap', 'favicon', 'sw', 'offline',
        ],

        // What happens around the end of a paid period (all in days).
        'subscription' => [
            'trial_days' => (int) env('PLATFORM_TRIAL_DAYS', 14),
            'grace_days' => (int) env('PLATFORM_GRACE_DAYS', 5),
            'keep_days' => (int) env('PLATFORM_KEEP_DAYS', 180),
            'warn_days' => [7, 3, 1, 0],
        ],
    ],
];

<?php

use App\Platform\Http\Controllers\BillingController;
use App\Platform\Http\Controllers\PlansController;
use App\Platform\Http\Controllers\PlatformWebhookController;
use App\Platform\Http\Controllers\SignupController;
use Illuminate\Support\Facades\Route;

/*
| The platform itself (patopro.shezoni.com), PATOPRO_MODE=platform only.
| No business is chosen for these (IdentifyBusiness lets platform/* through).
*/
Route::prefix('platform')->name('platform.')->group(function () {
    Route::get('/plans', [PlansController::class, 'index'])->middleware('throttle:public')->name('plans');

    Route::post('/signup/check-address', [SignupController::class, 'checkAddress'])->middleware('throttle:30,1')->name('signup.check');
    Route::post('/signup', [SignupController::class, 'start'])->middleware('throttle:10,60')->name('signup.start');
    Route::get('/signup/{signup}', [SignupController::class, 'show'])->middleware('throttle:60,1')->name('signup.show');
    Route::post('/signup/{signup}/resend', [SignupController::class, 'resend'])->middleware('throttle:10,60')->name('signup.resend');
    Route::post('/signup/{signup}/verify', [SignupController::class, 'verify'])->middleware('throttle:20,10')->name('signup.verify');

    // Gateways confirming package payments (each payment is checked with the gateway, not trusted).
    Route::post('/webhooks/azampay/{secret}', [PlatformWebhookController::class, 'azampay'])->middleware('throttle:120,1')->name('webhooks.azampay');
    Route::post('/webhooks/snippe', [PlatformWebhookController::class, 'snippe'])->middleware('throttle:120,1')->name('webhooks.snippe');

    // A business paying for its package: X-Business header and a staff sign-in. Allowed while read-only.
    Route::middleware(['auth:sanctum', 'staff'])->prefix('billing')->name('billing.')->group(function () {
        Route::get('/', [BillingController::class, 'show'])->middleware('permission:settings.view')->name('show');
        Route::post('/pay', [BillingController::class, 'pay'])->middleware(['permission:settings.update', 'throttle:10,1'])->name('pay');
        Route::get('/payments/{number}', [BillingController::class, 'payment'])->middleware('permission:settings.view')->name('payment');
    });
});

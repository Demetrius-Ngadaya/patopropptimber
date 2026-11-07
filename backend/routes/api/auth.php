<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Catalog\ProductCodeController;
use App\Http\Controllers\Api\V1\Core\LoginSlideController;
use App\Http\Controllers\Api\V1\Core\SettingController;
use App\Http\Controllers\Api\V1\Mobile\AppReleaseController;
use App\Http\Controllers\Api\V1\Shop\PublicDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:public')->group(function () {
    Route::get('/public/settings', [SettingController::class, 'public'])->name('public.settings');
    Route::get('/public/products/scan/{token}', [ProductCodeController::class, 'publicScan'])->where('token', '[A-Za-z0-9]{8,24}')->name('public.product-scan');
    // A customer's copy of a quotation / proforma / invoice (40-character link token).
    Route::get('/public/documents/{token}', [PublicDocumentController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->name('public.documents.show');
    Route::get('/public/documents/{token}/pdf', [PublicDocumentController::class, 'pdf'])->where('token', '[A-Za-z0-9]{40}')->name('public.documents.pdf');
    Route::post('/public/documents/{token}/respond', [PublicDocumentController::class, 'respond'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1')->name('public.documents.respond');
    Route::get('/public/login-slides', [LoginSlideController::class, 'public'])->name('public.login-slides');
    // The PatoPro Android app, downloaded from the system (it is not in an app store).
    Route::get('/public/app', [AppReleaseController::class, 'current'])->name('public.app');
    Route::get('/public/app/download', [AppReleaseController::class, 'download'])->middleware('throttle:20,1')->name('public.app.download');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot-password');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset-password');
});

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
Route::post('/auth/two-factor/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:two-factor')->name('auth.two-factor.verify');

Route::middleware(['auth:sanctum', 'session.timeout', 'activity', 'throttle:api'])->prefix('auth')->name('auth.')->group(function () {
    Route::get('/me', [AuthController::class, 'me'])->name('me');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/change-password', [AuthController::class, 'changePassword'])->name('change-password');
    Route::put('/profile', [AuthController::class, 'updateProfile'])->name('profile');
    Route::put('/preferences', [AuthController::class, 'updatePreferences'])->name('preferences');
    Route::get('/sessions', [AuthController::class, 'sessions'])->name('sessions');
    Route::delete('/sessions/{tokenId}', [AuthController::class, 'revokeSession'])->whereNumber('tokenId')->name('sessions.revoke');

    Route::middleware('throttle:10,1')->prefix('two-factor')->name('two-factor.')->group(function () {
        Route::post('/setup', [AuthController::class, 'twoFactorSetup'])->name('setup');
        Route::post('/confirm', [AuthController::class, 'twoFactorConfirm'])->name('confirm');
        Route::post('/disable', [AuthController::class, 'twoFactorDisable'])->name('disable');
        Route::post('/recovery-codes', [AuthController::class, 'twoFactorRecoveryCodes'])->name('recovery-codes');
    });
});

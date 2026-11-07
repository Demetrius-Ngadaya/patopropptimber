<?php

use App\Http\Controllers\Api\V1\Payments\OnlinePaymentController;
use Illuminate\Support\Facades\Route;

// Payments by phone (Snippe USSD push).
Route::prefix('online-payments')->name('online-payments.')->group(function () {
    Route::get('/status', [OnlinePaymentController::class, 'status'])->middleware('permission:settings.view|online_payments.view')->name('status');
    Route::get('/', [OnlinePaymentController::class, 'index'])->middleware('permission:online_payments.view')->name('index');
    Route::post('/', [OnlinePaymentController::class, 'store'])->middleware(['permission:online_payments.request', 'throttle:30,1'])->name('store');
    Route::get('/{id}', [OnlinePaymentController::class, 'show'])->middleware('permission:online_payments.request|online_payments.view')->whereNumber('id')->name('show');
    Route::post('/{id}/resend', [OnlinePaymentController::class, 'resend'])->middleware(['permission:online_payments.request', 'throttle:10,1'])->whereNumber('id')->name('resend');
    Route::post('/{id}/cancel', [OnlinePaymentController::class, 'cancel'])->middleware('permission:online_payments.request')->whereNumber('id')->name('cancel');
});

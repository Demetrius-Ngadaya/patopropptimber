<?php

use App\Platform\Http\Controllers\PlansController;
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
});

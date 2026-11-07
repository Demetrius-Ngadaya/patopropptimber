<?php

use App\Http\Controllers\Api\V1\Mobile\AppReleaseController;
use App\Http\Controllers\Api\V1\Mobile\MobileController;
use Illuminate\Support\Facades\Route;

// PatoPro mobile app (any signed-in staff member; each synced record and each
// replayed change checks its own permission).
Route::prefix('mobile')->name('mobile.')->group(function () {
    Route::get('/bootstrap', [MobileController::class, 'bootstrap'])->name('bootstrap');
    Route::post('/devices', [MobileController::class, 'registerDevice'])->middleware('throttle:30,1')->name('devices.register');
    Route::delete('/devices/{installationId}', [MobileController::class, 'forgetDevice'])->whereUuid('installationId')->name('devices.forget');
    Route::post('/devices/test', [MobileController::class, 'testNotification'])->middleware('throttle:6,1')->name('devices.test');
    Route::get('/sync/pull', [MobileController::class, 'pull'])->name('sync.pull');
    Route::post('/sync/push', [MobileController::class, 'push'])->middleware('throttle:60,1')->name('sync.push');
});

// Versions of the app offered for download (uploaded in small pieces so large APKs
// pass the web server and PHP upload limits).
Route::prefix('mobile/app-releases')->name('mobile.app-releases.')->middleware('permission:mobile_app.manage')->group(function () {
    Route::get('/', [AppReleaseController::class, 'index'])->name('index');
    Route::post('/uploads', [AppReleaseController::class, 'startUpload'])->name('uploads.start');
    Route::post('/uploads/{uploadId}/chunks', [AppReleaseController::class, 'uploadChunk'])->whereUuid('uploadId')->middleware('throttle:600,1')->name('uploads.chunk');
    Route::post('/', [AppReleaseController::class, 'store'])->name('store');
    Route::put('/{id}', [AppReleaseController::class, 'update'])->whereNumber('id')->name('update');
    Route::delete('/{id}', [AppReleaseController::class, 'destroy'])->whereNumber('id')->name('destroy');
});

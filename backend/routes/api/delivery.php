<?php

use App\Http\Controllers\Api\V1\Delivery\DeliveryController;
use Illuminate\Support\Facades\Route;

Route::prefix('deliveries')->name('deliveries.')->group(function () {
    $any = 'permission:deliveries.own|deliveries.view|deliveries.assign';
    Route::get('/', [DeliveryController::class, 'index'])->middleware($any)->name('index');
    Route::get('/couriers', [DeliveryController::class, 'couriers'])->middleware('permission:deliveries.assign')->name('couriers');
    Route::post('/quote', [DeliveryController::class, 'quote'])->middleware('permission:deliveries.assign')->name('quote');
    Route::post('/', [DeliveryController::class, 'store'])->middleware('permission:deliveries.assign')->name('store');
    Route::get('/{id}', [DeliveryController::class, 'show'])->middleware($any)->whereNumber('id')->name('show');
    Route::post('/{id}/start', [DeliveryController::class, 'start'])->middleware('permission:deliveries.own')->whereNumber('id')->name('start');
    Route::post('/{id}/location', [DeliveryController::class, 'location'])->middleware(['permission:deliveries.own', 'throttle:120,1'])->whereNumber('id')->name('location');
    Route::post('/{id}/discount', [DeliveryController::class, 'discount'])->middleware('permission:deliveries.own|deliveries.assign')->whereNumber('id')->name('discount');
    Route::post('/{id}/delivered', [DeliveryController::class, 'delivered'])->middleware('permission:deliveries.own|deliveries.assign')->whereNumber('id')->name('delivered');
    Route::post('/{id}/fail', [DeliveryController::class, 'fail'])->middleware('permission:deliveries.own|deliveries.assign')->whereNumber('id')->name('fail');
    Route::post('/{id}/cancel', [DeliveryController::class, 'cancel'])->middleware('permission:deliveries.assign')->whereNumber('id')->name('cancel');
    Route::get('/{id}/messages', [DeliveryController::class, 'messages'])->middleware($any)->whereNumber('id')->name('messages');
    Route::post('/{id}/messages', [DeliveryController::class, 'sendMessage'])->middleware([$any, 'throttle:60,1'])->whereNumber('id')->name('messages.send');
});

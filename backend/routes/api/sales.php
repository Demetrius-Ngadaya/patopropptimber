<?php

use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Sales\CreditSaleController;
use App\Http\Controllers\Api\V1\Sales\OrderController;
use App\Http\Controllers\Api\V1\Sales\PosController;
use App\Http\Controllers\Api\V1\Sales\SaleController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos')->name('pos.')->middleware('permission:pos.access')->group(function () {
    Route::get('/products', [PosController::class, 'products'])->name('products');
    Route::get('/lookup', [ProductController::class, 'lookup'])->name('lookup');
    Route::post('/quote', [PosController::class, 'quote'])->middleware('throttle:120,1')->name('quote');
    Route::post('/checkout', [PosController::class, 'checkout'])->name('checkout');
    Route::get('/held', [PosController::class, 'held'])->middleware('permission:pos.hold')->name('held');
    Route::post('/held', [PosController::class, 'hold'])->middleware('permission:pos.hold')->name('hold');
    Route::delete('/held/{id}', [PosController::class, 'discardHeld'])->middleware('permission:pos.hold')->whereNumber('id')->name('held.discard');
});

Route::prefix('sales')->name('sales.')->group(function () {
    Route::get('/', [SaleController::class, 'index'])->middleware('permission:sales.view')->name('index');
    Route::get('/{id}', [SaleController::class, 'show'])->middleware('permission:sales.view|sales.reprint|allocations.sell')->whereNumber('id')->name('show');
    Route::post('/{id}/cancel', [SaleController::class, 'cancel'])->middleware('permission:sales.cancel')->whereNumber('id')->name('cancel');
    Route::post('/{id}/returns', [SaleController::class, 'returnItems'])->middleware('permission:sales.return')->whereNumber('id')->name('return');
    Route::post('/{id}/send-receipt', [SaleController::class, 'sendReceipt'])->middleware('permission:sales.reprint')->whereNumber('id')->name('send-receipt');
    Route::post('/{id}/sms', [SaleController::class, 'sendReceipt'])->middleware('permission:sales.reprint')->whereNumber('id')->name('sms'); // older clients
});

Route::prefix('orders')->name('orders.')->group(function () {
    Route::get('/', [OrderController::class, 'index'])->middleware('permission:orders.view')->name('index');
    Route::get('/{id}', [OrderController::class, 'show'])->middleware('permission:orders.view')->whereNumber('id')->name('show');
    Route::post('/', [OrderController::class, 'store'])->middleware('permission:orders.create')->name('store');
    Route::post('/{id}/status', [OrderController::class, 'updateStatus'])->middleware('permission:orders.update_status')->whereNumber('id')->name('status');
    Route::post('/{id}/complete', [OrderController::class, 'complete'])->middleware('permission:orders.complete')->whereNumber('id')->name('complete');
});

// Credit sales (debts): full / partial repayments with complete history
Route::prefix('credit-sales')->name('credit-sales.')->group(function () {
    Route::get('/', [CreditSaleController::class, 'index'])->middleware('permission:credit.view')->name('index');
    Route::get('/{id}', [CreditSaleController::class, 'show'])->middleware('permission:credit.view')->whereNumber('id')->name('show');
    Route::post('/{id}/payments', [CreditSaleController::class, 'pay'])->middleware('permission:credit.receive_payment')->whereNumber('id')->name('pay');
    Route::put('/{id}/due-date', [CreditSaleController::class, 'reschedule'])->middleware('permission:credit.reschedule')->whereNumber('id')->name('reschedule');
});

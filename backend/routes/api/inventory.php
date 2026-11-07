<?php

use App\Http\Controllers\Api\V1\Inventory\AllocationController;
use App\Http\Controllers\Api\V1\Inventory\StockController;
use App\Http\Controllers\Api\V1\Inventory\TransferController;
use Illuminate\Support\Facades\Route;

Route::prefix('stock')->name('stock.')->group(function () {
    Route::get('/alerts', [StockController::class, 'alerts'])->middleware('permission:stock.alerts')->name('alerts');
    Route::get('/movements', [StockController::class, 'movements'])->middleware('permission:stock.movements')->name('movements');
    Route::get('/adjustments', [StockController::class, 'adjustments'])->middleware('permission:stock.view')->name('adjustments');
    Route::post('/adjustments', [StockController::class, 'adjust'])->middleware('permission:stock.adjust')->name('adjust');
});

Route::prefix('transfers')->name('transfers.')->group(function () {
    Route::get('/', [TransferController::class, 'index'])->middleware('permission:transfers.view')->name('index');
    Route::post('/', [TransferController::class, 'store'])->middleware('permission:transfers.create')->name('store');
    Route::get('/{id}', [TransferController::class, 'show'])->middleware('permission:transfers.view')->whereNumber('id')->name('show');
    Route::post('/{id}/receive', [TransferController::class, 'receive'])->middleware('permission:transfers.receive')->whereNumber('id')->name('receive');
    Route::post('/{id}/cancel', [TransferController::class, 'cancel'])->middleware('permission:transfers.cancel')->whereNumber('id')->name('cancel');
    Route::post('/{id}/mark-paid', [TransferController::class, 'markPaid'])->middleware('permission:transfers.mark_paid')->whereNumber('id')->name('mark-paid');
});

Route::prefix('allocations')->name('allocations.')->group(function () {
    Route::get('/', [AllocationController::class, 'index'])->middleware('permission:allocations.view|allocations.view_own')->name('index');
    Route::post('/', [AllocationController::class, 'store'])->middleware('permission:allocations.create')->name('store');
    Route::get('/{id}', [AllocationController::class, 'show'])->middleware('permission:allocations.view|allocations.view_own')->whereNumber('id')->name('show');
    Route::post('/{id}/sales', [AllocationController::class, 'sell'])->middleware('permission:allocations.sell')->whereNumber('id')->name('sell');
    Route::post('/{id}/reconcile', [AllocationController::class, 'reconcile'])->middleware('permission:allocations.reconcile')->whereNumber('id')->name('reconcile');
    Route::post('/{id}/cancel', [AllocationController::class, 'cancel'])->middleware('permission:allocations.cancel')->whereNumber('id')->name('cancel');
});

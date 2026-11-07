<?php

use App\Http\Controllers\Api\V1\Purchasing\PurchaseController;
use App\Http\Controllers\Api\V1\Purchasing\PurchasePlanController;
use App\Http\Controllers\Api\V1\Purchasing\SupplierController;
use Illuminate\Support\Facades\Route;

Route::crud('suppliers', SupplierController::class, 'suppliers');
Route::post('/suppliers/{id}/payments', [SupplierController::class, 'pay'])->middleware('permission:suppliers.pay')->whereNumber('id')->name('suppliers.pay');
Route::get('/suppliers/{id}/statement', [SupplierController::class, 'statement'])->middleware('permission:suppliers.statement')->whereNumber('id')->name('suppliers.statement');

Route::prefix('purchases')->name('purchases.')->group(function () {
    Route::get('/', [PurchaseController::class, 'index'])->middleware('permission:purchases.view')->name('index');
    Route::post('/', [PurchaseController::class, 'store'])->middleware('permission:purchases.create')->name('store');
    Route::get('/{id}', [PurchaseController::class, 'show'])->middleware('permission:purchases.view')->whereNumber('id')->name('show');
    Route::post('/{id}/returns', [PurchaseController::class, 'returnItems'])->middleware('permission:purchases.return')->whereNumber('id')->name('return');
});

Route::put('/purchases/{id}/reminder', [PurchaseController::class, 'reminder'])->middleware('permission:purchases.credit|suppliers.pay')->whereNumber('id')->name('purchases.reminder');

Route::prefix('purchase-plans')->name('purchase-plans.')->group(function () {
    Route::get('/', [PurchasePlanController::class, 'index'])->middleware('permission:purchase_plans.view')->name('index');
    Route::post('/', [PurchasePlanController::class, 'store'])->middleware('permission:purchase_plans.create')->name('store');
    Route::get('/{id}', [PurchasePlanController::class, 'show'])->middleware('permission:purchase_plans.view')->whereNumber('id')->name('show');
    Route::put('/{id}', [PurchasePlanController::class, 'update'])->middleware('permission:purchase_plans.create')->whereNumber('id')->name('update');
    Route::delete('/{id}', [PurchasePlanController::class, 'destroy'])->middleware('permission:purchase_plans.create')->whereNumber('id')->name('destroy');
    Route::post('/{id}/submit', [PurchasePlanController::class, 'submit'])->middleware('permission:purchase_plans.approve')->whereNumber('id')->name('submit');
    Route::post('/{id}/receive', [PurchasePlanController::class, 'receive'])->middleware('permission:purchase_plans.receive')->whereNumber('id')->name('receive');
    Route::post('/{id}/cancel', [PurchasePlanController::class, 'cancel'])->middleware('permission:purchase_plans.cancel')->whereNumber('id')->name('cancel');
    Route::post('/{id}/close', [PurchasePlanController::class, 'close'])->middleware('permission:purchase_plans.cancel|purchase_plans.receive')->whereNumber('id')->name('close');
});

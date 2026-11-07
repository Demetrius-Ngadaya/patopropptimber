<?php

use App\Http\Controllers\Api\V1\Crm\CustomerController;
use App\Http\Controllers\Api\V1\Crm\SavingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('customers')->name('customers.')->group(function () {
    Route::get('/', [CustomerController::class, 'index'])->middleware('permission:customers.view')->name('index');
    Route::post('/', [CustomerController::class, 'store'])->middleware('permission:customers.create')->name('store');
    Route::get('/{id}', [CustomerController::class, 'show'])->middleware('permission:customers.view')->whereNumber('id')->name('show');
    Route::put('/{id}', [CustomerController::class, 'update'])->middleware('permission:customers.update')->whereNumber('id')->name('update');
    Route::delete('/{id}', [CustomerController::class, 'destroy'])->middleware('permission:customers.delete')->whereNumber('id')->name('destroy');
    Route::post('/{id}/restore', [CustomerController::class, 'restore'])->middleware('permission:customers.restore')->whereNumber('id')->name('restore');
    Route::get('/{id}/financial-history', [CustomerController::class, 'financialHistory'])->middleware('permission:customers.statement|savings.view|credit.view')->whereNumber('id')->name('financial-history');
    Route::get('/{id}/statement', [CustomerController::class, 'statement'])->middleware('permission:customers.statement')->whereNumber('id')->name('statement');
    Route::post('/{id}/payments', [CustomerController::class, 'receivePayment'])->middleware('permission:sales.receive_payment')->whereNumber('id')->name('payments');
    Route::post('/{id}/remind', [CustomerController::class, 'remind'])->middleware('permission:customers.remind')->whereNumber('id')->name('remind');
    Route::put('/{id}/account', [CustomerController::class, 'account'])->middleware('permission:customers.manage_account')->whereNumber('id')->name('account');
});

// Customer savings ("akiba")
Route::prefix('savings')->name('savings.')->group(function () {
    Route::get('/', [SavingsController::class, 'index'])->middleware('permission:savings.view')->name('index');
    Route::post('/', [SavingsController::class, 'store'])->middleware('permission:savings.open')->name('store');
    Route::get('/transactions', [SavingsController::class, 'transactions'])->middleware('permission:savings.view')->name('transactions');
    Route::get('/{id}', [SavingsController::class, 'show'])->middleware('permission:savings.view')->whereNumber('id')->name('show');
    Route::post('/{id}/deposits', [SavingsController::class, 'deposit'])->middleware('permission:savings.deposit')->whereNumber('id')->name('deposit');
    Route::post('/{id}/withdrawals', [SavingsController::class, 'withdraw'])->middleware('permission:savings.withdraw')->whereNumber('id')->name('withdraw');
    Route::post('/{id}/transactions/{transaction}/reverse', [SavingsController::class, 'reverse'])->middleware('permission:savings.manage')->whereNumber(['id', 'transaction'])->name('reverse');
    Route::put('/{id}/status', [SavingsController::class, 'status'])->middleware('permission:savings.manage')->whereNumber('id')->name('status');
});

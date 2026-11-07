<?php

use App\Http\Controllers\Api\V1\Agency\AgencyController;
use Illuminate\Support\Facades\Route;

// Uwakala (mobile money and bank agency). An optional module: switched on in Settings.
Route::prefix('agency')->name('agency.')->group(function () {
    Route::get('/accounts', [AgencyController::class, 'accounts'])->middleware('permission:agency.view|agency.manage')->name('accounts');
    Route::post('/accounts', [AgencyController::class, 'storeAccount'])->middleware('permission:agency.manage')->name('accounts.store');
    Route::put('/accounts/{id}', [AgencyController::class, 'updateAccount'])->middleware('permission:agency.manage')->whereNumber('id')->name('accounts.update');
    Route::delete('/accounts/{id}', [AgencyController::class, 'destroyAccount'])->middleware('permission:agency.manage')->whereNumber('id')->name('accounts.destroy');

    Route::get('/today', [AgencyController::class, 'today'])->middleware('permission:agency.view')->name('today');
    Route::get('/summary', [AgencyController::class, 'summary'])->middleware('permission:agency.view')->name('summary');
    Route::get('/days', [AgencyController::class, 'days'])->middleware('permission:agency.view')->name('days');
    Route::post('/days', [AgencyController::class, 'open'])->middleware('permission:agency.record')->name('days.open');
    Route::get('/days/{id}', [AgencyController::class, 'showDay'])->middleware('permission:agency.view')->whereNumber('id')->name('days.show');
    Route::post('/days/{id}/close', [AgencyController::class, 'close'])->middleware('permission:agency.record')->whereNumber('id')->name('days.close');
    Route::put('/days/{id}', [AgencyController::class, 'correct'])->middleware('permission:agency.manage')->whereNumber('id')->name('days.correct');

    Route::get('/commissions', [AgencyController::class, 'commissions'])->middleware('permission:agency.view|agency.commissions')->name('commissions');
    Route::post('/commissions', [AgencyController::class, 'storeCommission'])->middleware('permission:agency.commissions')->name('commissions.store');
    Route::put('/commissions/{id}', [AgencyController::class, 'updateCommission'])->middleware('permission:agency.commissions')->whereNumber('id')->name('commissions.update');
    Route::delete('/commissions/{id}', [AgencyController::class, 'destroyCommission'])->middleware('permission:agency.commissions')->whereNumber('id')->name('commissions.destroy');
});

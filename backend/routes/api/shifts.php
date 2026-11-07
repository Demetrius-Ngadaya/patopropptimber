<?php

use App\Http\Controllers\Api\V1\Shifts\ShiftController;
use Illuminate\Support\Facades\Route;

// Shifts. An optional module: switched on in Settings.
Route::prefix('shifts')->name('shifts.')->group(function () {
    $any = 'permission:shifts.own|shifts.view|shifts.manage';
    Route::get('/', [ShiftController::class, 'index'])->middleware($any)->name('index');
    Route::get('/mine', [ShiftController::class, 'mine'])->middleware('permission:shifts.own')->name('mine');
    Route::get('/staff', [ShiftController::class, 'staff'])->middleware($any)->name('staff');
    Route::get('/types', [ShiftController::class, 'types'])->middleware($any)->name('types');
    Route::post('/types', [ShiftController::class, 'storeType'])->middleware('permission:shifts.manage')->name('types.store');
    Route::put('/types/{id}', [ShiftController::class, 'updateType'])->middleware('permission:shifts.manage')->whereNumber('id')->name('types.update');
    Route::delete('/types/{id}', [ShiftController::class, 'destroyType'])->middleware('permission:shifts.manage')->whereNumber('id')->name('types.destroy');

    Route::post('/', [ShiftController::class, 'schedule'])->middleware('permission:shifts.manage')->name('schedule');
    Route::put('/{id}', [ShiftController::class, 'update'])->middleware('permission:shifts.manage')->whereNumber('id')->name('update');
    Route::post('/{id}/cancel', [ShiftController::class, 'cancel'])->middleware('permission:shifts.manage')->whereNumber('id')->name('cancel');
    Route::post('/{id}/start', [ShiftController::class, 'start'])->middleware('permission:shifts.own')->whereNumber('id')->name('start');
    Route::post('/{id}/end', [ShiftController::class, 'end'])->middleware('permission:shifts.own')->whereNumber('id')->name('end');
    Route::post('/{id}/delegate', [ShiftController::class, 'delegate'])->middleware('permission:shifts.own')->whereNumber('id')->name('delegate');

    Route::post('/delegations/{id}/respond', [ShiftController::class, 'respond'])->middleware('permission:shifts.own')->whereNumber('id')->name('delegations.respond');
    Route::post('/delegations/{id}/cancel', [ShiftController::class, 'cancelDelegation'])->middleware('permission:shifts.own')->whereNumber('id')->name('delegations.cancel');
    Route::post('/handovers/{id}/accept', [ShiftController::class, 'acceptHandover'])->middleware('permission:shifts.own')->whereNumber('id')->name('handovers.accept');
});

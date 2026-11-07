<?php

use App\Http\Controllers\Api\V1\Sales\SalesDocumentController;
use Illuminate\Support\Facades\Route;

// Quotations, proforma invoices and invoices.
Route::prefix('documents')->name('documents.')->group(function () {
    Route::get('/', [SalesDocumentController::class, 'index'])->middleware('permission:documents.view')->name('index');
    Route::post('/', [SalesDocumentController::class, 'store'])->middleware('permission:documents.create')->name('store');
    Route::post('/preview', [SalesDocumentController::class, 'preview'])->middleware(['permission:documents.create', 'throttle:120,1'])->name('preview');
    Route::get('/{id}', [SalesDocumentController::class, 'show'])->middleware('permission:documents.view')->whereNumber('id')->name('show');
    Route::get('/{id}/pdf', [SalesDocumentController::class, 'pdf'])->middleware('permission:documents.view')->whereNumber('id')->name('pdf');
    Route::put('/{id}', [SalesDocumentController::class, 'update'])->middleware('permission:documents.create')->whereNumber('id')->name('update');
    Route::delete('/{id}', [SalesDocumentController::class, 'destroy'])->middleware('permission:documents.create')->whereNumber('id')->name('destroy');
    Route::post('/{id}/send', [SalesDocumentController::class, 'send'])->middleware('permission:documents.create')->whereNumber('id')->name('send');
    Route::post('/{id}/convert', [SalesDocumentController::class, 'convert'])->middleware('permission:documents.create')->whereNumber('id')->name('convert');
    Route::post('/{id}/respond', [SalesDocumentController::class, 'respond'])->middleware('permission:documents.create')->whereNumber('id')->name('respond');
    Route::post('/{id}/close', [SalesDocumentController::class, 'close'])->middleware('permission:documents.create')->whereNumber('id')->name('close');
    Route::post('/{id}/issue', [SalesDocumentController::class, 'issue'])->middleware('permission:documents.issue')->whereNumber('id')->name('issue');
    Route::post('/{id}/payments', [SalesDocumentController::class, 'payment'])->middleware('permission:documents.receive_payment')->whereNumber('id')->name('payments');
    Route::post('/{id}/cancel', [SalesDocumentController::class, 'cancel'])->middleware('permission:documents.cancel')->whereNumber('id')->name('cancel');
});

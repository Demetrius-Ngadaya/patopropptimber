<?php

use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\ProductCodeController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\ProductUnitController;
use App\Http\Controllers\Api\V1\Catalog\UnitController;
use Illuminate\Support\Facades\Route;

Route::crud('categories', CategoryController::class, 'categories');
Route::crud('units', UnitController::class, 'units');

Route::prefix('products')->name('products.')->group(function () {
    Route::get('/', [ProductController::class, 'index'])->middleware('permission:products.view|pos.access')->name('index');
    Route::post('/', [ProductController::class, 'store'])->middleware('permission:products.create')->name('store');
    Route::get('/lookup', [ProductController::class, 'lookup'])->middleware('permission:products.view|purchases.create|pos.access')->name('lookup');
    Route::get('/import/template', [ProductController::class, 'importTemplate'])->middleware('permission:products.import')->name('import.template');
    Route::post('/import', [ProductController::class, 'import'])->middleware(['permission:products.import', 'throttle:exports'])->name('import');
    Route::post('/barcodes', [ProductCodeController::class, 'generate'])->middleware('permission:products.update')->name('barcodes');
    Route::match(['get', 'post'], '/labels', [ProductCodeController::class, 'labels'])->middleware('permission:products.print_labels')->name('labels');
    Route::get('/scan/{token}', [ProductCodeController::class, 'scan'])->where('token', '[A-Za-z0-9]{8,24}')->name('scan');
    Route::get('/{id}', [ProductController::class, 'show'])->middleware('permission:products.view')->whereNumber('id')->name('show');
    Route::put('/{id}/descriptions', [ProductController::class, 'descriptions'])->middleware('permission:products.update')->whereNumber('id')->name('descriptions');
    Route::put('/{id}', [ProductController::class, 'update'])->middleware('permission:products.update')->whereNumber('id')->name('update');
    Route::delete('/{id}', [ProductController::class, 'destroy'])->middleware('permission:products.delete')->whereNumber('id')->name('destroy');
    Route::post('/{id}/restore', [ProductController::class, 'restore'])->middleware('permission:products.restore')->whereNumber('id')->name('restore');

    // Packs: carton / dozen / bale etc. for wholesale selling and buying.
    Route::prefix('/{product}/units')->whereNumber(['product', 'unit'])->name('units.')->group(function () {
        Route::get('/', [ProductUnitController::class, 'index'])->middleware('permission:products.view')->name('index');
        Route::post('/', [ProductUnitController::class, 'store'])->middleware('permission:products.update')->name('store');
        Route::put('/{unit}', [ProductUnitController::class, 'update'])->middleware('permission:products.update')->name('update');
        Route::delete('/{unit}', [ProductUnitController::class, 'destroy'])->middleware('permission:products.update')->name('destroy');
        Route::post('/{unit}/restore', [ProductUnitController::class, 'restore'])->middleware('permission:products.update')->name('restore');
    });
});

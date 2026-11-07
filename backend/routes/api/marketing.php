<?php

use App\Http\Controllers\Api\V1\Marketing\CustomerInsightsController;
use App\Http\Controllers\Api\V1\Marketing\PromotionController;
use App\Http\Controllers\Api\V1\Shop\FaqController;
use App\Http\Controllers\Api\V1\Site\VisitorController;
use Illuminate\Support\Facades\Route;

Route::get('/visitors/summary', [VisitorController::class, 'summary'])->middleware('permission:visitors.view')->name('visitors.summary');
Route::get('/visitors', [VisitorController::class, 'index'])->middleware('permission:visitors.view')->name('visitors.index');
Route::crud('faqs', FaqController::class, 'faqs', ['index', 'store', 'show', 'update', 'destroy', 'restore'], array_fill_keys(['index', 'store', 'show', 'update', 'destroy', 'restore'], 'faqs.manage'));

Route::get('/customer-insights', [CustomerInsightsController::class, 'index'])->middleware('permission:marketing.insights')->name('customer-insights');

Route::prefix('promotions')->name('promotions.')->group(function () {
    Route::get('/eligible', [PromotionController::class, 'eligible'])->middleware('permission:marketing.apply|marketing.promotions')->name('eligible');
    Route::get('/', [PromotionController::class, 'index'])->middleware('permission:marketing.promotions')->name('index');
    Route::post('/', [PromotionController::class, 'store'])->middleware('permission:marketing.manage')->name('store');
    Route::get('/{id}', [PromotionController::class, 'show'])->middleware('permission:marketing.promotions')->whereNumber('id')->name('show');
    Route::put('/{id}', [PromotionController::class, 'update'])->middleware('permission:marketing.manage')->whereNumber('id')->name('update');
    Route::delete('/{id}', [PromotionController::class, 'destroy'])->middleware('permission:marketing.manage')->whereNumber('id')->name('destroy');
    Route::post('/{id}/preview', [PromotionController::class, 'preview'])->middleware('permission:marketing.notify')->whereNumber('id')->name('preview');
    Route::post('/{id}/notify', [PromotionController::class, 'notify'])->middleware('permission:marketing.notify')->whereNumber('id')->name('notify');
});

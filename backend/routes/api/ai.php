<?php

use App\Http\Controllers\Api\V1\Ai\AiController;
use Illuminate\Support\Facades\Route;

/*
| AI features. The assistant and AI writing call Claude (rate limited and
| budgeted); forecasting, anomalies, credit scoring and insights are in-house.
*/
Route::prefix('ai')->name('ai.')->group(function () {
    Route::get('/status', [AiController::class, 'status'])->name('status');

    Route::middleware('permission:ai.assistant')->group(function () {
        Route::get('/conversations', [AiController::class, 'conversations'])->name('conversations');
        Route::get('/conversations/{id}', [AiController::class, 'conversation'])->whereNumber('id')->name('conversations.show');
        Route::delete('/conversations/{id}', [AiController::class, 'deleteConversation'])->whereNumber('id')->name('conversations.destroy');
        Route::post('/ask', [AiController::class, 'ask'])->middleware('throttle:20,1')->name('ask');
    });

    Route::get('/insights', [AiController::class, 'insights'])->middleware(['permission:ai.insights', 'throttle:30,1'])->name('insights');
    Route::get('/reorder', [AiController::class, 'reorder'])->middleware('permission:ai.forecast')->name('reorder');
    Route::get('/forecast/{product}', [AiController::class, 'productForecast'])->middleware('permission:ai.forecast')->whereNumber('product')->name('forecast');
    Route::get('/anomalies', [AiController::class, 'anomalies'])->middleware('permission:ai.anomalies')->name('anomalies');
    Route::get('/credit-scores', [AiController::class, 'creditScores'])->middleware('permission:ai.credit_score')->name('credit-scores');
    Route::get('/credit-scores/{customer}', [AiController::class, 'creditScore'])->middleware('permission:ai.credit_score')->whereNumber('customer')->name('credit-score');
    Route::post('/products/{product}/describe', [AiController::class, 'describeProduct'])->middleware(['permission:ai.generate', 'throttle:20,1'])->whereNumber('product')->name('describe');
    Route::get('/usage', [AiController::class, 'usage'])->middleware('permission:ai.usage')->name('usage');
});

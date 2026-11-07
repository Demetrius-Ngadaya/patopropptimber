<?php

use App\Http\Controllers\Api\V1\Shop\AccountController;
use App\Http\Controllers\Api\V1\Shop\CatalogController;
use App\Http\Controllers\Api\V1\Shop\DeliveryTrackingController;
use App\Http\Controllers\Api\V1\Shop\FaqController;
use App\Http\Controllers\Api\V1\Shop\ShopAssistantController;
use App\Http\Controllers\Api\V1\Site\VisitController;
use Illuminate\Support\Facades\Route;

// Public storefront
Route::middleware('throttle:public')->prefix('shop')->name('shop.')->group(function () {
    Route::get('/branches', [CatalogController::class, 'branches'])->name('branches');
    Route::get('/categories', [CatalogController::class, 'categories'])->name('categories');
    Route::get('/products', [CatalogController::class, 'products'])->name('products');
    Route::get('/products/{slug}', [CatalogController::class, 'product'])->name('product');
    Route::post('/register', [AccountController::class, 'register'])->name('register');
    Route::post('/delivery-quote', [DeliveryTrackingController::class, 'quote'])->name('delivery-quote');
    Route::get('/faqs', [FaqController::class, 'published'])->name('faqs');
    Route::post('/assistant', [ShopAssistantController::class, 'ask'])->middleware('throttle:15,1')->name('assistant');
});

// Website visitor counter (every page of the site, signed in or not).
Route::post('/public/visits', [VisitController::class, 'store'])->middleware('throttle:120,1')->name('public.visits');

// Delivery tracking and chat for the customer (link token, no login).
Route::middleware('throttle:public')->prefix('public/deliveries/{token}')->where(['token' => '[A-Za-z0-9]{40}'])->name('public.deliveries.')->group(function () {
    Route::get('/', [DeliveryTrackingController::class, 'show'])->name('show');
    Route::post('/received', [DeliveryTrackingController::class, 'received'])->name('received');
    Route::get('/messages', [DeliveryTrackingController::class, 'messages'])->name('messages');
    Route::post('/messages', [DeliveryTrackingController::class, 'sendMessage'])->middleware('throttle:30,1')->name('messages.send');
});

// Signed-in customers
Route::middleware(['auth:sanctum', 'session.timeout', 'customer', 'activity', 'throttle:api'])
    ->prefix('shop/account')->name('shop.account.')->group(function () {
        Route::get('/', [AccountController::class, 'profile'])->name('profile');
        Route::put('/', [AccountController::class, 'updateProfile'])->name('update');
        Route::get('/orders', [AccountController::class, 'orders'])->name('orders');
        Route::post('/orders', [AccountController::class, 'placeOrder'])->name('orders.place');
        Route::get('/orders/{number}', [AccountController::class, 'order'])->name('orders.show');
        Route::post('/orders/{number}/cancel', [AccountController::class, 'cancelOrder'])->name('orders.cancel');
        Route::get('/orders/{number}/payment', [AccountController::class, 'orderPayment'])->name('orders.payment');
        Route::post('/orders/{number}/pay', [AccountController::class, 'payOrder'])->middleware('throttle:10,1')->name('orders.pay');
    });

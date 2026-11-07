<?php

use App\Http\Controllers\Api\V1\Payments\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1  (prefix /api/v1, see bootstrap/app.php)
|--------------------------------------------------------------------------
| Each business module keeps its routes in routes/api/<module>.php.
| Staff routes pass through: auth:sanctum -> session.timeout -> staff ->
| two-factor (if required for the role) -> branch -> activity log -> throttle, then a `permission:` check
| on every single action.
*/

Route::get('/health', fn () => response()->json(['success' => true, 'status' => 'ok', 'time' => now()->toIso8601String()]))
    ->name('health');

require __DIR__.'/api/auth.php';
require __DIR__.'/api/shop.php';

// Payment gateway callbacks: no login; each one is verified by its signature.
Route::post('/webhooks/snippe', [PaymentWebhookController::class, 'snippe'])->middleware('throttle:120,1')->name('webhooks.snippe');

Route::middleware(['auth:sanctum', 'session.timeout', 'staff', 'two_factor', 'branch', 'activity', 'throttle:api'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        foreach (['core', 'catalog', 'inventory', 'sales', 'payments', 'documents', 'crm', 'marketing', 'delivery', 'purchasing', 'agency', 'notes', 'shifts', 'finance', 'hr', 'reports', 'ai', 'mobile'] as $module) {
            $file = __DIR__."/api/{$module}.php";
            if (is_file($file)) {
                require $file;
            }
        }
    });

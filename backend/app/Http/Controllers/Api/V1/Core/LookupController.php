<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Category;
use App\Models\Catalog\Unit;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Finance\ExpenseCategory;
use App\Models\Purchasing\Supplier;
use App\Models\Sales\Payment;
use App\Services\Payments\OnlinePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Small option lists for dropdowns. Available to every signed-in staff member
 * because they contain no sensitive data.
 */
class LookupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $wanted = array_filter(explode(',', (string) $request->query('include', 'branches,categories,units,expense_categories,payment')));
        $data = [];

        foreach ($wanted as $key) {
            $data[$key] = match ($key) {
                'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
                'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id']),
                'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'short_name', 'allow_decimal']),
                'expense_categories' => ExpenseCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'phone', 'balance']),
                'staff' => User::query()->visibleTo($request->user())->where('type', User::TYPE_STAFF)->where('is_active', true)
                    ->when(! $this->branch()->canAccessAll(), fn ($q) => $q->whereIn('branch_id', $this->branch()->accessibleIds()))
                    ->orderBy('name')->get(['id', 'name', 'username', 'branch_id']),
                'payment' => [
                    // "online" (payment by phone through the gateway) is offered at the till only, when switched on.
                    'methods' => array_values(array_diff(Payment::METHODS, ['online'])),
                    'providers' => Payment::PROVIDERS,
                    'online' => app(OnlinePaymentService::class)->availability()['pos'] && $request->user()->hasPermission('online_payments.request'),
                    'online_minimum' => (int) config('mauzo.snippe.minimum', 500),
                ],
                default => null,
            };
        }

        return $this->ok(array_filter($data, fn ($v) => $v !== null));
    }

    /** Typeahead customer search (POS, orders). */
    public function customers(Request $request): JsonResponse
    {
        $customers = Customer::query()
            ->where('is_active', true)
            ->search($request->query('search'))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'code', 'name', 'phone', 'balance', 'credit_limit', 'allow_credit']);

        return $this->ok($customers);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Allocation;
use App\Services\Inventory\AllocationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AllocationController extends Controller
{
    public function __construct(private readonly AllocationService $allocations) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->visible($request)
            ->with(['seller', 'branch:id,name'])
            ->withCount('items')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('seller_id'), fn ($q, $v) => $q->where('seller_id', $v))
            ->when($request->query('search'), fn ($q, $t) => $q->where('number', 'like', "%{$t}%"))
            ->latest('issued_at');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $allocation = $this->visible($request)
            ->with(['items.product:id,name,sku,unit_id', 'items.product.unit:id,short_name', 'seller', 'branch', 'sale:id,number,total,amount_paid', 'creator'])
            ->findOrFail($id);
        if (! $request->user()->hasPermission('sales.view_profit')) {
            $allocation->items->each->makeHidden('unit_cost');
        }

        return $this->ok($allocation);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        if (collect($data['items'])->contains(fn ($i) => isset($i['unit_price']))) {
            $this->requirePermission($request, 'pos.change_price');
        }

        $allocation = $this->allocations->issue($data, $request->user(), $this->branch()->requireId());

        return $this->created($allocation, __('Allocation :allocation issued to :seller.', ['allocation' => $allocation->number, 'seller' => $allocation->seller->name]));
    }

    /** The seller records a sale from their own open allocation. */
    public function sell(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $allocation = $this->visible($request)->findOrFail($id);
        if ($allocation->seller_id !== $user->id && ! $user->hasPermission('allocations.reconcile')) {
            abort(403, __('Only the seller of this allocation can sell from it.'));
        }
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.allocation_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'payments' => ['required', 'array', 'min:1', 'max:3'],
            'payments.*.method' => ['required', Rule::in(['cash', 'mobile_money', 'lipa_namba', 'bank'])],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.provider' => ['nullable', 'string', 'max:40'],
            'payments.*.reference' => ['nullable', 'string', 'max:100', 'required_unless:payments.*.method,cash'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $sale = $this->allocations->sell($allocation, $data, $user);

        return $this->created($sale->load(['items', 'payments']), __('Sale :number saved.', ['number' => $sale->number]));
    }

    public function reconcile(Request $request, int $id): JsonResponse
    {
        $allocation = $this->branch()->scope(Allocation::query())->findOrFail($id);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.allocation_item_id' => ['required', 'integer'],
            'items.*.quantity_returned' => ['required', 'numeric', 'min:0'],
            'items.*.quantity_damaged' => ['nullable', 'numeric', 'min:0'],
            'amount_received' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(['cash', 'mobile_money', 'bank', 'lipa_namba'])],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $allocation = $this->allocations->reconcile($allocation, $data, $request->user());
        $msg = "Allocation {$allocation->number} reconciled.";
        if ((float) $allocation->shortage > 0) {
            $msg .= ' Shortage: '.number_format((float) $allocation->shortage, 2).'.';
        }

        return $this->ok($allocation, $msg);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $allocation = $this->branch()->scope(Allocation::query())->findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->ok($this->allocations->cancel($allocation, $data['reason']), __('Allocation cancelled. All stock has been returned.'));
    }

    /** Sellers with only "view own" see just their allocations. */
    private function visible(Request $request): Builder
    {
        $query = $this->branch()->scope(Allocation::query());
        if (! $request->user()->hasPermission('allocations.view')) {
            $query->where('seller_id', $request->user()->id);
        }

        return $query;
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Purchasing\Purchase;
use App\Services\Purchasing\PurchaseReminders;
use App\Services\Purchasing\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{
    public function __construct(private readonly PurchaseService $purchases) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->branch()->scope(Purchase::query())
            ->with(['supplier:id,name', 'branch:id,name', 'creator'])
            ->withCount('items')
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('supplier_invoice_no', 'like', "%{$t}%")))
            ->when($request->query('supplier_id'), fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->query('payment_status'), fn ($q, $v) => $v === 'owed' ? $q->where('balance', '>', 0) : $q->where('payment_status', $v))
            ->when($request->query('due') === 'overdue', fn ($q) => $q->where('balance', '>', 0)->where('due_date', '<', today()->toDateString()))
            ->when($request->query('due') === 'week', fn ($q) => $q->where('balance', '>', 0)->whereBetween('due_date', [today()->toDateString(), today()->addDays(7)->toDateString()]))
            ->when($request->query('date_from'), fn ($q, $v) => $q->where('purchase_date', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->where('purchase_date', '<=', $v.' 23:59:59'));
        $this->sortable($query, $request, ['purchase_date', 'total', 'balance', 'number', 'due_date'], 'purchase_date');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function show(int $id): JsonResponse
    {
        $purchase = $this->branch()->scope(Purchase::query())
            ->with(['items.product:id,name,sku,unit_id', 'items.product.unit:id,short_name', 'supplier', 'branch', 'payments', 'returns.items.product:id,name', 'creator', 'plan:id,number,status'])
            ->findOrFail($id);

        return $this->ok($purchase);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'supplier_invoice_no' => ['nullable', 'string', 'max:60'],
            'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.batch_no' => ['nullable', 'string', 'max:60'],
            'items.*.expiry_date' => ['nullable', 'date', 'after:purchase_date'],
            'tax_cost' => ['nullable', 'numeric', 'min:0'],
            'loading_cost' => ['nullable', 'numeric', 'min:0'],
            'unloading_cost' => ['nullable', 'numeric', 'min:0'],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],
            'other_cost' => ['nullable', 'numeric', 'min:0'],
            'cost_allocation' => ['nullable', Rule::in(['value', 'quantity'])],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(['cash', 'mobile_money', 'bank', 'cheque', 'lipa_namba'])],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'due_date' => ['nullable', 'date', 'after_or_equal:purchase_date'],
            'remind_on' => ['nullable', 'date'],
            'reminder_channels' => ['nullable', 'array'],
            'reminder_channels.*' => ['string', 'in:sms,whatsapp,email'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'items.*.expiry_date.after' => __('An expiry date must be after the purchase date.'),
        ]);

        $changesPrice = collect($data['items'])->contains(fn ($i) => isset($i['selling_price']) && $i['selling_price'] !== null);
        if ($changesPrice && ! $request->user()->hasPermission('products.update_price')) {
            throw new BusinessException(__('You are not allowed to change selling prices. Leave the selling price empty.'), 'FORBIDDEN', 403);
        }

        $purchase = $this->purchases->create($data, $request->user(), $this->branch()->requireId());
        app(PurchaseReminders::class)->apply($purchase, ['remind_on' => $data['remind_on'] ?? null, 'reminder_channels' => $data['reminder_channels'] ?? null]);

        return $this->created($purchase, __('Purchase :purchase recorded and stock updated.', ['purchase' => $purchase->number]));
    }

    /** Change when (and how) the company is reminded to pay this purchase. */
    public function reminder(Request $request, int $id): JsonResponse
    {
        $purchase = $this->branch()->scope(Purchase::query())->findOrFail($id);
        $data = $request->validate([
            'due_date' => ['nullable', 'date', 'after_or_equal:'.$purchase->purchase_date->toDateString()],
            'remind_on' => ['nullable', 'date'],
            'reminder_channels' => ['present', 'array'],
            'reminder_channels.*' => ['string', 'in:sms,whatsapp,email'],
        ]);
        if ((float) $purchase->balance <= 0.004) {
            throw new BusinessException(__('This purchase is fully paid.'));
        }
        if (! empty($data['due_date'])) {
            $purchase->forceFill(['due_date' => $data['due_date']])->save();
        }
        app(PurchaseReminders::class)->apply($purchase, $data);

        return $this->ok($purchase->fresh(), __('Reminder set for :date.', ['date' => $purchase->fresh()->remind_on?->format('d/m/Y') ?? '-']));
    }

    public function returnItems(Request $request, int $id): JsonResponse
    {
        $purchase = $this->branch()->scope(Purchase::query())->findOrFail($id);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $return = $this->purchases->returnToSupplier($purchase, $data['items'], $data['reason']);

        return $this->created($return, __('Return :return recorded. Stock reduced by the returned quantities.', ['return' => $return->number]));
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Purchasing\PurchasePlan;
use App\Services\Purchasing\PurchasePlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchasePlanController extends Controller
{
    public function __construct(private readonly PurchasePlanService $plans) {}

    public function index(Request $request): JsonResponse
    {
        $base = fn () => $this->branch()->scope(PurchasePlan::query())
            ->when($request->query('supplier_id'), fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('title', 'like', "%{$t}%")));
        $query = $base()->with(['supplier:id,name', 'branch:id,name', 'creator'])->withCount('items')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id');
        $counts = collect(PurchasePlan::STATUSES)->mapWithKeys(fn ($s) => [$s => $base()->where('status', $s)->count()]);

        return $this->paginated($query->paginate($this->perPage($request)), ['counts' => $counts]);
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok($this->find($id)->load([
            'items.product:id,name,sku,unit_id,cost_price,track_expiry', 'items.product.unit:id,short_name', 'supplier', 'branch:id,name', 'creator',
            'purchases:id,number,purchase_plan_id,purchase_date,total,balance,payment_status,due_date,remind_on',
            'events.user:id,name',
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $plan = $this->plans->save($this->validated($request), $request->user(), $this->branch()->requireId());

        return $this->created($plan, __('Purchase plan :number saved as a draft.', ['number' => $plan->number]));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $plan = $this->find($id);
        $plan = $this->plans->save($this->validated($request), $request->user(), $plan->branch_id, $plan);

        return $this->ok($plan, __(':number saved.', ['number' => $plan->number]));
    }

    public function destroy(int $id): JsonResponse
    {
        $plan = $this->find($id);
        if ($plan->status !== 'draft') {
            throw new BusinessException(__('Only drafts can be deleted. Cancel it instead.'));
        }
        $plan->delete();

        return $this->ok(null, __('Draft deleted.'));
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $plan = $this->plans->submit($this->find($id), $request->user());

        return $this->ok($plan, __(':number is pending: waiting for the goods.', ['number' => $plan->number]));
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:60'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.plan_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
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
        ]);
        if (collect($data['items'])->contains(fn ($i) => isset($i['selling_price'])) && ! $request->user()->hasPermission('products.update_price')) {
            throw new BusinessException(__('You are not allowed to change selling prices. Leave the selling price empty.'), 'FORBIDDEN', 403);
        }
        $purchase = $this->plans->receive($this->find($id), $data, $request->user());

        return $this->created($purchase, __('Goods received as purchase :number. Stock updated.', ['number' => $purchase->number]));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return $this->ok($this->plans->cancel($this->find($id), $request->user(), $reason), __('Plan cancelled.'));
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->ok($this->plans->close($this->find($id), $request->user(), $note), __('Plan closed.'));
    }

    private function find(int $id): PurchasePlan
    {
        return $this->branch()->scope(PurchasePlan::query())->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'title' => ['nullable', 'string', 'max:150'],
            'expected_date' => ['nullable', 'date'],
            'payment_type' => ['required', Rule::in(['cash', 'credit'])],
            'credit_days' => ['nullable', 'required_if:payment_type,credit', 'integer', 'min:0', 'max:365'],
            'reminder_days_before' => ['nullable', 'integer', 'min:0', 'max:90'],
            'reminder_channels' => ['nullable', 'array'],
            'reminder_channels.*' => ['string', 'in:sms,whatsapp,email'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}

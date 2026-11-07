<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Models\Crm\Customer;
use App\Models\Delivery\Delivery;
use App\Models\Sales\Order;
use App\Models\Sales\Payment;
use App\Services\Sales\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->branch()->scope(Order::query())
            ->with(['customer:id,name,phone', 'branch:id,name', 'assignee'])
            ->withCount('items')
            ->when($request->query('status'), fn ($q, $v) => $v === 'open'
                ? $q->whereNotIn('status', ['completed', 'cancelled'])
                : $q->where('status', $v))
            ->when($request->query('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")
                ->orWhere('contact_phone', 'like', "%{$t}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$t}%"))))
            ->when($request->query('date_from'), fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($request->query('date_to'), fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->latest();

        $counts = $this->branch()->scope(Order::query())
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return $this->paginated($query->paginate($this->perPage($request)), ['counts' => $counts]);
    }

    public function show(int $id): JsonResponse
    {
        $order = $this->branch()->scope(Order::query())
            ->with(['items.product:id,name,sku,image_path', 'customer', 'branch', 'histories.user', 'sale:id,number,total,amount_paid,balance', 'assignee', 'onlinePayments'])
            ->findOrFail($id);

        return $this->ok($order->toArray() + [
            'delivery' => Delivery::query()->where('order_id', $order->id)->with('courier:id,name,phone')->latest('id')->first(),
        ]);
    }

    /** Staff take an order for a customer (phone call, WhatsApp, walk-in for later pickup). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'delivery_method' => ['required', Rule::in(['pickup', 'delivery'])],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'staff_notes' => ['nullable', 'string', 'max:1000'],
            'discount' => ['nullable', 'numeric', 'min:0'],
        ]);
        if (! empty($data['discount'])) {
            $this->requirePermission($request, 'pos.discount');
        }

        $customer = Customer::findOrFail($data['customer_id']);
        $order = $this->orders->place($customer, $data + ['branch_id' => $this->branch()->requireId()], 'staff');

        return $this->created($order, __('Order :order created.', ['order' => $order->number]));
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $order = $this->branch()->scope(Order::query())->findOrFail($id);
        $data = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'processing', 'ready', 'out_for_delivery', 'cancelled'])],
            'note' => ['nullable', 'string', 'max:500', Rule::requiredIf($request->input('status') === 'cancelled')],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        if ($data['status'] === 'cancelled') {
            $this->requirePermission($request, 'orders.cancel');
        }
        if (array_key_exists('assigned_to', $data)) {
            $order->assigned_to = $data['assigned_to'];
        }

        $order = $this->orders->changeStatus($order, $data['status'], $data['note'] ?? null);

        return $this->ok($order, __('Order :order is now :status.', ['order' => $order->number, 'status' => __(str_replace('_', ' ', $order->status))]));
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $order = $this->branch()->scope(Order::query())->findOrFail($id);
        $data = $request->validate([
            'payments' => ['present', 'array'],
            // What the customer pays now; money already paid online is added automatically.
            'payments.*.method' => ['required', Rule::in(array_diff(Payment::METHODS, ['online']))],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.provider' => ['nullable', 'string', 'max:40'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $this->orders->complete($order, $data, $request->user(), $this->branch()->requireId());

        return $this->ok($order, __('Order :order completed. Sale :sale created.', ['order' => $order->number, 'sale' => $order->sale?->number]));
    }
}

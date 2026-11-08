<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Product;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Sales\Order;
use App\Models\Sales\OrderItem;
use App\Models\Sales\OrderStatusHistory;
use App\Models\Sales\Sale;
use App\Services\Catalog\BranchPrices;
use App\Services\Catalog\PricingService;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use App\Services\Core\SettingsService;
use App\Services\Delivery\DeliveryFees;
use App\Services\Inventory\StockService;
use App\Services\Messaging\CustomerMessages;
use App\Services\Notifications\StaffNotices;
use App\Services\Payments\OnlinePaymentService;
use App\Support\Afterwards;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Orders placed by customers in the online shop (or by staff for a customer).
 * Orders are paid on pickup / delivery, or online by phone when the admin
 * allows it (the money then counts towards the sale made when the order is
 * completed). Completing an order turns it into a sale, which is when stock
 * is deducted.
 */
class OrderService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly NumberGenerator $numbers,
        private readonly SaleService $sales,
        private readonly SettingsService $settings,
        private readonly CustomerMessages $customerMessages,
        private readonly AuditLogger $audit,
        private readonly PricingService $pricing,
        private readonly DeliveryFees $fees,
        private readonly StaffNotices $notices,
        private readonly OnlinePaymentService $onlinePayments,
    ) {}

    /**
     * @param  array{branch_id: int, items: array<int, array{product_id: int, quantity: float}>, delivery_method: string, delivery_address?: ?string, contact_phone?: ?string, customer_notes?: ?string, preferred_date?: ?string, staff_notes?: ?string, discount?: ?float}  $data
     */
    public function place(Customer $customer, array $data, string $source = 'online'): Order
    {
        $branch = Branch::query()->whereKey($data['branch_id'])->where('is_active', true)->first();
        if (! $branch) {
            throw new BusinessException(__('The selected branch is not available.'));
        }
        if ($source === 'online') {
            if (! $this->settings->bool('shop_enabled')) {
                throw new BusinessException(__('The online shop is currently closed.'));
            }
            if (! $branch->accepts_online_orders) {
                throw new BusinessException(__(':branch does not accept online orders.', ['branch' => $branch->name]));
            }
        }

        $delivery = $data['delivery_method'] === 'delivery';
        if ($delivery && ! $this->settings->bool('shop_delivery_enabled') && $source === 'online') {
            throw new BusinessException(__('Delivery is not available. Please choose pickup.'));
        }
        if ($delivery && empty($data['delivery_address'])) {
            throw new BusinessException(__('Enter the delivery address.'));
        }

        return DB::transaction(function () use ($customer, $data, $source, $branch, $delivery) {
            $lines = collect($data['items'])->groupBy('product_id')->map(fn ($g) => [
                'product_id' => (int) $g->first()['product_id'],
                'quantity' => round((float) $g->sum('quantity'), 3),
            ])->values();

            $products = Product::query()->whereIn('id', $lines->pluck('product_id'))->get()->keyBy('id');
            app(BranchPrices::class)->apply($products, $branch?->id);
            $allowNegative = $this->settings->bool('allow_negative_stock');

            // Wholesale customers see and pay wholesale prices online too.
            $level = $this->pricing->levelFor($customer, null);

            $subtotal = 0.0;
            $rows = [];
            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);
                if (! $product || ! $product->is_active || ($source === 'online' && ! $product->show_online)) {
                    throw new BusinessException(__('One of the products is no longer available.'));
                }
                $this->stock->assertQuantity($product, $line['quantity']);
                if (! $product->is_service && ! $allowNegative) {
                    $available = $this->stock->onHand($product->id, $branch->id);
                    if ($available + 0.0005 < $line['quantity']) {
                        throw new BusinessException(__('Only :available of :product available at :branch.', ['available' => 0 + round($available, 3), 'product' => $product->name, 'branch' => $branch->name]), 'INSUFFICIENT_STOCK');
                    }
                }
                $price = $this->pricing->quote($product, null, $line['quantity'], $level)['price'];
                $lineTotal = round($price * $line['quantity'], 2);
                $subtotal += $lineTotal;
                $rows[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $line['quantity'],
                    'unit_price' => $price,
                    'line_total' => $lineTotal,
                ];
            }

            $minimum = (float) $this->settings->get('shop_min_order_amount');
            if ($source === 'online' && $minimum > 0 && $subtotal < $minimum) {
                throw new BusinessException(__('The minimum order amount is :amount.', ['amount' => number_format($minimum, 2)]));
            }

            // Fee by distance when the customer's location is known (else the flat shop fee).
            $lat = isset($data['delivery_latitude']) ? (float) $data['delivery_latitude'] : null;
            $lng = isset($data['delivery_longitude']) ? (float) $data['delivery_longitude'] : null;
            $quote = $delivery ? $this->fees->quote($branch, $lat, $lng) : ['fee' => 0.0, 'distance_km' => null];
            $deliveryFee = (float) $quote['fee'];
            $discount = $source === 'staff' ? round((float) ($data['discount'] ?? 0), 2) : 0.0;

            $order = Order::create([
                'number' => $this->numbers->next('order'),
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'source' => $source,
                'status' => 'pending',
                'delivery_method' => $data['delivery_method'],
                'delivery_address' => $data['delivery_address'] ?? null,
                'delivery_latitude' => $delivery ? $lat : null,
                'delivery_longitude' => $delivery ? $lng : null,
                'delivery_distance_km' => $quote['distance_km'],
                'contact_phone' => $data['contact_phone'] ?? $customer->phone,
                'payment_method' => 'cash_on_delivery',
                'subtotal' => round($subtotal, 2),
                'delivery_fee' => $deliveryFee,
                'discount' => $discount,
                'total' => round($subtotal + $deliveryFee - $discount, 2),
                'preferred_date' => $data['preferred_date'] ?? null,
                'customer_notes' => $data['customer_notes'] ?? null,
                'staff_notes' => $data['staff_notes'] ?? null,
            ]);

            foreach ($rows as $row) {
                OrderItem::create($row + ['order_id' => $order->id]);
            }

            $this->history($order, null, 'pending', $source === 'online' ? 'Order placed online' : 'Order created by staff');
            $this->audit->event('order.placed', "Order {$order->number} placed ({$source})", $order);
            $this->notices->orderPlaced($order);

            return $order->load(['items', 'branch', 'customer']);
        });
    }

    public function changeStatus(Order $order, string $to, ?string $note = null): Order
    {
        $allowed = Order::TRANSITIONS[$order->status] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new BusinessException(__('An order that is :status cannot be changed to :to.', ['status' => $order->status, 'to' => $to]));
        }
        if ($to === 'cancelled') {
            return $this->cancel($order, $note ?: 'Cancelled by staff');
        }

        $from = $order->status;
        $order->status = $to;
        if ($to === 'confirmed') {
            $order->confirmed_at = now();
        }
        $order->save();
        $this->history($order, $from, $to, $note);
        $this->notify($order);

        return $order->load(['items', 'histories.user', 'customer', 'branch']);
    }

    /** A delivery person has set off with the order (from whichever open step it was at). */
    public function markOutForDelivery(Order $order, ?string $note = null): Order
    {
        if (! $order->isOpen() || $order->status === 'out_for_delivery') {
            return $order;
        }
        $from = $order->status;
        $order->forceFill(['status' => 'out_for_delivery', 'confirmed_at' => $order->confirmed_at ?? now()])->save();
        $this->history($order, $from, 'out_for_delivery', $note);
        $this->notify($order);

        return $order;
    }

    public function cancel(Order $order, string $reason): Order
    {
        if (! $order->isOpen()) {
            throw new BusinessException(__('This order is already :status.', ['status' => __($order->status)]));
        }

        $from = $order->status;
        $order->auditReason = $reason;
        $order->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();
        $this->history($order, $from, 'cancelled', $reason);
        $this->notify($order);

        if ((float) $order->paid_online > 0) {
            // The gateway does not refund by itself: someone must send the money back.
            $this->history($order, 'cancelled', 'cancelled', __('Paid online :amount: refund the customer.', ['amount' => number_format((float) $order->paid_online)]));
            $this->notices->refundNeeded($order);
        }

        return $order->load(['items', 'histories.user', 'customer', 'branch']);
    }

    /**
     * Hand over the goods: creates the sale (stock is deducted now) and records payment.
     *
     * @param  array{payments?: array, due_date?: ?string, notes?: ?string}  $data
     */
    public function complete(Order $order, array $data, User $user, int $branchId): Order
    {
        if (! $order->isOpen()) {
            throw new BusinessException(__('This order is already :status.', ['status' => __($order->status)]));
        }
        if ((int) $order->branch_id !== $branchId) {
            throw new BusinessException(__('Switch to the branch that fulfils this order to complete it.'));
        }

        return DB::transaction(function () use ($order, $data, $user, $branchId) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->load('items');

            $sale = $this->sales->create([
                'customer_id' => $order->customer_id,
                'items' => $order->items->map(fn (OrderItem $i) => [
                    'product_id' => $i->product_id,
                    'quantity' => (float) $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                ])->all(),
                'discount' => (float) $order->discount,
                'delivery_fee' => (float) $order->delivery_fee,
                'payments' => array_merge($this->onlinePayments->unusedForOrder($order)->map(fn ($p) => [
                    'method' => 'online', 'amount' => (float) $p->amount, 'online_payment_id' => $p->id,
                ])->all(), $data['payments'] ?? []),
                'due_date' => $data['due_date'] ?? null,
                'channel' => $order->source === 'online' ? 'online' : 'order',
                'notes' => trim("Order {$order->number}. ".($data['notes'] ?? '')),
                'send_sms' => false,
            ], $user, $branchId, [
                'enforce_permissions' => false,
                'source' => $order,
            ]);

            $from = $order->status;
            $order->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'sale_id' => $sale->id,
            ])->save();
            $this->history($order, $from, 'completed', "Sale {$sale->number}");
            $this->notify($order);

            return $order->load(['items', 'histories.user', 'customer', 'branch', 'sale']);
        });
    }

    private function history(Order $order, ?string $from, string $to, ?string $note): void
    {
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'user_id' => Auth::id(),
        ]);
    }

    private function notify(Order $order): void
    {
        if ($this->settings->bool('sms_order_updates')) {
            Afterwards::run(fn () => $this->customerMessages->orderUpdate($order));
        }
    }
}

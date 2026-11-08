<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductUnit;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Sales\HeldSale;
use App\Models\Sales\Order;
use App\Models\Sales\Payment;
use App\Models\Sales\Sale;
use App\Models\Sales\SaleItem;
use App\Models\Sales\SaleReturn;
use App\Services\Catalog\BranchPrices;
use App\Services\Catalog\PricingService;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use App\Services\Core\SettingsService;
use App\Services\Crm\CustomerService;
use App\Services\Crm\SavingsService;
use App\Services\Finance\PostingService;
use App\Services\Inventory\StockService;
use App\Services\Marketing\PromotionService;
use App\Services\Messaging\CustomerMessages;
use App\Services\Notifications\StaffNotices;
use App\Services\Payments\OnlinePaymentService;
use App\Support\Afterwards;
use App\Support\Channels;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales: checkout (cash, mobile money, bank, savings, credit), cancellation,
 * returns and debt repayments. Stock, savings, customer balances and the
 * general ledger are all updated inside one database transaction.
 */
class SaleService
{
    private const EPSILON = 0.005;

    public function __construct(
        private readonly StockService $stock,
        private readonly PricingService $pricing,
        private readonly NumberGenerator $numbers,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly CustomerMessages $customerMessages,
        private readonly CustomerService $customers,
        private readonly SavingsService $savings,
        private readonly PostingService $posting,
        private readonly PromotionService $promotions,
        private readonly StaffNotices $notices,
        private readonly OnlinePaymentService $online,
        private readonly BranchPrices $branchPrices,
    ) {}

    /**
     * Create a completed sale.
     *
     * @param  array{
     *   customer_id?: ?int, customer_name?: ?string, customer_phone?: ?string,
     *   new_customer?: ?array{name: string, phone: string, address: string},
     *   price_level?: ?string,
     *   items: array<int, array{product_id: int, product_unit_id?: ?int, quantity: float, unit_price?: ?float, discount?: ?float, consumed?: array}>,
     *   discount?: ?float, delivery_fee?: ?float,
     *   payments?: array<int, array{method: string, amount: float, provider?: ?string, reference?: ?string, payer_phone?: ?string}>,
     *   due_date?: ?string, sold_at?: ?string, notes?: ?string, channel?: string, seller_id?: ?int,
     *   held_sale_id?: ?int, send_sms?: bool, receipt_channels?: ?array
     * }  $data
     * @param  array{enforce_permissions?: bool, source?: ?Model, allow_credit_without_permission?: bool, allow_shortage?: bool}  $options
     */
    public function create(array $data, User $user, int $branchId, array $options = []): Sale
    {
        $enforce = $options['enforce_permissions'] ?? true;
        $items = $data['items'] ?? [];
        if ($items === []) {
            throw new BusinessException(__('Add at least one product to the sale.'));
        }

        $sale = DB::transaction(function () use ($data, $user, $branchId, $options, $enforce, $items) {
            $products = Product::query()->with('unit')
                ->whereIn('id', collect($items)->pluck('product_id')->unique())
                ->get()->keyBy('id');
            $this->branchPrices->apply($products, $branchId);
            $units = ProductUnit::query()
                ->whereIn('id', collect($items)->pluck('product_unit_id')->filter()->unique())
                ->get()->keyBy('id');

            $customer = $this->resolveCustomer($data, $branchId);
            $level = $this->pricing->levelFor($customer, $data['price_level'] ?? null);
            if ($enforce && ($data['price_level'] ?? null) === PricingService::WHOLESALE && ! $customer?->isWholesale() && ! $user->hasPermission('pos.wholesale')) {
                throw new BusinessException(__('You are not allowed to sell at wholesale prices.'), 'FORBIDDEN', 403);
            }

            $soldAt = $this->saleDate($data, $user, $enforce);
            // Who may use a promotion depends on past purchases, so check before this sale exists.
            $promotion = ! empty($data['promotion_id']) ? $this->promotions->findEligible((int) $data['promotion_id'], $customer) : null;

            $sale = Sale::create([
                'number' => $this->numbers->next('sale'),
                'branch_id' => $branchId,
                'customer_id' => $customer?->id,
                'customer_name' => $customer?->name ?? ($data['customer_name'] ?? null),
                'customer_phone' => $customer?->phone ?? ($data['customer_phone'] ?? null),
                'cashier_id' => $user->id,
                'seller_id' => $data['seller_id'] ?? null,
                'channel' => $data['channel'] ?? 'pos',
                'price_level' => $level,
                'status' => Sale::STATUS_COMPLETED,
                'sold_at' => $soldAt,
                'notes' => $data['notes'] ?? null,
                'source_type' => isset($options['source']) ? $options['source']->getMorphClass() : null,
                'source_id' => isset($options['source']) ? $options['source']->getKey() : null,
            ]);

            $pricesIncludeTax = $this->settings->bool('prices_include_tax');
            $subtotal = $itemDiscount = $tax = $costTotal = 0.0;

            foreach ($items as $line) {
                /** @var Product|null $product */
                $product = $products->get($line['product_id']);
                if (! $product || ! $product->is_active) {
                    throw new BusinessException(__('A product in the cart is no longer available.'));
                }
                $unit = ! empty($line['product_unit_id']) ? $units->get($line['product_unit_id']) : null;
                if (! empty($line['product_unit_id']) && ! $unit) {
                    throw new BusinessException(__('The selected unit of :product no longer exists.', ['product' => $product->name]));
                }

                $unitQuantity = round((float) $line['quantity'], 3);
                $quote = $this->pricing->quote($product, $unit, $unitQuantity, $level);
                $baseQuantity = $quote['base_quantity'];
                if ($unit && abs($unitQuantity - round($unitQuantity)) > 0.0005 && ! $product->allow_decimal) {
                    throw new BusinessException(__(':unit of :product are sold in whole numbers only.', ['unit' => $unit->name, 'product' => $product->name]));
                }
                $this->stock->assertQuantity($product, $baseQuantity);

                $listPrice = $quote['price'];
                $price = isset($line['unit_price']) && $line['unit_price'] !== null ? round((float) $line['unit_price'], 2) : $listPrice;
                $lineDiscount = round((float) ($line['discount'] ?? 0), 2);

                if ($enforce) {
                    $this->checkPricePermissions($user, $product, $price, $listPrice, $lineDiscount, $quote['factor']);
                }

                $gross = round($unitQuantity * $price, 2);
                if ($lineDiscount > $gross) {
                    throw new BusinessException(__('The discount on :product is larger than the line amount.', ['product' => $product->name]));
                }
                $lineTotal = round($gross - $lineDiscount, 2);
                $taxRate = (float) $product->tax_rate;
                $lineTax = $taxRate > 0
                    ? ($pricesIncludeTax ? round($lineTotal - $lineTotal / (1 + $taxRate / 100), 2) : round($lineTotal * $taxRate / 100, 2))
                    : 0.0;

                $consumed = $line['consumed'] ?? $this->stock->decrease($product, $branchId, $baseQuantity, 'sale', $sale, ['note' => $sale->number]);
                $unitCost = $this->stock->averageCost($consumed);

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'product_unit_id' => $unit?->id,
                    'product_name' => $product->name,
                    'unit_name' => $quote['unit_name'],
                    'unit_factor' => $quote['factor'],
                    'unit_quantity' => $unitQuantity,
                    'price_level' => $quote['level'],
                    'quantity' => $baseQuantity,
                    'unit_price' => $price,
                    'list_price' => $listPrice,
                    'discount' => $lineDiscount,
                    'tax_rate' => $taxRate,
                    'tax' => $lineTax,
                    'line_total' => $lineTotal,
                    'unit_cost' => $unitCost,
                    'batches' => $consumed,
                ]);

                $subtotal += $gross;
                $itemDiscount += $lineDiscount;
                $tax += $lineTax;
                $costTotal += $unitCost * $baseQuantity;
            }

            $invoiceDiscount = round((float) ($data['discount'] ?? 0), 2);
            if ($invoiceDiscount > 0 && $enforce && ! $user->hasPermission('pos.discount')) {
                throw new BusinessException(__('You are not allowed to give discounts.'), 'FORBIDDEN', 403);
            }

            // A customer promotion: its own discount, no discount permission needed.
            $promotionDiscount = 0.0;
            if ($promotion) {
                $promotionDiscount = $this->promotions->discount($promotion, $sale->items()->get(['product_id', 'line_total'])->toArray());
                $promotionDiscount = min($promotionDiscount, max(0, round($subtotal - $itemDiscount - $invoiceDiscount, 2)));
                $invoiceDiscount = round($invoiceDiscount + $promotionDiscount, 2);
            }

            $deliveryFee = round(max(0, (float) ($data['delivery_fee'] ?? 0)), 2);
            $total = round($subtotal - $itemDiscount - $invoiceDiscount + ($pricesIncludeTax ? 0 : $tax) + $deliveryFee, 2);
            if ($total < 0) {
                throw new BusinessException(__('The discount cannot be larger than the sale amount.'));
            }

            $payments = $data['payments'] ?? [];
            if ((! empty($data['use_savings']) || collect($payments)->contains('method', 'savings')) && ! Modules::enabled('savings')) {
                throw new BusinessException(__('Customer savings are switched off on this system.'), 'MODULE_DISABLED');
            }
            if (! empty($data['use_savings'])) {
                // Pay as much as the savings cover; whatever is left becomes a credit debt.
                if (! $customer) {
                    throw new BusinessException(__('Choose the customer whose savings will pay for this sale.'), 'CUSTOMER_REQUIRED');
                }
                $others = round(array_sum(array_map(fn ($p) => $p['method'] === 'savings' ? 0 : (float) $p['amount'], $payments)), 2);
                $payments = array_values(array_filter($payments, fn ($p) => $p['method'] !== 'savings'));
                $fromSavings = round(min($this->savings->availableBalance($customer), max(0, $total - $others)), 2);
                if ($fromSavings > 0) {
                    $payments[] = ['method' => 'savings', 'amount' => $fromSavings];
                }
            }
            $order = ($options['source'] ?? null) instanceof Order ? $options['source'] : null;
            [$amountPaid, $change] = $this->recordPayments($sale, $payments, $total, $user, $branchId, $customer, $order);
            $balance = round($total - $amountPaid, 2);
            $isCredit = $balance > self::EPSILON && ! ($options['allow_shortage'] ?? false);

            if ($isCredit) {
                $this->assertCreditAllowed($user, $customer, $balance, $enforce && ! ($options['allow_credit_without_permission'] ?? false));
                $dueDate = $this->dueDate($data, $soldAt);
                $customer->balance = round((float) $customer->balance + $balance, 2);
                $customer->save();
            }

            $sale->forceFill([
                'subtotal' => round($subtotal, 2),
                'item_discount' => round($itemDiscount, 2),
                'discount' => $invoiceDiscount,
                'promotion_id' => $promotion?->id,
                'promotion_discount' => $promotionDiscount,
                'tax' => round($tax, 2),
                'delivery_fee' => $deliveryFee,
                'total' => $total,
                'cost_total' => round($costTotal, 2),
                'amount_paid' => $amountPaid,
                'balance' => max(0, $balance),
                'change_given' => $change,
                'payment_status' => $this->paymentStatus($total, $amountPaid),
                'is_credit' => $isCredit,
                'credit_amount' => $isCredit ? $balance : 0,
                'due_date' => $balance > self::EPSILON ? ($dueDate ?? $soldAt->copy()->addDays($this->settings->int('credit_default_days'))->toDateString()) : null,
                'fully_paid_at' => $balance > self::EPSILON ? null : now(),
            ])->saveQuietly();

            // Savings purchases record how much of the purchase was left as debt.
            $sale->payments()->where('method', 'savings')->with('savingsTransaction')->get()
                ->each(fn (Payment $p) => $p->savingsTransaction?->forceFill(['debt_amount' => max(0, $balance)])->save());

            if (! empty($data['held_sale_id'])) {
                HeldSale::query()->whereKey($data['held_sale_id'])->where('branch_id', $branchId)->delete();
            }

            if ($promotion && $promotionDiscount > 0) {
                $this->promotions->recordRedemption($promotion, $sale, $promotionDiscount);
            }

            $this->posting->sale($sale);

            $this->audit->event('sale.completed', __('Sale :number completed, total :total', ['number' => $sale->number, 'total' => number_format($total, 2)]), $sale, [
                'total' => $total, 'paid' => $amountPaid, 'balance' => max(0, $balance), 'items' => count($items), 'credit' => $isCredit,
            ]);

            return $sale;
        });

        $sale->load(['items', 'payments', 'customer', 'cashier', 'branch']);

        // Receipt to the customer: the channels ticked at the till, else the setting.
        if (array_key_exists('receipt_channels', $data) && $data['receipt_channels'] !== null) {
            $channels = Channels::filter((array) $data['receipt_channels']);
        } elseif (isset($data['send_sms'])) {
            $channels = $data['send_sms'] ? Channels::filter([Channels::SMS]) : []; // older clients
        } else {
            $channels = $this->settings->bool('sms_receipts') ? Channels::forType('customer_receipt') : [];
        }
        if ($channels) {
            Afterwards::run(fn () => $this->customerMessages->receipt($sale, $channels));
        }

        return $sale;
    }

    /**
     * Price a cart exactly as checkout would (packs, wholesale level, automatic
     * wholesale by quantity, tax), without saving anything or touching stock.
     * The till shows these figures so the amount collected always matches.
     *
     * @return array{price_level: string, lines: array, subtotal: float, discount: float, tax: float, total: float}
     */
    public function quote(array $data, ?int $branchId = null): array
    {
        $items = $data['items'] ?? [];
        $products = Product::query()->with('unit')->whereIn('id', collect($items)->pluck('product_id')->unique())->get()->keyBy('id');
        $this->branchPrices->apply($products, $branchId);
        $units = ProductUnit::query()->whereIn('id', collect($items)->pluck('product_unit_id')->filter()->unique())->get()->keyBy('id');
        $customer = ! empty($data['customer_id']) ? Customer::query()->find($data['customer_id']) : null;
        $level = $this->pricing->levelFor($customer, $data['price_level'] ?? null);
        $pricesIncludeTax = $this->settings->bool('prices_include_tax');

        $lines = [];
        $subtotal = $itemDiscount = $tax = 0.0;
        foreach ($items as $i => $line) {
            $product = $products->get($line['product_id']);
            if (! $product) {
                continue;
            }
            $unit = ! empty($line['product_unit_id']) ? $units->get($line['product_unit_id']) : null;
            $q = $this->pricing->quote($product, $unit, (float) $line['quantity'], $level);
            $price = isset($line['unit_price']) && $line['unit_price'] !== null ? round((float) $line['unit_price'], 2) : $q['price'];
            $gross = round($price * (float) $line['quantity'], 2);
            $discount = min($gross, round((float) ($line['discount'] ?? 0), 2));
            $net = $gross - $discount;
            $rate = (float) $product->tax_rate;
            $lineTax = $rate > 0 ? ($pricesIncludeTax ? round($net - $net / (1 + $rate / 100), 2) : round($net * $rate / 100, 2)) : 0.0;

            $lines[] = [
                'index' => $i, 'product_id' => $product->id, 'product_unit_id' => $unit?->id,
                'list_price' => $q['price'], 'unit_price' => $price, 'price_level' => $q['level'],
                'unit_name' => $q['unit_name'], 'factor' => $q['factor'], 'base_quantity' => $q['base_quantity'],
                'discount' => $discount, 'tax' => $lineTax, 'line_total' => round($net, 2),
            ];
            $subtotal += $gross;
            $itemDiscount += $discount;
            $tax += $lineTax;
        }
        $invoiceDiscount = round((float) ($data['discount'] ?? 0), 2);
        $promotion = null;
        if (! empty($data['promotion_id'])) {
            [$found, $amount] = $this->promotions->discountFor((int) $data['promotion_id'], $customer, $lines);
            $amount = min($amount, max(0, round($subtotal - $itemDiscount - $invoiceDiscount, 2)));
            $promotion = ['id' => $found->id, 'name' => $found->name, 'discount' => $amount];
            $invoiceDiscount += $amount;
        }

        return [
            'price_level' => $level,
            'promotion' => $promotion,
            'lines' => $lines,
            'subtotal' => round($subtotal, 2),
            'discount' => round($itemDiscount + $invoiceDiscount, 2),
            'tax' => round($tax, 2),
            'total' => round(max(0, $subtotal - $itemDiscount - $invoiceDiscount + ($pricesIncludeTax ? 0 : $tax)), 2),
        ];
    }

    public function cancel(Sale $sale, User $user, string $reason): Sale
    {
        if ($sale->status === Sale::STATUS_CANCELLED) {
            throw new BusinessException(__('This sale is already cancelled.'));
        }

        return DB::transaction(function () use ($sale, $user, $reason) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->load(['items.product', 'payments', 'customer']);

            foreach ($sale->items as $item) {
                $qty = $item->returnableQuantity();
                if ($qty > 0) {
                    $this->stock->restore($item->product, $sale->branch_id, $item->batches ?? [], $qty, 'sale_cancel', $sale, __('Cancelled :number', ['number' => $sale->number]));
                }
            }

            if ($sale->customer_id && (float) $sale->balance > 0) {
                $customer = Customer::query()->whereKey($sale->customer_id)->lockForUpdate()->first();
                $customer->balance = round(max(0, (float) $customer->balance - (float) $sale->balance), 2);
                $customer->save();
            }

            // Give the money back the way it came in: savings back to savings, the rest in cash.
            // Money still held for this sale = everything received minus refunds already paid out.
            $refund = max(0, round((float) $sale->amount_paid + (float) $sale->payments->where('type', 'refund')->sum(fn ($p) => (float) $p->amount), 2));
            $paidBySavings = max(0, round((float) $sale->payments->where('method', 'savings')->sum(fn ($p) => (float) $p->amount), 2));
            $toSavings = min($paidBySavings, $refund);
            if ($toSavings > self::EPSILON && $sale->customer) {
                $tx = $this->savings->refund($sale->customer, $toSavings, $sale, __('Cancelled sale :number', ['number' => $sale->number]));
                $this->refundPayment($sale, $toSavings, 'savings', $user, __('Refund to savings for cancelled sale :number', ['number' => $sale->number]), $tx->id);
            }
            if ($refund - $toSavings > self::EPSILON) {
                $this->refundPayment($sale, $refund - $toSavings, 'cash', $user, __('Refund for cancelled sale :number', ['number' => $sale->number]));
            }

            $sale->auditReason = $reason;
            $sale->forceFill([
                'status' => Sale::STATUS_CANCELLED,
                'balance' => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reason,
            ])->save();

            $this->posting->saleCancelled($sale, $reason);
            $this->audit->event('sale.cancelled', __('Sale :number cancelled: :reason', ['number' => $sale->number, 'reason' => $reason]), $sale);
            $this->notices->saleCancelled($sale, $reason, $user->name);

            return $sale->fresh(['items', 'payments', 'customer', 'cashier', 'branch']);
        });
    }

    /**
     * @param  array<int, array{sale_item_id: int, quantity: float, condition: string}>  $lines  quantities in base units
     */
    public function processReturn(Sale $sale, array $lines, string $reason, ?string $refundMethod, User $user): SaleReturn
    {
        if ($sale->status !== Sale::STATUS_COMPLETED) {
            throw new BusinessException(__('Only completed sales can be returned.'));
        }
        $refundMethod = $refundMethod ?: 'cash';
        if ($refundMethod === 'savings' && ! $sale->customer_id) {
            throw new BusinessException(__('Refunds to savings need a registered customer.'));
        }

        return DB::transaction(function () use ($sale, $lines, $reason, $refundMethod, $user) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $items = $sale->items()->with('product')->get()->keyBy('id');

            $return = SaleReturn::create([
                'number' => $this->numbers->next('sale_return'),
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'customer_id' => $sale->customer_id,
                'reason' => $reason,
                'refund_method' => $refundMethod,
            ]);

            $value = $restockedCost = 0.0;
            foreach ($lines as $line) {
                /** @var SaleItem|null $item */
                $item = $items->get($line['sale_item_id']);
                if (! $item) {
                    throw new BusinessException(__('A returned item does not belong to this sale.'));
                }
                $qty = round((float) $line['quantity'], 3);
                if ($qty <= 0) {
                    continue;
                }
                if ($qty - $item->returnableQuantity() > 0.0005) {
                    throw new BusinessException(__('You can return at most :quantity of :product.', ['quantity' => $item->returnableQuantity(), 'product' => $item->product_name]));
                }

                $lineValue = round($qty * $item->netUnitPrice(), 2);
                $return->items()->create([
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $qty,
                    'unit_price' => round($item->netUnitPrice(), 2),
                    'line_total' => $lineValue,
                    'condition' => $line['condition'],
                ]);

                if ($line['condition'] === 'restock') {
                    $this->stock->restore($item->product, $sale->branch_id, $item->batches ?? [], $qty, 'sale_return', $return, __('Return :return of :sale', ['return' => $return->number, 'sale' => $sale->number]));
                    $restockedCost += $qty * (float) $item->unit_cost;
                }

                $item->returned_quantity = round((float) $item->returned_quantity + $qty, 3);
                $item->save();
                $value += $lineValue;
            }

            if ($value <= 0) {
                throw new BusinessException(__('Enter a quantity for at least one item to return.'));
            }

            // A return first reduces what the customer still owes; the rest is refunded.
            $creditAmount = min($value, (float) $sale->balance);
            $refundAmount = round($value - $creditAmount, 2);

            $customer = $sale->customer_id ? Customer::query()->whereKey($sale->customer_id)->lockForUpdate()->first() : null;
            if ($creditAmount > 0 && $customer) {
                $customer->balance = round(max(0, (float) $customer->balance - $creditAmount), 2);
                $customer->save();
            }
            if ($refundAmount > self::EPSILON) {
                $txId = $refundMethod === 'savings'
                    ? $this->savings->refund($customer, $refundAmount, $sale, __('Return :number', ['number' => $return->number]))->id
                    : null;
                $this->refundPayment($sale, $refundAmount, $refundMethod, $user, __('Refund for return :number', ['number' => $return->number]), $txId);
            }

            $return->forceFill([
                'total' => round($value, 2),
                'credit_amount' => round($creditAmount, 2),
                'refund_amount' => $refundAmount,
            ])->saveQuietly();

            $newBalance = round((float) $sale->balance - $creditAmount, 2);
            $sale->forceFill([
                'returned_amount' => round((float) $sale->returned_amount + $value, 2),
                'balance' => max(0, $newBalance),
                'payment_status' => $newBalance > self::EPSILON ? $sale->payment_status : 'paid',
                'fully_paid_at' => $newBalance > self::EPSILON ? null : ($sale->fully_paid_at ?? now()),
            ])->save();

            $this->posting->saleReturn($return, $sale, round($restockedCost, 2));
            $this->audit->event('sale.returned', __('Return :return on sale :sale: :value', ['return' => $return->number, 'sale' => $sale->number, 'value' => number_format($value, 2)]), $return, ['reason' => $reason]);

            return $return->load('items.product');
        });
    }

    /**
     * Customer pays (part of) their debt. With sale_id the money goes to that
     * credit sale; without it, to the oldest unpaid sales first.
     * Every payment records the sale balance before and after it.
     *
     * @param  array{amount: float, method: string, provider?: ?string, reference?: ?string, sale_id?: ?int, notes?: ?string, paid_at?: ?string}  $data
     * @return Collection<int, Payment>
     */
    public function receiveDebtPayment(Customer $customer, array $data, User $user, int $branchId): Collection
    {
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new BusinessException(__('Payment amount must be greater than zero.'));
        }

        return DB::transaction(function () use ($customer, $data, $user, $branchId, $amount) {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $sales = Sale::query()
                ->where('customer_id', $customer->id)
                ->completed()
                ->where('balance', '>', 0)
                ->when(! empty($data['sale_id']), fn ($q) => $q->whereKey($data['sale_id']))
                ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_date')
                ->orderBy('sold_at')
                ->lockForUpdate()
                ->get();

            $owed = round((float) $sales->sum(fn ($s) => (float) $s->balance), 2);
            if ($owed <= 0) {
                throw new BusinessException(! empty($data['sale_id']) ? __('This sale has no outstanding balance.') : __('This customer has no unpaid sales.'));
            }
            if ($amount - $owed > self::EPSILON) {
                throw new BusinessException(__('The amount is more than the debt (:owed).', ['owed' => number_format($owed, 2)]), 'OVERPAYMENT');
            }

            $remaining = $amount;
            $payments = collect();
            foreach ($sales as $sale) {
                if ($remaining <= self::EPSILON) {
                    break;
                }
                $apply = round(min($remaining, (float) $sale->balance), 2);
                $before = (float) $sale->balance;
                $newBalance = round($before - $apply, 2);

                $txId = $data['method'] === 'savings' ? $this->savings->payDebt($customer, $apply, $sale, $branchId)->id : null;

                $payment = Payment::create([
                    'number' => $this->numbers->next('payment'),
                    'branch_id' => $branchId,
                    'customer_id' => $customer->id,
                    'sale_id' => $sale->id,
                    'savings_transaction_id' => $txId,
                    'type' => 'debt',
                    'amount' => $apply,
                    'balance_before' => $before,
                    'balance_after' => max(0, $newBalance),
                    'method' => $data['method'],
                    'provider' => $data['provider'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'paid_at' => $data['paid_at'] ?? now(),
                    'notes' => $data['notes'] ?? null,
                    'received_by' => $user->id,
                ]);
                $payments->push($payment);

                $sale->forceFill([
                    'amount_paid' => round((float) $sale->amount_paid + $apply, 2),
                    'balance' => max(0, $newBalance),
                    'payment_status' => $newBalance > self::EPSILON ? 'partial' : 'paid',
                    'fully_paid_at' => $newBalance > self::EPSILON ? null : now(),
                ])->save();

                $this->posting->debtPayment($payment, $sale);
                $remaining = round($remaining - $apply, 2);
            }

            $customer->balance = round(max(0, (float) $customer->balance - $amount), 2);
            $customer->save();

            $this->audit->event('customer.payment', __('Received :amount from :customer', ['amount' => number_format($amount, 2), 'customer' => $customer->name]), $customer, [
                'amount' => $amount, 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'sale_id' => $data['sale_id'] ?? null,
            ]);

            return $payments;
        });
    }

    /**
     * Pay a specific credit sale in full or in part.
     *
     * @param  array{mode: string, amount?: ?float, method: string, provider?: ?string, reference?: ?string, notes?: ?string}  $data
     */
    public function payCreditSale(Sale $sale, array $data, User $user, int $branchId): Payment
    {
        if (! $sale->customer_id) {
            throw new BusinessException(__('This sale has no customer to collect from.'));
        }
        if ($sale->status !== Sale::STATUS_COMPLETED || (float) $sale->balance <= 0) {
            throw new BusinessException(__('This sale has no outstanding balance.'));
        }
        // Full: the whole remaining balance. Partial: any amount up to it (more is refused as OVERPAYMENT).
        $amount = $data['mode'] === 'full' ? (float) $sale->balance : (float) ($data['amount'] ?? 0);

        return $this->receiveDebtPayment($sale->customer, ['amount' => $amount, 'sale_id' => $sale->id] + $data, $user, $branchId)->first();
    }

    // ----------------------------------------------------------------- helpers

    private function resolveCustomer(array $data, int $branchId): ?Customer
    {
        if (! empty($data['customer_id'])) {
            $customer = Customer::query()->whereKey($data['customer_id'])->lockForUpdate()->first();
            if (! $customer || ! $customer->is_active) {
                throw new BusinessException(__('The selected customer is not active.'));
            }

            return $customer;
        }

        if (! empty($data['new_customer']['phone'])) {
            $customer = $this->customers->findOrCreateForCredit($data['new_customer'], $branchId);

            return Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
        }

        return null;
    }

    private function saleDate(array $data, User $user, bool $enforce): Carbon
    {
        // Sold offline on the mobile app: the time on the phone, set by the sync (not by the client's payload).
        if (! empty($data['offline_sold_at'])) {
            $at = Carbon::parse($data['offline_sold_at']);
            $oldest = now()->subDays(max(1, $this->settings->int('mobile_session_days')) + 1);

            return $at->isFuture() ? now() : $at->max($oldest);
        }
        if (empty($data['sold_at'])) {
            return now();
        }
        if ($enforce && ! $user->hasPermission('pos.backdate')) {
            throw new BusinessException(__('You are not allowed to record sales on an earlier date.'), 'FORBIDDEN', 403);
        }
        $soldAt = Carbon::parse($data['sold_at']);
        if ($soldAt->isFuture()) {
            throw new BusinessException(__('The sale date cannot be in the future.'));
        }

        return $soldAt;
    }

    private function dueDate(array $data, Carbon $soldAt): string
    {
        if (empty($data['due_date'])) {
            throw new BusinessException(__('Enter the date the customer will pay the balance.'), 'DUE_DATE_REQUIRED');
        }
        $due = Carbon::parse($data['due_date'])->startOfDay();
        if ($due->lt($soldAt->copy()->startOfDay())) {
            throw new BusinessException(__('The repayment date cannot be before the sale date.'));
        }

        return $due->toDateString();
    }

    private function checkPricePermissions(User $user, Product $product, float $price, float $listPrice, float $lineDiscount, float $factor): void
    {
        if (abs($price - $listPrice) > self::EPSILON && ! $user->hasPermission('pos.change_price')) {
            throw new BusinessException(__('You are not allowed to change the price of :product.', ['product' => $product->name]), 'FORBIDDEN', 403);
        }
        if ($lineDiscount > 0 && ! $user->hasPermission('pos.discount')) {
            throw new BusinessException(__('You are not allowed to give discounts.'), 'FORBIDDEN', 403);
        }
        $minimum = $product->min_selling_price !== null ? (float) $product->min_selling_price * $factor : null;
        if ($minimum !== null && $price + self::EPSILON < $minimum && ! $user->hasPermission('pos.sell_below_minimum')) {
            throw new BusinessException(__(':product cannot be sold below :price.', ['product' => $product->name, 'price' => number_format($minimum, 2)]), 'BELOW_MINIMUM_PRICE');
        }
    }

    private function assertCreditAllowed(User $user, ?Customer $customer, float $balance, bool $checkPermission): void
    {
        if (! $customer) {
            throw new BusinessException(__('To sell on credit, choose a registered customer or enter the customer\'s name, phone number and address.'), 'CUSTOMER_REQUIRED');
        }
        if ($checkPermission && ! $user->hasPermission('pos.credit_sale')) {
            throw new BusinessException(__('You are not allowed to sell on credit. Collect the full amount.'), 'FORBIDDEN', 403);
        }
        if ($this->settings->bool('credit_requires_approval') && ! $customer->allow_credit) {
            throw new BusinessException(__(':customer is not allowed to buy on credit.', ['customer' => $customer->name]), 'CREDIT_NOT_ALLOWED');
        }
        $limit = (float) $customer->credit_limit;
        if ($limit > 0 && (float) $customer->balance + $balance - $limit > self::EPSILON) {
            throw new BusinessException(__(':customer would exceed the credit limit of :limit (current debt :debt).', [
                'customer' => $customer->name, 'limit' => number_format($limit, 2), 'debt' => number_format((float) $customer->balance, 2),
            ]), 'CREDIT_LIMIT');
        }
    }

    /**
     * @return array{0: float, 1: float} [amount applied to the sale, change given]
     */
    private function recordPayments(Sale $sale, array $payments, float $total, User $user, int $branchId, ?Customer $customer, ?Order $order = null): array
    {
        $tendered = round(array_sum(array_map(fn ($p) => (float) $p['amount'], $payments)), 2);
        $cash = round(array_sum(array_map(fn ($p) => $p['method'] === 'cash' ? (float) $p['amount'] : 0, $payments)), 2);
        $change = max(0, round($tendered - $total, 2));

        if ($change > 0 && $change - $cash > self::EPSILON) {
            throw new BusinessException(__('Only cash payments can be more than the total (to give change).'));
        }

        $changeLeft = $change;
        foreach ($payments as $payment) {
            $amount = round((float) $payment['amount'], 2);
            if ($payment['method'] === 'cash' && $changeLeft > 0) {
                $deduct = min($amount, $changeLeft);
                $amount = round($amount - $deduct, 2);
                $changeLeft = round($changeLeft - $deduct, 2);
            }
            if ($amount <= 0) {
                continue;
            }

            $txId = null;
            if ($payment['method'] === 'online') {
                // Payment by phone: only a payment the gateway confirmed, used once, for exactly this amount.
                if (empty($payment['online_payment_id'])) {
                    throw new BusinessException(__('Send the payment request to the customer\'s phone first.'), 'ONLINE_PAYMENT_REQUIRED');
                }
                $online = $this->online->useForSale((int) $payment['online_payment_id'], $amount, $sale, $branchId, $order);
                $payment['provider'] = $online->provider_name;
                $payment['reference'] = $online->external_reference ?? $online->reference;
                $payment['payer_phone'] = $online->phone;
            }
            if ($payment['method'] === 'savings') {
                if (! $customer) {
                    throw new BusinessException(__('Choose the customer whose savings will pay for this sale.'), 'CUSTOMER_REQUIRED');
                }
                $txId = $this->savings->spend($customer, $amount, $sale, $total)->id;
            }

            Payment::create([
                'number' => $this->numbers->next('payment'),
                'branch_id' => $branchId,
                'customer_id' => $customer?->id,
                'sale_id' => $sale->id,
                'savings_transaction_id' => $txId,
                'type' => 'sale',
                'amount' => $amount,
                'method' => $payment['method'],
                'provider' => $payment['provider'] ?? null,
                'reference' => $payment['reference'] ?? null,
                'payer_phone' => $payment['payer_phone'] ?? null,
                'paid_at' => $sale->sold_at,
                'received_by' => $user->id,
            ]);
        }

        return [round(min($tendered, $total), 2), $change];
    }

    private function refundPayment(Sale $sale, float $amount, string $method, User $user, string $note, ?int $savingsTransactionId = null): void
    {
        Payment::create([
            'number' => $this->numbers->next('payment'),
            'branch_id' => $sale->branch_id,
            'customer_id' => $sale->customer_id,
            'sale_id' => $sale->id,
            'savings_transaction_id' => $savingsTransactionId,
            'type' => 'refund',
            'amount' => -abs($amount),
            'method' => $method,
            'paid_at' => now(),
            'notes' => $note,
            'received_by' => $user->id,
        ]);
    }

    private function paymentStatus(float $total, float $paid): string
    {
        if ($total - $paid <= self::EPSILON) {
            return 'paid';
        }

        return $paid > self::EPSILON ? 'partial' : 'unpaid';
    }
}

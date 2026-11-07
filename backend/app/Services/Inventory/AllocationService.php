<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Product;
use App\Models\Core\User;
use App\Models\Inventory\Allocation;
use App\Models\Inventory\AllocationItem;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use App\Services\Finance\PostingService;
use App\Services\Sales\SaleService;
use Illuminate\Support\Facades\DB;

/**
 * Seller allocations: stock is handed to a seller (field agent, hawker, outside
 * counter). While out, the seller can record each sale in the system (a real
 * sale with a receipt, paid in full). When the seller comes back the allocation
 * is reconciled:
 *   sold = issued - returned - damaged
 * of which the part the seller did not record becomes one sale now. Returned
 * goods go back into stock and money not handed in for that unrecorded part
 * is recorded as the seller's shortage.
 */
class AllocationService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly NumberGenerator $numbers,
        private readonly SaleService $sales,
        private readonly AuditLogger $audit,
        private readonly PostingService $posting,
    ) {}

    public function issue(array $data, User $user, int $branchId): Allocation
    {
        $seller = User::query()->whereKey($data['seller_id'])->where('type', User::TYPE_STAFF)->where('is_active', true)->first();
        if (! $seller) {
            throw new BusinessException(__('The selected seller is not an active staff member.'));
        }

        return DB::transaction(function () use ($data, $branchId, $seller) {
            $products = Product::query()->whereIn('id', collect($data['items'])->pluck('product_id'))->get()->keyBy('id');

            $allocation = Allocation::create([
                'number' => $this->numbers->next('allocation'),
                'branch_id' => $branchId,
                'seller_id' => $seller->id,
                'status' => 'open',
                'issued_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $expected = 0.0;
            foreach ($data['items'] as $line) {
                $product = $products->get($line['product_id']);
                if (! $product || ! $product->is_active) {
                    throw new BusinessException(__('A selected product is not available.'));
                }
                $qty = round((float) $line['quantity'], 3);
                $this->stock->assertQuantity($product, $qty);
                $consumed = $this->stock->decrease($product, $branchId, $qty, 'allocation_out', $allocation, [
                    'note' => "{$allocation->number} to {$seller->name}",
                    'allow_expired' => false,
                ]);
                $price = round((float) ($line['unit_price'] ?? $product->selling_price), 2);

                AllocationItem::create([
                    'allocation_id' => $allocation->id,
                    'product_id' => $product->id,
                    'quantity_issued' => $qty,
                    'unit_price' => $price,
                    'unit_cost' => $this->stock->averageCost($consumed),
                    'batches' => $consumed,
                ]);
                $expected += $qty * $price;
            }

            $allocation->forceFill(['expected_amount' => round($expected, 2)])->saveQuietly();
            $this->audit->event('allocation.issued', "Allocation {$allocation->number} issued to {$seller->name}: ".number_format($expected, 2), $allocation);

            return $allocation->load(['items.product', 'seller']);
        });
    }

    /**
     * The seller sells part of an open allocation: a normal sale with a receipt,
     * paid in full now (the goods left stock when the allocation was issued).
     *
     * @param  array{items: array<int, array{allocation_item_id: int, quantity: float}>, customer_id?: ?int, customer_name?: ?string, customer_phone?: ?string, payments: array<int, array{method: string, amount: float, provider?: ?string, reference?: ?string}>, notes?: ?string}  $data
     */
    public function sell(Allocation $allocation, array $data, User $seller): \App\Models\Sales\Sale
    {
        return DB::transaction(function () use ($allocation, $data, $seller) {
            $allocation = Allocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            if ($allocation->status !== 'open') {
                throw new BusinessException(__('This allocation is already :status.', ['status' => __($allocation->status)]));
            }
            $items = $allocation->items()->with('product')->get()->keyBy('id');
            $lines = [];
            $total = 0.0;
            foreach ($data['items'] as $line) {
                $item = $items->get((int) $line['allocation_item_id']);
                if (! $item) {
                    throw new BusinessException(__('A selected product is not part of this allocation.'));
                }
                $qty = round((float) $line['quantity'], 3);
                $this->stock->assertQuantity($item->product, $qty);
                $left = round((float) $item->quantity_issued - (float) $item->quantity_recorded, 3);
                if ($qty <= 0 || $qty - $left > 0.0005) {
                    throw new BusinessException(__('Only :left of :product is left in this allocation.', ['left' => 0 + $left, 'product' => $item->product->name]));
                }
                $item->forceFill(['quantity_recorded' => round((float) $item->quantity_recorded + $qty, 3)])->save();
                $lines[] = [
                    'product_id' => $item->product_id,
                    'quantity' => $qty,
                    'unit_price' => (float) $item->unit_price,
                    'consumed' => [['batch_id' => null, 'quantity' => $qty, 'unit_cost' => (float) $item->unit_cost, 'expiry_date' => null, 'batch_no' => null]],
                ];
                $total += $qty * (float) $item->unit_price;
            }
            $total = round($total, 2);
            $paid = round(collect($data['payments'])->sum(fn ($p) => (float) $p['amount']), 2);
            if ($paid + 0.005 < $total) {
                throw new BusinessException(__('Take the full amount (:total). Sales from an allocation cannot be on credit.', ['total' => number_format($total, 2)]));
            }

            $sale = $this->sales->create([
                'items' => $lines,
                'channel' => 'allocation',
                'seller_id' => $allocation->seller_id,
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'payments' => $data['payments'],
                'notes' => trim(($data['notes'] ?? '').' ('.__('Allocation :number', ['number' => $allocation->number]).')'),
                'send_sms' => false,
            ], $seller, $allocation->branch_id, [
                'enforce_permissions' => false,
                'source' => $allocation,
            ]);

            $allocation->forceFill([
                'recorded_amount' => round((float) $allocation->recorded_amount + (float) $sale->total, 2),
                'recorded_sales' => (int) $allocation->recorded_sales + 1,
            ])->save();

            return $sale;
        });
    }

    /**
     * @param  array{items: array<int, array{allocation_item_id: int, quantity_returned: float, quantity_damaged?: float}>, amount_received: float, payment_method?: string, payment_reference?: ?string, notes?: ?string}  $data
     */
    public function reconcile(Allocation $allocation, array $data, User $user): Allocation
    {
        return DB::transaction(function () use ($allocation, $data, $user) {
            $allocation = Allocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            if ($allocation->status !== 'open') {
                throw new BusinessException(__('This allocation is already :status.', ['status' => __($allocation->status)]));
            }

            $items = $allocation->items()->with('product')->get()->keyBy('id');
            $input = collect($data['items'])->keyBy('allocation_item_id');
            $saleLines = [];
            $damagedValue = 0.0;
            $soldAmount = 0.0;

            foreach ($items as $item) {
                $returned = round((float) ($input[$item->id]['quantity_returned'] ?? 0), 3);
                $damaged = round((float) ($input[$item->id]['quantity_damaged'] ?? 0), 3);
                $issued = (float) $item->quantity_issued;
                $recorded = (float) $item->quantity_recorded;
                if ($returned < 0 || $damaged < 0 || $returned + $damaged + $recorded - $issued > 0.0005) {
                    throw new BusinessException($recorded > 0
                        ? __('Returned + damaged for :product cannot be more than the :left not yet sold.', ['product' => $item->product->name, 'left' => 0 + round($issued - $recorded, 3)])
                        : __('Returned + damaged for :product cannot be more than the :issued issued.', ['product' => $item->product->name, 'issued' => $issued]));
                }
                // Sold but not recorded by the seller: becomes one sale now.
                $sold = round($issued - $returned - $damaged - $recorded, 3);
                $damagedValue += $damaged * (float) $item->unit_cost;

                if ($returned > 0) {
                    $this->stock->restore($item->product, $allocation->branch_id, $item->batches ?? [], $returned, 'allocation_return', $allocation, "Returned by seller ({$allocation->number})");
                }

                $item->forceFill([
                    'quantity_returned' => $returned,
                    'quantity_damaged' => $damaged,
                    'quantity_sold' => round($sold + $recorded, 3),
                ])->save();

                if ($sold > 0) {
                    $saleLines[] = [
                        'product_id' => $item->product_id,
                        'quantity' => $sold,
                        'unit_price' => (float) $item->unit_price,
                        // Already out of stock since the allocation was issued.
                        'consumed' => [['batch_id' => null, 'quantity' => $sold, 'unit_cost' => (float) $item->unit_cost, 'expiry_date' => null, 'batch_no' => null]],
                    ];
                    $soldAmount += $sold * (float) $item->unit_price;
                }
            }

            $soldAmount = round($soldAmount, 2);
            $received = round((float) $data['amount_received'], 2);
            if ($received - $soldAmount > 0.005) {
                throw new BusinessException(__('Amount received (:received) is more than the value sold (:sold).', ['received' => number_format($received, 2), 'sold' => number_format($soldAmount, 2)]));
            }

            $sale = null;
            if ($saleLines !== []) {
                $sale = $this->sales->create([
                    'items' => $saleLines,
                    'channel' => 'allocation',
                    'seller_id' => $allocation->seller_id,
                    'customer_name' => 'Allocation '.$allocation->number,
                    'payments' => $received > 0 ? [[
                        'method' => $data['payment_method'] ?? 'cash',
                        'amount' => $received,
                        'reference' => $data['payment_reference'] ?? null,
                    ]] : [],
                    'notes' => "Sold by seller under allocation {$allocation->number}",
                    'send_sms' => false,
                ], $user, $allocation->branch_id, [
                    'enforce_permissions' => false,
                    'source' => $allocation,
                    // A shortage is the seller's debt, not a customer credit sale.
                    'allow_shortage' => true,
                ]);
            }

            $allocation->forceFill([
                'status' => 'reconciled',
                'sold_amount' => round($soldAmount + (float) $allocation->recorded_amount, 2),
                'amount_received' => $received,
                'shortage' => round($soldAmount - $received, 2),
                'reconciled_at' => now(),
                'reconciled_by' => $user->id,
                'reconcile_notes' => $data['notes'] ?? null,
                'sale_id' => $sale?->id,
            ])->save();

            $this->posting->allocationDamages($allocation, round($damagedValue, 2));
            $this->audit->event('allocation.reconciled', "Allocation {$allocation->number} reconciled. Sold ".number_format($soldAmount, 2).', received '.number_format($received, 2), $allocation);

            return $allocation->load(['items.product', 'seller', 'sale']);
        });
    }

    public function cancel(Allocation $allocation, string $reason): Allocation
    {
        return DB::transaction(function () use ($allocation, $reason) {
            $allocation = Allocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            if ($allocation->status !== 'open') {
                throw new BusinessException(__('Only open allocations can be cancelled.'));
            }
            if ((int) $allocation->recorded_sales > 0) {
                throw new BusinessException(__('The seller has already sold from this allocation. Reconcile it instead.'));
            }
            foreach ($allocation->items()->with('product')->get() as $item) {
                $this->stock->restore($item->product, $allocation->branch_id, $item->batches ?? [], (float) $item->quantity_issued, 'allocation_return', $allocation, "Cancelled {$allocation->number}");
            }
            $allocation->auditReason = $reason;
            $allocation->forceFill(['status' => 'cancelled', 'reconcile_notes' => $reason])->save();

            return $allocation->load(['items.product', 'seller']);
        });
    }
}

<?php

namespace App\Services\Purchasing;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductUnit;
use App\Models\Core\User;
use App\Models\Purchasing\Purchase;
use App\Models\Purchasing\PurchaseItem;
use App\Models\Purchasing\PurchaseReturn;
use App\Models\Purchasing\Supplier;
use App\Models\Purchasing\SupplierPayment;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use App\Services\Finance\PostingService;
use App\Services\Inventory\StockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    private const EPSILON = 0.005;

    public function __construct(
        private readonly StockService $stock,
        private readonly NumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly PostingService $posting,
    ) {}

    /**
     * Lines may be entered in packs (product_unit_id): convert to base units.
     *
     * @return array<int, array{product: Product, unit: ?ProductUnit, factor: float, unit_quantity: float, quantity: float, line_total: float}+array<string, mixed>>
     */
    private function normalizeLines(array $lines, Collection $products, Collection $units): array
    {
        return array_map(function (array $line) use ($products, $units) {
            $product = $products->get($line['product_id']) ?? throw new BusinessException(__('One of the products no longer exists.'));
            $unit = ! empty($line['product_unit_id']) ? $units->get($line['product_unit_id']) : null;
            if ($unit && (int) $unit->product_id !== (int) $product->id) {
                throw new BusinessException(__('The selected unit does not belong to :product.', ['product' => $product->name]));
            }
            $factor = $unit ? (float) $unit->factor : 1.0;
            $unitQuantity = round((float) $line['quantity'], 3);

            return array_merge($line, [
                'product' => $product,
                'unit' => $unit,
                'factor' => $factor,
                'unit_quantity' => $unitQuantity,
                'quantity' => round($unitQuantity * $factor, 3),
                'line_total' => round($unitQuantity * (float) $line['unit_cost'], 2),
            ]);
        }, $lines);
    }

    /**
     * Receive goods. Extra costs (tax, loading, unloading, transport, other) are
     * spread over the items by value or by quantity to get the landed unit cost,
     * which becomes the batch cost used for profit calculations.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user, int $branchId, bool $creditApproved = false): Purchase
    {
        $lines = $data['items'];

        return DB::transaction(function () use ($data, $user, $branchId, $lines, $creditApproved) {
            $products = Product::query()->whereIn('id', collect($lines)->pluck('product_id'))->get()->keyBy('id');
            $units = ProductUnit::query()->whereIn('id', collect($lines)->pluck('product_unit_id')->filter())->get()->keyBy('id');
            $lines = $this->normalizeLines($lines, $products, $units);
            $supplier = ! empty($data['supplier_id']) ? Supplier::query()->whereKey($data['supplier_id'])->lockForUpdate()->first() : null;

            $subtotal = 0.0;
            $totalQty = 0.0;
            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);
                if ($product->is_service) {
                    throw new BusinessException(__(':product is a service and has no stock.', ['product' => $product->name]));
                }
                $this->stock->assertQuantity($product, (float) $line['quantity']);
                if ($product->track_expiry && empty($line['expiry_date'])) {
                    throw new BusinessException(__('Enter the expiry date for :product.', ['product' => $product->name]));
                }
                $subtotal += $line['line_total'];
                $totalQty += (float) $line['quantity'];
            }

            $costs = [
                'tax_cost' => round((float) ($data['tax_cost'] ?? 0), 2),
                'loading_cost' => round((float) ($data['loading_cost'] ?? 0), 2),
                'unloading_cost' => round((float) ($data['unloading_cost'] ?? 0), 2),
                'transport_cost' => round((float) ($data['transport_cost'] ?? 0), 2),
                'other_cost' => round((float) ($data['other_cost'] ?? 0), 2),
            ];
            $extras = array_sum($costs);
            $allocation = $data['cost_allocation'] ?? 'value';

            $amountPaid = round((float) ($data['amount_paid'] ?? $subtotal), 2);
            if ($amountPaid - $subtotal > self::EPSILON) {
                throw new BusinessException(__('The amount paid is more than the purchase amount.'));
            }
            $balance = round($subtotal - $amountPaid, 2);
            if ($balance > self::EPSILON) {
                if (! $supplier) {
                    throw new BusinessException(__('Select the supplier for a purchase that is not fully paid.'));
                }
                // A credit purchase plan approved earlier already allowed the credit.
                if (! $creditApproved && ! $user->hasPermission('purchases.credit')) {
                    throw new BusinessException(__('You are not allowed to record purchases on credit.'), 'FORBIDDEN', 403);
                }
            }

            $purchase = Purchase::create(array_merge($costs, [
                'number' => $this->numbers->next('purchase'),
                'branch_id' => $branchId,
                'supplier_id' => $supplier?->id,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'purchase_date' => $data['purchase_date'] ?? now()->toDateString(),
                'cost_allocation' => $allocation,
                'subtotal' => round($subtotal, 2),
                'total' => round($subtotal + $extras, 2),
                'supplier_total' => round($subtotal, 2),
                'amount_paid' => $amountPaid,
                'balance' => max(0, $balance),
                'payment_status' => $balance > self::EPSILON ? ($amountPaid > 0 ? 'partial' : 'unpaid') : 'paid',
                'due_date' => $balance > self::EPSILON ? ($data['due_date'] ?? now()->addDays($supplier?->payment_terms_days ?: 30)->toDateString()) : null,
                'notes' => $data['notes'] ?? null,
            ]));

            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);
                $qty = $line['quantity'];
                $unitCost = round($line['line_total'] / max($qty, 0.001), 2);
                $lineTotal = $line['line_total'];

                $share = 0.0;
                if ($extras > 0) {
                    $share = $allocation === 'quantity'
                        ? $extras * ($qty / max($totalQty, 0.001))
                        : ($subtotal > 0 ? $extras * ($lineTotal / $subtotal) : $extras * ($qty / max($totalQty, 0.001)));
                }
                $extraPerUnit = $qty > 0 ? round($share / $qty, 2) : 0.0;
                $landed = round($unitCost + $extraPerUnit, 2);

                $batch = $this->stock->increase($product, $branchId, $qty, $landed, 'purchase', $purchase, [
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'batch_no' => $line['batch_no'] ?? null,
                    'note' => $purchase->number.($purchase->supplier_invoice_no ? " / {$purchase->supplier_invoice_no}" : ''),
                ]);

                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $product->id,
                    'product_unit_id' => $line['unit']?->id,
                    'unit_name' => $line['unit']?->name ?? $product->unit?->short_name,
                    'unit_factor' => $line['factor'],
                    'unit_quantity' => $line['unit_quantity'],
                    'stock_batch_id' => $batch?->id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'extra_cost_per_unit' => $extraPerUnit,
                    'landed_unit_cost' => $landed,
                    'line_total' => $lineTotal,
                    'selling_price' => $line['selling_price'] ?? null,
                    'batch_no' => $line['batch_no'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null,
                ]);

                $updates = ['cost_price' => $landed];
                if (isset($line['selling_price']) && $line['selling_price'] !== null && $line['selling_price'] !== '') {
                    // A new selling price entered against a pack updates that pack's price.
                    if ($line['unit']) {
                        $line['unit']->fill(['retail_price' => round((float) $line['selling_price'], 2)])->save();
                    } else {
                        $updates['selling_price'] = round((float) $line['selling_price'], 2);
                    }
                }
                $product->auditReason = "Purchase {$purchase->number}";
                $product->fill($updates)->save();
            }

            if ($amountPaid > 0) {
                SupplierPayment::create([
                    'number' => $this->numbers->next('supplier_payment'),
                    'supplier_id' => $supplier?->id ?? $this->cashSupplierId(),
                    'purchase_id' => $purchase->id,
                    'branch_id' => $branchId,
                    'amount' => $amountPaid,
                    'method' => $data['payment_method'] ?? 'cash',
                    'reference' => $data['payment_reference'] ?? null,
                    'paid_on' => $purchase->purchase_date,
                ]);
            }

            if ($supplier && $balance > self::EPSILON) {
                $supplier->balance = round((float) $supplier->balance + $balance, 2);
                $supplier->save();
            }

            $this->posting->purchase($purchase, $data['payment_method'] ?? 'cash');
            $this->audit->event('purchase.received', "Purchase {$purchase->number} received: ".number_format($subtotal + $extras, 2), $purchase);

            return $purchase->load(['items.product', 'supplier', 'payments']);
        });
    }

    /**
     * @param  array{amount: float, method: string, reference?: ?string, purchase_id?: ?int, paid_on?: ?string, notes?: ?string}  $data
     * @return Collection<int, SupplierPayment>
     */
    public function paySupplier(Supplier $supplier, array $data, ?int $branchId): Collection
    {
        $amount = round((float) $data['amount'], 2);

        return DB::transaction(function () use ($supplier, $data, $branchId, $amount) {
            $supplier = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $purchases = Purchase::query()
                ->where('supplier_id', $supplier->id)
                ->where('balance', '>', 0)
                ->when(! empty($data['purchase_id']), fn ($q) => $q->whereKey($data['purchase_id']))
                ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_date')->orderBy('purchase_date')
                ->lockForUpdate()->get();

            $owed = round((float) $purchases->sum(fn ($p) => (float) $p->balance), 2);
            if ($owed <= 0) {
                throw new BusinessException(__('Nothing is owed to this supplier.'));
            }
            if ($amount - $owed > self::EPSILON) {
                throw new BusinessException(__('The amount is more than what is owed (:owed).', ['owed' => number_format($owed, 2)]));
            }

            $remaining = $amount;
            $payments = collect();
            foreach ($purchases as $purchase) {
                if ($remaining <= self::EPSILON) {
                    break;
                }
                $apply = round(min($remaining, (float) $purchase->balance), 2);
                $payments->push($payment = SupplierPayment::create([
                    'number' => $this->numbers->next('supplier_payment'),
                    'supplier_id' => $supplier->id,
                    'purchase_id' => $purchase->id,
                    'branch_id' => $branchId ?? $purchase->branch_id,
                    'amount' => $apply,
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'paid_on' => $data['paid_on'] ?? now()->toDateString(),
                    'notes' => $data['notes'] ?? null,
                ]));
                $this->posting->supplierPayment($payment);
                $newBalance = round((float) $purchase->balance - $apply, 2);
                $purchase->forceFill([
                    'amount_paid' => round((float) $purchase->amount_paid + $apply, 2),
                    'balance' => max(0, $newBalance),
                    'payment_status' => $newBalance > self::EPSILON ? 'partial' : 'paid',
                ])->save();
                $remaining = round($remaining - $apply, 2);
            }

            $supplier->balance = round((float) $supplier->balance - $amount, 2);
            $supplier->save();

            $this->audit->event('supplier.payment', 'Paid '.number_format($amount, 2)." to {$supplier->name}", $supplier, $data);

            return $payments;
        });
    }

    /**
     * Send goods back to the supplier. Stock leaves the exact batch the purchase created.
     *
     * @param  array<int, array{purchase_item_id: int, quantity: float}>  $lines
     */
    public function returnToSupplier(Purchase $purchase, array $lines, string $reason): PurchaseReturn
    {
        return DB::transaction(function () use ($purchase, $lines, $reason) {
            $purchase = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $items = $purchase->items()->with('product')->get()->keyBy('id');

            $return = PurchaseReturn::create([
                'number' => $this->numbers->next('purchase_return'),
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'branch_id' => $purchase->branch_id,
                'reason' => $reason,
            ]);

            $total = $landedValue = 0.0;
            foreach ($lines as $line) {
                /** @var PurchaseItem|null $item */
                $item = $items->get($line['purchase_item_id']);
                $qty = round((float) $line['quantity'], 3);
                if (! $item || $qty <= 0) {
                    continue;
                }
                $returnable = round((float) $item->quantity - (float) $item->returned_quantity, 3);
                if ($qty - $returnable > 0.0005) {
                    throw new BusinessException(__('At most :returnable of :product can be returned.', ['returnable' => $returnable, 'product' => $item->product->name]));
                }

                $this->stock->decrease($item->product, $purchase->branch_id, $qty, 'purchase_return', $return, [
                    'batch_id' => $item->stock_batch_id,
                    'allow_expired' => true,
                    'allow_negative' => false,
                    'note' => "Return {$return->number}: {$reason}",
                ]);

                $value = round($qty * (float) $item->unit_cost, 2);
                $return->items()->create([
                    'purchase_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $qty,
                    'unit_cost' => $item->unit_cost,
                    'line_total' => $value,
                ]);
                $item->returned_quantity = round((float) $item->returned_quantity + $qty, 3);
                $item->save();
                $total += $value;
                $landedValue += round($qty * (float) $item->landed_unit_cost, 2);
            }

            if ($total <= 0) {
                throw new BusinessException(__('Enter a quantity for at least one item to return.'));
            }

            $return->forceFill(['total' => round($total, 2)])->saveQuietly();

            $newBalance = round((float) $purchase->balance - $total, 2);
            $purchase->forceFill([
                'returned_amount' => round((float) $purchase->returned_amount + $total, 2),
                'supplier_total' => round((float) $purchase->supplier_total - $total, 2),
                'balance' => max(0, $newBalance),
                'payment_status' => $newBalance > self::EPSILON ? $purchase->payment_status : 'paid',
            ])->save();

            if ($purchase->supplier_id) {
                // Reduces what we owe; may go negative = the supplier owes us a refund / credit note.
                $supplier = Supplier::query()->whereKey($purchase->supplier_id)->lockForUpdate()->first();
                $supplier->balance = round((float) $supplier->balance - $total, 2);
                $supplier->save();
            }

            $this->posting->purchaseReturn($return, $purchase, round($landedValue, 2));
            $this->audit->event('purchase.returned', 'Returned goods worth '.number_format($total, 2)." on {$purchase->number}: {$reason}", $return);

            return $return->load('items.product');
        });
    }

    /** Cash purchases without a supplier are booked against a "Cash purchases" supplier. */
    private function cashSupplierId(): int
    {
        return Supplier::query()->firstOrCreate(
            ['code' => 'SUP-CASH'],
            ['name' => 'Cash purchases (no supplier)', 'is_active' => true],
        )->id;
    }
}

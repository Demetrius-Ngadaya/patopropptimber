<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Product;
use App\Models\Inventory\ProductStock;
use App\Models\Inventory\StockBatch;
use App\Models\Inventory\StockMovement;
use App\Services\Core\SettingsService;
use App\Services\Notifications\StockAlerts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The only place where stock quantities change.
 *
 * - product_stocks holds the on-hand quantity per product per branch.
 * - stock_batches hold what was received, at what cost, with which expiry date.
 *   Stock leaves batches first-expiry-first-out (then oldest first).
 * - stock_movements is the permanent ledger of every change.
 */
class StockService
{
    private const EPSILON = 0.0005;

    public function __construct(private readonly SettingsService $settings) {}

    /** Validate a quantity against the product's unit rules. */
    public function assertQuantity(Product $product, float $quantity): void
    {
        if ($quantity <= 0) {
            throw new BusinessException(__('Quantity for :product must be greater than zero.', ['product' => $product->name]));
        }
        if (! $product->allow_decimal && abs($quantity - round($quantity)) > self::EPSILON) {
            throw new BusinessException(__(':product is sold in whole units only.', ['product' => $product->name]));
        }
    }

    public function onHand(int $productId, int $branchId): float
    {
        return (float) (ProductStock::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->value('quantity') ?? 0);
    }

    /**
     * Put stock into a branch. Creates a new batch unless `batch_id` is given
     * (used when returning goods to the batch they came from).
     *
     * @param  array{expiry_date?: ?string, batch_no?: ?string, note?: ?string, batch_id?: ?int, received_at?: mixed, source_type?: string, source_id?: ?int}  $options
     */
    public function increase(Product $product, int $branchId, float $quantity, float $unitCost, string $type, ?Model $reference = null, array $options = []): ?StockBatch
    {
        if ($product->is_service) {
            return null;
        }

        $quantity = round($quantity, 3);
        if ($quantity <= 0) {
            throw new BusinessException(__('Quantity to add must be greater than zero.'));
        }

        if ($product->track_expiry && empty($options['expiry_date']) && empty($options['batch_id'])) {
            throw new BusinessException(__(':product tracks expiry dates. Please enter the expiry date.', ['product' => $product->name]));
        }

        return DB::transaction(function () use ($product, $branchId, $quantity, $unitCost, $type, $reference, $options) {
            $stock = $this->lockStock($product->id, $branchId);

            $batch = null;
            if (! empty($options['batch_id'])) {
                $batch = StockBatch::query()->whereKey($options['batch_id'])
                    ->where('product_id', $product->id)->where('branch_id', $branchId)
                    ->lockForUpdate()->first();
            }

            if ($batch) {
                $batch->quantity_remaining = round((float) $batch->quantity_remaining + $quantity, 3);
                $batch->save();
            } else {
                $batch = StockBatch::create([
                    'product_id' => $product->id,
                    'branch_id' => $branchId,
                    'batch_no' => $options['batch_no'] ?? null,
                    'expiry_date' => $options['expiry_date'] ?? null,
                    'unit_cost' => round($unitCost, 2),
                    'quantity_received' => $quantity,
                    'quantity_remaining' => $quantity,
                    'source_type' => $options['source_type'] ?? $type,
                    'source_id' => $options['source_id'] ?? $reference?->getKey(),
                    'received_at' => $options['received_at'] ?? now(),
                ]);
            }

            $stock->quantity = round((float) $stock->quantity + $quantity, 3);
            $stock->save();

            $this->movement($product->id, $branchId, $batch->id, $type, $quantity, (float) $stock->quantity, (float) $batch->unit_cost, $reference, $options['note'] ?? null);

            return $batch;
        });
    }

    /**
     * Take stock out of a branch.
     *
     * @param  array{note?: ?string, batch_id?: ?int, allow_expired?: bool, allow_negative?: bool}  $options
     * @return array<int, array{batch_id: ?int, quantity: float, unit_cost: float, expiry_date: ?string, batch_no: ?string}> batches consumed
     */
    public function decrease(Product $product, int $branchId, float $quantity, string $type, ?Model $reference = null, array $options = []): array
    {
        if ($product->is_service) {
            return [['batch_id' => null, 'quantity' => round($quantity, 3), 'unit_cost' => (float) $product->cost_price, 'expiry_date' => null, 'batch_no' => null]];
        }

        $quantity = round($quantity, 3);
        if ($quantity <= 0) {
            throw new BusinessException(__('Quantity to remove must be greater than zero.'));
        }

        return DB::transaction(function () use ($product, $branchId, $quantity, $type, $reference, $options) {
            $stock = $this->lockStock($product->id, $branchId);
            $allowNegative = $options['allow_negative'] ?? $this->settings->bool('allow_negative_stock');
            $allowExpired = $options['allow_expired'] ?? ! $this->settings->bool('block_expired_sales');

            $batchQuery = StockBatch::query()
                ->where('product_id', $product->id)
                ->where('branch_id', $branchId)
                ->where('quantity_remaining', '>', 0);

            if (! empty($options['batch_id'])) {
                $batchQuery->whereKey($options['batch_id']);
            } elseif (! $allowExpired) {
                $batchQuery->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()));
            }

            $batches = $batchQuery
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expiry_date')
                ->orderBy('received_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $available = round((float) $batches->sum(fn ($b) => (float) $b->quantity_remaining), 3);
            if ($available + self::EPSILON < $quantity && ! $allowNegative) {
                $unit = $product->unit?->short_name ?? '';
                $onHand = round((float) $stock->quantity, 3);
                $hint = ! $allowExpired && $onHand > $available ? ' '.__('(some stock is expired and cannot be sold)') : '';
                throw new BusinessException(
                    __('Not enough stock for :product. Available: :available :unit, requested: :requested :unit:hint.', ['product' => $product->name, 'available' => 0 + $available, 'requested' => 0 + $quantity, 'unit' => $unit, 'hint' => $hint]),
                    'INSUFFICIENT_STOCK'
                );
            }

            $remaining = $quantity;
            $consumed = [];
            foreach ($batches as $batch) {
                if ($remaining <= self::EPSILON) {
                    break;
                }
                $take = min($remaining, (float) $batch->quantity_remaining);
                $batch->quantity_remaining = round((float) $batch->quantity_remaining - $take, 3);
                $batch->save();
                $remaining = round($remaining - $take, 3);

                $consumed[] = [
                    'batch_id' => $batch->id,
                    'quantity' => round($take, 3),
                    'unit_cost' => (float) $batch->unit_cost,
                    'expiry_date' => $batch->expiry_date?->toDateString(),
                    'batch_no' => $batch->batch_no,
                ];
            }

            if ($remaining > self::EPSILON) {
                // Only reachable when negative stock is allowed.
                $consumed[] = ['batch_id' => null, 'quantity' => $remaining, 'unit_cost' => (float) $product->cost_price, 'expiry_date' => null, 'batch_no' => null];
            }

            $before = (float) $stock->quantity;
            $stock->quantity = round($before - $quantity, 3);
            $stock->save();
            app(StockAlerts::class)->afterDecrease($product, $branchId, $before, (float) $stock->quantity);

            foreach ($consumed as $part) {
                $this->movement($product->id, $branchId, $part['batch_id'], $type, -$part['quantity'], (float) $stock->quantity, $part['unit_cost'], $reference, $options['note'] ?? null);
            }

            return $consumed;
        });
    }

    /**
     * Put back stock that was previously taken out (sale cancel / return / transfer cancel),
     * into the same batches so cost and expiry are preserved.
     *
     * @param  array<int, array{batch_id: ?int, quantity: float, unit_cost: float}>  $consumed
     */
    public function restore(Product $product, int $branchId, array $consumed, float $quantity, string $type, ?Model $reference = null, ?string $note = null): void
    {
        if ($product->is_service) {
            return;
        }

        $remaining = round($quantity, 3);
        foreach (array_reverse($consumed) as $part) {
            if ($remaining <= self::EPSILON) {
                break;
            }
            $put = min($remaining, (float) $part['quantity']);
            $batchExists = $part['batch_id'] && StockBatch::query()->whereKey($part['batch_id'])->exists();

            $this->increase($product, $branchId, $put, (float) $part['unit_cost'], $type, $reference, [
                'batch_id' => $batchExists ? $part['batch_id'] : null,
                'expiry_date' => $part['expiry_date'] ?? null,
                'note' => $note,
                'source_type' => $type,
            ]);
            $remaining = round($remaining - $put, 3);
        }

        if ($remaining > self::EPSILON) {
            $this->increase($product, $branchId, $remaining, (float) $product->cost_price, $type, $reference, [
                'note' => $note,
                'expiry_date' => $product->track_expiry ? now()->addYear()->toDateString() : null,
            ]);
        }
    }

    /** Weighted average cost of consumed batches. */
    public function averageCost(array $consumed): float
    {
        $qty = array_sum(array_column($consumed, 'quantity'));
        if ($qty <= 0) {
            return 0.0;
        }
        $value = array_sum(array_map(fn ($p) => $p['quantity'] * $p['unit_cost'], $consumed));

        return round($value / $qty, 2);
    }

    /** Lock (and create if missing) the stock row so concurrent sales cannot oversell. */
    private function lockStock(int $productId, int $branchId): ProductStock
    {
        ProductStock::query()->firstOrCreate(['product_id' => $productId, 'branch_id' => $branchId], ['quantity' => 0]);

        return ProductStock::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function movement(int $productId, int $branchId, ?int $batchId, string $type, float $quantity, float $balanceAfter, float $unitCost, ?Model $reference, ?string $note): void
    {
        StockMovement::create([
            'product_id' => $productId,
            'branch_id' => $branchId,
            'stock_batch_id' => $batchId,
            'type' => $type,
            'quantity' => round($quantity, 3),
            'balance_after' => round($balanceAfter, 3),
            'unit_cost' => round($unitCost, 2),
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'note' => $note,
            'user_id' => Auth::id(),
        ]);
    }
}

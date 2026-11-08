<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Product;
use App\Models\Core\Branch;
use App\Models\Inventory\ProductStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-branch prices. A branch may have its own buying (cost), selling, wholesale and
 * minimum price for a product; anything left empty falls back to the product's price.
 *
 * apply() puts a branch's prices onto loaded Product models in memory, so pricing,
 * the till, sales and the mobile app use them without knowing about branches. The
 * values are marked as unchanged, so saving such a model never copies a branch price
 * onto the product itself.
 */
class BranchPrices
{
    public const FIELDS = ['cost_price', 'selling_price', 'wholesale_price', 'min_selling_price'];

    /**
     * @param  Product|iterable<Product>|null  $products
     */
    public function apply(Product|iterable|null $products, ?int $branchId): void
    {
        if (! $branchId || $products === null) {
            return;
        }
        $list = $products instanceof Product ? collect([$products]) : collect($products);
        if ($list->isEmpty()) {
            return;
        }

        $rows = ProductStock::query()
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $list->pluck('id')->unique()->all())
            ->where(fn ($q) => $q->whereNotNull('cost_price')->orWhereNotNull('selling_price')
                ->orWhereNotNull('wholesale_price')->orWhereNotNull('min_selling_price'))
            ->get(['product_id', ...self::FIELDS])
            ->keyBy('product_id');

        foreach ($list as $product) {
            $row = $rows->get($product->id);
            $overridden = [];
            foreach (self::FIELDS as $field) {
                if ($row && $row->{$field} !== null && array_key_exists($field, $product->getAttributes())) {
                    $product->setAttribute($field, $row->{$field});
                    $product->syncOriginalAttribute($field);
                    $overridden[] = $field;
                }
            }
            $product->setAttribute('branch_price', $overridden !== []);
            $product->syncOriginalAttribute('branch_price');
        }
    }

    /**
     * Every branch with its own prices for this product (and the product's own prices).
     *
     * @return Collection<int, array>
     */
    public function forProduct(Product $product, ?array $branchIds = null): Collection
    {
        $rows = ProductStock::query()->where('product_id', $product->id)->get()->keyBy('branch_id');

        return Branch::query()->where('is_active', true)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds ?: [0]))
            ->orderByDesc('is_main')->orderBy('name')->get(['id', 'name'])
            ->map(function (Branch $b) use ($rows, $product) {
                $row = $rows->get($b->id);
                $own = [];
                $effective = [];
                foreach (self::FIELDS as $field) {
                    $value = $row?->{$field};
                    $own[$field] = $value !== null ? (float) $value : null;
                    $effective[$field] = $value !== null ? (float) $value : ($product->{$field} !== null ? (float) $product->{$field} : null);
                }

                return [
                    'branch_id' => $b->id,
                    'branch_name' => $b->name,
                    'quantity' => (float) ($row?->quantity ?? 0),
                    'own' => $own,
                    'effective' => $effective,
                    'has_own_price' => collect($own)->filter(fn ($v) => $v !== null)->isNotEmpty(),
                ];
            })->values();
    }

    /**
     * Saves a branch's prices for a product. A null / empty value removes the branch
     * price so the product's own price applies again.
     */
    public function set(Product $product, int $branchId, array $prices): void
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $prices)) {
                continue;
            }
            $v = $prices[$field];
            $values[$field] = ($v === null || $v === '') ? null : round((float) $v, 2);
        }
        if ($values === []) {
            return;
        }

        $selling = $values['selling_price'] ?? null;
        $minimum = $values['min_selling_price'] ?? null;
        if ($selling !== null && $minimum !== null && $minimum > $selling) {
            throw new BusinessException(__('The minimum price cannot be higher than the selling price.'));
        }

        DB::transaction(function () use ($product, $branchId, $values) {
            $row = ProductStock::query()->where('product_id', $product->id)->where('branch_id', $branchId)->lockForUpdate()->first();
            if ($row) {
                $row->forceFill($values)->save();
            } else {
                ProductStock::query()->forceCreate(['product_id' => $product->id, 'branch_id' => $branchId, 'quantity' => 0] + $values);
            }
            // The mobile app pulls products changed since its last sync.
            $product->touch();
        });
    }

    /** SQL for a branch's effective price, for reports: COALESCE(branch, product). */
    public static function sql(string $field, string $stockAlias = 'product_stocks', string $productAlias = 'products'): string
    {
        if (! in_array($field, self::FIELDS, true)) {
            throw new \InvalidArgumentException("Unknown price field {$field}");
        }

        return "COALESCE({$stockAlias}.{$field}, {$productAlias}.{$field})";
    }
}

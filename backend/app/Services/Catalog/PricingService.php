<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductUnit;
use App\Models\Crm\Customer;
use App\Services\Core\SettingsService;

/**
 * Retail / wholesale prices for a product, in its base unit or in a pack unit.
 *
 * Price level, in order of precedence:
 *  1. explicitly chosen at the till (needs pos.wholesale permission, checked by the caller)
 *  2. the customer's type (wholesale customers always get wholesale prices)
 *  3. retail, upgraded to wholesale automatically when the quantity reaches the
 *     product's wholesale minimum (if that setting is on)
 */
class PricingService
{
    public const RETAIL = 'retail';

    public const WHOLESALE = 'wholesale';

    public function __construct(private readonly SettingsService $settings) {}

    public function levelFor(?Customer $customer, ?string $requested): string
    {
        if (in_array($requested, [self::RETAIL, self::WHOLESALE], true)) {
            return $requested;
        }

        return $customer?->isWholesale() ? self::WHOLESALE : self::RETAIL;
    }

    /**
     * @return array{price: float, level: string, factor: float, base_quantity: float, unit_name: ?string}
     */
    public function quote(Product $product, ?ProductUnit $unit, float $quantity, string $level): array
    {
        if ($unit && (int) $unit->product_id !== (int) $product->id) {
            throw new BusinessException(__('The selected unit does not belong to :product.', ['product' => $product->name]));
        }
        if ($unit && ! $unit->is_active) {
            throw new BusinessException(__('The unit :unit of :product is no longer sold.', ['unit' => $unit->name, 'product' => $product->name]));
        }

        $factor = $unit ? max(0.001, (float) $unit->factor) : 1.0;
        $baseQuantity = round($quantity * $factor, 3);

        $effective = $level;
        $minimum = (float) ($product->wholesale_min_qty ?? 0);
        if ($level === self::RETAIL && $minimum > 0 && $baseQuantity + 0.0005 >= $minimum && $this->settings->bool('auto_wholesale_by_quantity')) {
            $effective = self::WHOLESALE;
        }

        // No wholesale price exists for this product / unit: the retail price applies.
        if ($effective === self::WHOLESALE && $this->wholesaleFor($product, $unit) === null) {
            $effective = self::RETAIL;
        }
        $price = $this->unitPrice($product, $unit, $effective);

        return [
            'price' => round($price, 2),
            'level' => $effective,
            'factor' => $factor,
            'base_quantity' => $baseQuantity,
            'unit_name' => $unit?->name ?? $product->unit?->short_name,
        ];
    }

    public function unitPrice(Product $product, ?ProductUnit $unit, string $level): float
    {
        $retail = $unit ? (float) $unit->retail_price : (float) $product->selling_price;
        if ($level !== self::WHOLESALE) {
            return $retail;
        }

        return $this->wholesaleFor($product, $unit) ?? $retail;
    }

    private function wholesaleFor(Product $product, ?ProductUnit $unit): ?float
    {
        if ($unit) {
            if ($unit->wholesale_price !== null) {
                return (float) $unit->wholesale_price;
            }

            return $product->wholesale_price !== null ? (float) $product->wholesale_price * (float) $unit->factor : null;
        }

        return $product->wholesale_price !== null ? (float) $product->wholesale_price : null;
    }
}

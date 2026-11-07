<?php

namespace App\Models\Sales;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['batches'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'returned_quantity' => 'decimal:3',
            'unit_factor' => 'decimal:3',
            'unit_quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'list_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'batches' => 'array',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function returnableQuantity(): float
    {
        return round((float) $this->quantity - (float) $this->returned_quantity, 3);
    }

    /** Net price per unit after the line discount. */
    public function netUnitPrice(): float
    {
        $qty = (float) $this->quantity;

        return $qty > 0 ? round((float) $this->line_total / $qty, 4) : 0.0;
    }
}

<?php

namespace App\Models\Catalog;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An alternative selling / buying unit of a product, e.g. "Carton (24)".
 * Stock is always kept in the product's base unit: 1 of this unit = factor base units.
 */
class ProductUnit extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    protected $fillable = ['product_id', 'unit_id', 'name', 'factor', 'barcode', 'retail_price', 'wholesale_price', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:3',
            'retail_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }
}

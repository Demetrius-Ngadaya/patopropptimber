<?php

namespace App\Models\Inventory;

use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllocationItem extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['batches'];

    protected function casts(): array
    {
        return [
            'quantity_issued' => 'decimal:3',
            'quantity_returned' => 'decimal:3',
            'quantity_damaged' => 'decimal:3',
            'quantity_sold' => 'decimal:3',
            'quantity_recorded' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'batches' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}

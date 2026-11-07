<?php

namespace App\Models\Purchasing;

use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchasePlanItem extends Model
{
    protected $guarded = ['id'];

    protected $appends = ['remaining_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'received_quantity' => 'decimal:3', 'unit_cost' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function getRemainingQuantityAttribute(): float
    {
        return max(0, round((float) $this->quantity - (float) $this->received_quantity, 3));
    }
}

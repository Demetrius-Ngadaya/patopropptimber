<?php

namespace App\Models\Purchasing;

use App\Models\Catalog\Product;
use App\Models\Inventory\StockBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'returned_quantity' => 'decimal:3',
            'unit_factor' => 'decimal:3',
            'unit_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'extra_cost_per_unit' => 'decimal:2',
            'landed_unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'expiry_date' => 'date:Y-m-d',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }
}

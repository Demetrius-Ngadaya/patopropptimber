<?php

namespace App\Models\Inventory;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Sales\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Allocation extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'recorded_amount' => 'decimal:2',
            'recorded_sales' => 'integer',
            'sold_amount' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'shortage' => 'decimal:2',
            'issued_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id')->withTrashed()->select('id', 'name', 'username', 'phone');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AllocationItem::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withTrashed();
    }
}

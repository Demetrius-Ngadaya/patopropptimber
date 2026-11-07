<?php

namespace App\Models\Purchasing;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'remind_on' => 'date:Y-m-d',
            'reminded_on' => 'date:Y-m-d',
            'reminder_channels' => 'array',
            'subtotal' => 'decimal:2',
            'tax_cost' => 'decimal:2',
            'loading_cost' => 'decimal:2',
            'unloading_cost' => 'decimal:2',
            'transport_cost' => 'decimal:2',
            'other_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'supplier_total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance' => 'decimal:2',
            'returned_amount' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PurchasePlan::class, 'purchase_plan_id')->withTrashed();
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }
}

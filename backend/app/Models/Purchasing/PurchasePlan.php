<?php

namespace App\Models\Purchasing;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A planned purchase (order to a supplier): drafted, made pending (approved /
 * ordered), received in one or more deliveries, then closed - or cancelled.
 */
class PurchasePlan extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const STATUSES = ['draft', 'pending', 'partially_received', 'received', 'cancelled', 'closed'];

    /** Goods can still arrive. */
    public const RECEIVABLE = ['pending', 'partially_received'];

    protected $guarded = ['id', 'number'];

    protected function casts(): array
    {
        return [
            'expected_date' => 'date:Y-m-d',
            'reminder_channels' => 'array',
            'estimated_total' => 'decimal:2',
            'received_total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
        return $this->hasMany(PurchasePlanItem::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PurchasePlanEvent::class)->orderBy('id');
    }
}

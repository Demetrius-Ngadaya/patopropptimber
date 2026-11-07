<?php

namespace App\Models\Crm;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A customer's savings ("akiba"): money deposited to spend on future purchases. */
class SavingsAccount extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FROZEN = 'frozen';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_FROZEN, self::STATUS_CLOSED];

    /** Balances only change through SavingsService (forceFill), never mass assignment. */
    protected $fillable = ['account_no', 'customer_id', 'branch_id', 'status', 'notes'];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'total_deposited' => 'decimal:2',
            'total_spent' => 'decimal:2',
            'total_withdrawn' => 'decimal:2',
            'last_transaction_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function auditLabel(): string
    {
        return $this->account_no;
    }
}

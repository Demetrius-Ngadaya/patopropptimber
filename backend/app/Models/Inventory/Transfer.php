<?php

namespace App\Models\Inventory;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transfer extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const TYPES = ['free', 'sale', 'credit'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'total_cost' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'receiving_costs' => 'decimal:2',
            'due_date' => 'date:Y-m-d',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id')->withTrashed();
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransferItem::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by')->withTrashed()->select('id', 'name', 'username');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by')->withTrashed()->select('id', 'name', 'username');
    }
}

<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $guarded = ['id'];

    protected $appends = ['remaining'];

    protected function casts(): array
    {
        return ['entitled' => 'decimal:1', 'carried_forward' => 'decimal:1', 'adjustment' => 'decimal:1', 'taken' => 'decimal:1'];
    }

    public function getRemainingAttribute(): float
    {
        return round((float) $this->entitled + (float) $this->carried_forward + (float) $this->adjustment - (float) $this->taken, 1);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }
}

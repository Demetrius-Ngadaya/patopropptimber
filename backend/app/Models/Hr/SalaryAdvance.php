<?php

namespace App\Models\Hr;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdvance extends Model
{
    use Auditable, HasUserstamps;

    protected $fillable = ['employee_id', 'amount', 'installments', 'reason'];

    protected $appends = ['outstanding'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'monthly_deduction' => 'decimal:2',
            'recovered' => 'decimal:2',
            'decided_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function getOutstandingAttribute(): float
    {
        return round((float) $this->amount - (float) $this->recovered, 2);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function auditLabel(): string
    {
        return $this->number;
    }
}

<?php

namespace App\Models\Hr;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollRun extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const TOTALS = ['gross', 'paye', 'nssf_employee', 'nssf_employer', 'sdl', 'wcf', 'nhif_employee', 'nhif_employer', 'other_deductions', 'advance_recovery', 'net', 'employer_cost'];

    protected $fillable = ['period', 'period_start', 'period_end', 'branch_id', 'notes'];

    protected function casts(): array
    {
        return array_fill_keys(self::TOTALS, 'decimal:2') + [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed()->select('id', 'name');
    }

    public function auditLabel(): string
    {
        return "{$this->number} ({$this->period})";
    }
}

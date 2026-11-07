<?php

namespace App\Models\Hr;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use App\Models\Core\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A member of staff. National ID, TIN and bank account are encrypted at rest;
 * salary and bank fields are hidden unless the viewer has employees.view_salary.
 */
class Employee extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const ACTIVE_STATUSES = ['active', 'on_leave', 'suspended'];

    public const STATUSES = ['active', 'on_leave', 'suspended', 'terminated', 'resigned'];

    public const TYPES = ['permanent', 'contract', 'casual', 'intern'];

    /** Sensitive pay fields, hidden from viewers without employees.view_salary. */
    public const SALARY_FIELDS = ['basic_salary', 'bank_name', 'bank_account_number', 'mobile_money_number', 'tin', 'national_id', 'nssf_number', 'nhif_number', 'paye_exempt', 'nssf_exempt'];

    protected $fillable = [
        'user_id', 'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'marital_status', 'national_id',
        'phone', 'email', 'address', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
        'branch_id', 'department_id', 'position_id', 'manager_id', 'employment_type', 'hire_date', 'probation_end_date',
        'contract_end_date', 'basic_salary', 'payment_method', 'bank_name', 'bank_account_number', 'mobile_money_number',
        'tin', 'nssf_number', 'nhif_number', 'paye_exempt', 'nssf_exempt', 'notes',
    ];

    protected $appends = ['full_name', 'photo_url'];

    /** Audit logs must never contain the decrypted secrets. */
    protected array $auditExclude = ['national_id', 'bank_account_number', 'tin'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'hire_date' => 'date:Y-m-d',
            'probation_end_date' => 'date:Y-m-d',
            'contract_end_date' => 'date:Y-m-d',
            'exit_date' => 'date:Y-m-d',
            'basic_salary' => 'decimal:2',
            'national_id' => 'encrypted',
            'bank_account_number' => 'encrypted',
            'tin' => 'encrypted',
            'paye_exempt' => 'boolean',
            'nssf_exempt' => 'boolean',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim(preg_replace('/\s+/', ' ', "{$this->first_name} {$this->middle_name} {$this->last_name}"));
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    public function scopeEmployed(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isEmployed(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class)->withTrashed();
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id')->withTrashed();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(SalaryAdvance::class);
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(SalaryComponent::class, 'employee_salary_components')
            ->withPivot(['id', 'amount', 'starts_on', 'ends_on'])->withTimestamps();
    }

    public function auditLabel(): string
    {
        return "{$this->employee_no} {$this->full_name}";
    }
}

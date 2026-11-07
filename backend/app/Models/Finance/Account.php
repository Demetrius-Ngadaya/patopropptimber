<?php

namespace App\Models\Finance;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** Money accounts: where payments are received / paid from. */
    public const MONEY_SUB_TYPES = ['cash', 'bank', 'mobile_money'];

    protected $fillable = [
        'code', 'name', 'type', 'sub_type', 'parent_id', 'is_header', 'allow_manual', 'is_active',
        'description', 'bank_name', 'account_number', 'branch_id',
    ];

    protected function casts(): array
    {
        return [
            'is_header' => 'boolean',
            'allow_manual' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    /** Debit-normal accounts grow with debits (assets, expenses). */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_header', false)->where('is_active', true);
    }

    public function scopeMoney(Builder $query): Builder
    {
        return $query->whereIn('sub_type', self::MONEY_SUB_TYPES);
    }

    public function auditLabel(): string
    {
        return "{$this->code} {$this->name}";
    }
}

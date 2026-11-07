<?php

namespace App\Models\Agency;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A mobile money or bank float the business runs as an agent (M-Pesa, CRDB ...). */
class AgencyAccount extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const PROVIDERS = [
        'mpesa' => ['M-Pesa', 'mobile_money'],
        'mixx' => ['Mixx by Yas (Tigo Pesa)', 'mobile_money'],
        'airtel' => ['Airtel Money', 'mobile_money'],
        'halopesa' => ['HaloPesa', 'mobile_money'],
        'tpesa' => ['T-Pesa', 'mobile_money'],
        'azampesa' => ['AzamPesa', 'mobile_money'],
        'nmb' => ['NMB Wakala', 'bank'],
        'crdb' => ['CRDB Wakala', 'bank'],
        'nbc' => ['NBC Wakala', 'bank'],
        'other' => ['Other', 'other'],
    ];

    public const KINDS = ['mobile_money', 'bank', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    /** Accounts used at this branch (its own, plus those for every branch). */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId));
    }
}

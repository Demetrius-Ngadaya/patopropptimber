<?php

namespace App\Models\Finance;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An item of property or equipment, depreciated straight-line every month. */
class FixedAsset extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const ACTIVE = 'active';

    public const DISPOSED = 'disposed';

    public const FULLY_DEPRECIATED = 'fully_depreciated';

    protected $fillable = [
        'name', 'category', 'serial_number', 'location', 'branch_id', 'asset_account_id',
        'depreciation_account_id', 'accumulated_account_id', 'acquired_on', 'cost', 'salvage_value',
        'useful_life_months', 'notes',
    ];

    protected $appends = ['book_value', 'monthly_depreciation'];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date:Y-m-d',
            'depreciated_to' => 'date:Y-m-d',
            'disposed_on' => 'date:Y-m-d',
            'cost' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'disposal_amount' => 'decimal:2',
            'useful_life_months' => 'integer',
        ];
    }

    public function getBookValueAttribute(): float
    {
        return round((float) $this->cost - (float) $this->accumulated_depreciation, 2);
    }

    public function getMonthlyDepreciationAttribute(): float
    {
        $months = max(1, (int) $this->useful_life_months);

        return round(((float) $this->cost - (float) $this->salvage_value) / $months, 2);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id')->withTrashed();
    }

    public function depreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_account_id')->withTrashed();
    }

    public function accumulatedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_account_id')->withTrashed();
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(AssetDepreciation::class);
    }

    public function auditLabel(): string
    {
        return "{$this->number} {$this->name}";
    }
}

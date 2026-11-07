<?php

namespace App\Models\Marketing;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A discount offered to all customers or to chosen groups / people, for a period. */
class Promotion extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    protected $guarded = ['id', 'code'];

    protected $appends = ['state'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_purchase' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'category_ids' => 'array',
            'product_ids' => 'array',
            'segments' => 'array',
            'customer_ids' => 'array',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'is_active' => 'boolean',
            'notified_at' => 'datetime',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    /** scheduled | running | ended | paused */
    public function getStateAttribute(): string
    {
        if (! $this->is_active) {
            return 'paused';
        }
        if ($this->starts_on?->isFuture()) {
            return 'scheduled';
        }

        return $this->ends_on?->lt(today()) ? 'ended' : 'running';
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today());
    }

    /** "10% off" / "TZS 2,000 off". */
    public function headline(string $currency): string
    {
        return $this->type === 'percent'
            ? __(':value% off', ['value' => 0 + (float) $this->value])
            : __(':currency :value off', ['currency' => $currency, 'value' => number_format((float) $this->value)]);
    }
}

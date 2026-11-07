<?php

namespace App\Models\Agency;

use App\Models\Concerns\Auditable;
use App\Models\Core\Branch;
use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One business day of the agency: what was there at opening (cash + each
 * float) and at closing. Total value should only move with capital put in or
 * taken out; anything else is a shortage or an excess.
 */
class AgencyDay extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected $appends = ['opening_float', 'opening_total', 'closing_float', 'closing_total', 'difference'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'opening_cash' => 'decimal:2',
            'closing_cash' => 'decimal:2',
            'capital_added' => 'decimal:2',
            'capital_withdrawn' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function balances(): HasMany
    {
        return $this->hasMany(AgencyDayBalance::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function getOpeningFloatAttribute(): float
    {
        return round((float) $this->balances->sum('opening_float'), 2);
    }

    public function getOpeningTotalAttribute(): float
    {
        return round((float) $this->opening_cash + $this->opening_float, 2);
    }

    public function getClosingFloatAttribute(): ?float
    {
        return $this->status === 'closed' ? round((float) $this->balances->sum('closing_float'), 2) : null;
    }

    public function getClosingTotalAttribute(): ?float
    {
        return $this->status === 'closed' ? round((float) $this->closing_cash + $this->closing_float, 2) : null;
    }

    /** Closing minus opening, after capital movements: + excess, - shortage. */
    public function getDifferenceAttribute(): ?float
    {
        if ($this->status !== 'closed') {
            return null;
        }

        return round($this->closing_total - $this->opening_total - (float) $this->capital_added + (float) $this->capital_withdrawn, 2);
    }
}

<?php

namespace App\Models\Shifts;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const STATUSES = ['scheduled', 'in_progress', 'completed', 'missed', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class, 'shift_type_id')->withTrashed();
    }

    public function delegations(): HasMany
    {
        return $this->hasMany(ShiftDelegation::class);
    }

    /** The handover made at the end of this shift. */
    public function handover(): HasOne
    {
        return $this->hasOne(ShiftHandover::class)->latestOfMany();
    }

    /** The handover received at the start of this shift. */
    public function receivedHandover(): HasOne
    {
        return $this->hasOne(ShiftHandover::class, 'next_shift_id')->latestOfMany();
    }
}

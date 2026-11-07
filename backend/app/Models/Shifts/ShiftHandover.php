<?php

namespace App\Models\Shifts;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the outgoing person hands to the next one: notes, cash counted, confirmation. */
class ShiftHandover extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cash_counted' => 'decimal:2', 'cash_confirmed' => 'decimal:2', 'accepted_at' => 'datetime'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }

    public function nextShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'next_shift_id')->withTrashed();
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id')->withTrashed();
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id')->withTrashed();
    }
}

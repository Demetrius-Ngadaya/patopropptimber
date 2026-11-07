<?php

namespace App\Models\Shifts;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Please cover my shift": asked by the assigned person, accepted or declined by a colleague. */
class ShiftDelegation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['emergency' => 'boolean', 'responded_at' => 'datetime'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class)->withTrashed();
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

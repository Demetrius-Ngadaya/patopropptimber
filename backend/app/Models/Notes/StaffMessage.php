<?php

namespace App\Models\Notes;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A message, reminder or announcement from the administration to staff. */
class StaffMessage extends Model
{
    use SoftDeletes;

    public const KINDS = ['message', 'reminder', 'announcement'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'role_ids' => 'array',
            'user_ids' => 'array',
            'requires_ack' => 'boolean',
            'show_from' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id')->withTrashed();
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(StaffMessageRecipient::class);
    }

    /** Showing now (started, not expired). */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('show_from')->orWhere('show_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}

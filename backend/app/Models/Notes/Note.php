<?php

namespace App\Models\Notes;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A note a user keeps for themself, optionally shared with colleagues. */
class Note extends Model
{
    use SoftDeletes;

    public const COLORS = ['yellow', 'green', 'blue', 'pink', 'purple', 'gray'];

    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['remind_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function members(): HasMany
    {
        return $this->hasMany(NoteMember::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(NoteAttachment::class);
    }

    public function memberFor(User $user): ?NoteMember
    {
        return $this->members->firstWhere('user_id', $user->id);
    }
}

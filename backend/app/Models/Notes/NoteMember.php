<?php

namespace App\Models\Notes;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteMember extends Model
{
    public const ACCESS = ['view', 'edit'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pinned_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}

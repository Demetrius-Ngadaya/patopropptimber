<?php

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = ['key', 'module', 'action', 'type', 'label', 'description', 'sort_order'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}

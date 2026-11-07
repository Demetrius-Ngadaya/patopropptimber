<?php

namespace App\Models\Core;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const DEVELOPER_SLUG = 'system-software-engineer';

    protected $fillable = ['name', 'slug', 'description', 'all_branches'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_super_admin' => 'boolean',
            'is_developer' => 'boolean',
            'all_branches' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** The Software Engineer role exists only for Software Engineers. */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        return $viewer?->isDeveloper() ? $query : $query->where('is_developer', false);
    }
}

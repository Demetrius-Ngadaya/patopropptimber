<?php

namespace App\Models\Core;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A version of the PatoPro mobile app that people download from the system. */
class AppRelease extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    protected $fillable = [
        'platform', 'version', 'build_number', 'file_path', 'file_name', 'file_size', 'sha256',
        'external_url', 'notes', 'is_required', 'is_published', 'published_at',
    ];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'build_number' => 'integer',
            'file_size' => 'integer',
            'downloads' => 'integer',
            'is_required' => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** The release the download buttons offer: the newest published one. */
    public static function current(string $platform = 'android'): ?self
    {
        return static::query()->published()->where('platform', $platform)
            ->orderByDesc('build_number')->orderByDesc('id')->first();
    }

    public function hasFile(): bool
    {
        return (bool) $this->file_path;
    }
}

<?php

namespace App\Models\Core;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class LoginSlide extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    protected $fillable = ['title', 'caption', 'image_path', 'image_small_path', 'sort_order', 'is_active', 'show_on_login', 'show_on_shop'];

    protected $appends = ['image_url', 'image_small_url'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'show_on_login' => 'boolean', 'show_on_shop' => 'boolean'];
    }

    /** Active pictures for one place: "login" (beside the login form) or "shop" (top of the online shop). */
    public function scopeVisible(Builder $query, string $place = 'login'): Builder
    {
        return $query->where('is_active', true)
            ->where($place === 'shop' ? 'show_on_shop' : 'show_on_login', true)
            ->orderBy('sort_order')->orderBy('id');
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** A smaller copy for phones (falls back to the full picture). */
    public function getImageSmallUrlAttribute(): ?string
    {
        return $this->image_small_path ? Storage::disk('public')->url($this->image_small_path) : $this->image_url;
    }

    public function auditLabel(): string
    {
        return $this->title ?: 'slide #'.$this->getKey();
    }
}

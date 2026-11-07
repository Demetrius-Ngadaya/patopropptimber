<?php

namespace App\Models\Site;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One browser that visited the website (an anonymous id kept by the browser). */
class SiteVisitor extends Model
{
    /** Seen within this many minutes = online now. */
    public const ONLINE_MINUTES = 3;

    /** Away longer than this = the next page view starts a new visit. */
    public const SESSION_MINUTES = 30;

    protected $guarded = ['id'];

    protected $hidden = ['visitor_key'];

    protected $appends = ['is_online'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'visits' => 'integer',
            'page_views' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function days(): HasMany
    {
        return $this->hasMany(SiteVisitorDay::class);
    }

    public function getIsOnlineAttribute(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gte(now()->subMinutes(self::ONLINE_MINUTES));
    }
}

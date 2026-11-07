<?php

namespace App\Models\Core;

use App\Services\Mobile\PushNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A notification in the bell. Shown to staff who hold `permission` (any staff
 * when null) and can access `branch_id` (every branch when null).
 */
class AppNotification extends Model
{
    public const UPDATED_AT = null;

    /** Older notifications are not shown and are pruned. */
    public const KEEP_DAYS = 60;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Also to the phones of everyone who may see it (PatoPro mobile app).
        static::created(fn (AppNotification $n) => app(PushNotifications::class)->queue($n));
    }

    protected function casts(): array
    {
        return ['params' => 'array', 'role_ids' => 'array', 'created_at' => 'datetime'];
    }

    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'app_notification_reads')->withPivot('read_at');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->where('created_at', '>=', now()->subDays(self::KEEP_DAYS));
        if ($user->created_at) {
            $query->where('created_at', '>=', $user->created_at->copy()->subDay());
        }
        // Addressed to one person: only they see it.
        $query->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $user->id));
        if (! $user->isDeveloper()) {
            $keys = $user->permissionKeys();
            $query->where(fn ($q) => $q->whereNotNull('user_id')->orWhereNull('permission')->orWhereIn('permission', $keys ?: ['-']));
            // Sent to chosen roles (custom notifications).
            $query->where(fn ($q) => $q->whereNull('role_ids')->orWhereJsonContains('role_ids', (int) $user->role_id));
        }
        if (! $user->canAccessAllBranches()) {
            $query->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $user->accessibleBranchIds() ?: [0]));
        }

        return $query;
    }

    public function scopeUnreadBy(Builder $query, User $user): Builder
    {
        return $query->whereDoesntHave('readers', fn ($q) => $q->whereKey($user->id));
    }

    /** Title and body in the reader's language. */
    public function present(bool $read): array
    {
        $params = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', (array) $this->params);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'level' => $this->level,
            'title' => __($this->title, $params),
            'body' => $this->body ? __($this->body, $params) : null,
            'url' => $this->url,
            'read' => $read,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

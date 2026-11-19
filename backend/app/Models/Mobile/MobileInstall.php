<?php

namespace App\Models\Mobile;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One phone the app was opened on (see the create_mobile_installs migration). */
class MobileInstall extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'first_login_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** signed_in · tried (failed only) · never (opened, nobody tried to sign in). */
    public function getStatusAttribute(): string
    {
        return match (true) {
            $this->logins > 0 => 'signed_in',
            $this->failed_logins > 0 => 'tried',
            default => 'never',
        };
    }

    /** A sign-in that the server accepted on this phone. */
    public static function signedIn(string $installationId, User $user, array $info = []): void
    {
        $install = static::query()->firstOrNew(['installation_id' => $installationId]);
        $install->fill(array_filter($info, fn ($v) => $v !== null));
        $install->first_seen_at ??= now();
        $install->last_seen_at = now();
        $install->user_id = $user->id;
        $install->first_login_at ??= now();
        // Registering the phone happens again on later starts: count a login once per day at most.
        if (! $install->last_login_at || $install->last_login_at->lt(now()->subHours(12))) {
            $install->logins++;
        }
        $install->last_login_at = now();
        $install->save();
    }
}

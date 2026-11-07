<?php

namespace App\Models\Mobile;

use App\Models\Core\User;
use App\Support\NotificationCategories;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One installation of the PatoPro mobile app. Holds the Expo push token and
 * how each kind of notification should alert on this phone.
 */
class MobileDevice extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['push_token'];

    protected function casts(): array
    {
        return [
            'preferences' => 'array',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'push_failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * How a notification of this category alerts right now, after quiet hours.
     *
     * @return array{enabled: bool, voice: bool, sound: bool, vibrate: bool, quiet: bool}
     */
    public function alertFor(string $category, ?Carbon $at = null): array
    {
        $prefs = (array) ($this->preferences ?? []);
        $mode = array_merge(NotificationCategories::defaultMode($category), (array) ($prefs['categories'][$category] ?? []));
        $alert = [
            'enabled' => (bool) ($mode['enabled'] ?? true),
            'voice' => (bool) ($mode['voice'] ?? false),
            'sound' => (bool) ($mode['sound'] ?? true),
            'vibrate' => (bool) ($mode['vibrate'] ?? true),
            'quiet' => false,
        ];

        if ($this->inQuietHours((array) ($prefs['quiet_hours'] ?? []), $at ?? now())) {
            // Still delivered to the notification bar, without voice, sound or vibration.
            $alert = ['voice' => false, 'sound' => false, 'vibrate' => false, 'quiet' => true] + $alert;
        }

        return $alert;
    }

    /** Quiet hours in the phone's own time zone, e.g. 22:00 to 06:00 (crossing midnight). */
    private function inQuietHours(array $quiet, Carbon $at): bool
    {
        if (empty($quiet['enabled']) || empty($quiet['from']) || empty($quiet['to'])) {
            return false;
        }
        $tz = is_string($quiet['timezone'] ?? null) && in_array($quiet['timezone'], timezone_identifiers_list(), true)
            ? $quiet['timezone'] : config('app.timezone');
        $now = $at->copy()->setTimezone($tz)->format('H:i');
        [$from, $to] = [(string) $quiet['from'], (string) $quiet['to']];

        return $from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    }
}

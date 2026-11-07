<?php

namespace App\Services\Mobile;

use App\Models\Core\AppNotification;
use App\Models\Core\User;
use App\Models\Mobile\MobileDevice;
use App\Support\Afterwards;
use App\Support\NotificationCategories;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends every bell notification to the phones of the staff allowed to see it,
 * through the Expo push service, shaped by each phone's settings:
 *
 *  - Android channel by sound / vibration (a channel's sound and vibration
 *    are fixed by Android once created, so there is one channel per mix);
 *  - "voice" on Android: a data-only message, so the app wakes (even when
 *    closed), shows the notification itself and reads it out loud;
 *  - iOS: a normal alert, plus content-available so the app can speak when
 *    it is open or suspended;
 *  - quiet hours: still in the notification bar, but silent.
 */
class PushNotifications
{
    /** Android channels created by the app (mobile/src/notifications/channels.ts). */
    public static function channelId(bool $sound, bool $vibrate): string
    {
        return match (true) {
            $sound && $vibrate => 'alert_sound_vibrate',
            $sound => 'alert_sound',
            $vibrate => 'alert_vibrate',
            default => 'alert_silent',
        };
    }

    /** Called when a bell notification is created: sends after the response. */
    public function queue(AppNotification $notification): void
    {
        if (! config('mauzo.mobile.push_enabled')) {
            return;
        }
        $id = $notification->id;
        Afterwards::run(function () use ($id) {
            $n = AppNotification::query()->find($id);
            if ($n) {
                $this->sendNow($n);
            }
        });
    }

    /** @return int number of phones the notification was sent to */
    public function sendNow(AppNotification $notification): int
    {
        $devices = MobileDevice::query()->whereNotNull('push_token')
            ->with('user.role')
            ->get()
            ->filter(fn (MobileDevice $d) => $d->user && $d->user->is_active && $d->user->isStaff()
                && AppNotification::query()->visibleTo($d->user)->whereKey($notification->id)->exists());

        $messages = $devices->map(fn (MobileDevice $d) => $this->message($notification, $d))->filter()->values();

        return $this->deliver($messages);
    }

    /** A test notification from the app's settings screen, to one phone. */
    public function test(MobileDevice $device, string $category): bool
    {
        $notification = new AppNotification([
            'type' => match ($category) {
                'orders' => 'new_order', 'stock' => 'low_stock', 'money' => 'sales_report', 'team' => 'shift', default => 'general',
            },
            'level' => 'info',
            'title' => 'Test notification',
            'body' => 'This is how PatoPro will alert you.',
            'params' => [],
            'url' => null,
        ]);
        $notification->id = 0;
        $notification->created_at = now();
        $message = $this->message($notification, $device, true);

        return $message !== null && $this->deliver(collect([$message])) === 1;
    }

    /** @return array<string, mixed>|null */
    private function message(AppNotification $n, MobileDevice $device, bool $isTest = false): ?array
    {
        $category = NotificationCategories::forType($n->type);
        $alert = $device->alertFor($category);
        if (! $alert['enabled'] && ! $isTest) {
            return null;
        }

        $previous = App::getLocale();
        App::setLocale(in_array($device->locale ?? $device->user?->locale, ['en', 'sw'], true) ? ($device->locale ?? $device->user->locale) : $previous);
        try {
            $present = $n->present(false);
        } finally {
            App::setLocale($previous);
        }

        $channel = self::channelId($alert['sound'], $alert['vibrate']);
        $data = [
            'notification_id' => $n->id,
            'type' => $n->type,
            'category' => $category,
            'level' => $n->level,
            'url' => $n->url,
            'title' => $present['title'],
            'body' => $present['body'],
            'speak' => $alert['voice'],
            'sound' => $alert['sound'],
            'vibrate' => $alert['vibrate'],
            'channel_id' => $channel,
            'created_at' => $present['created_at'],
        ];

        // Android with voice: the app shows and speaks it (works with the app closed).
        if ($device->platform === 'android' && $alert['voice']) {
            return ['to' => $device->push_token, 'data' => $data + ['display' => true], 'priority' => 'high'];
        }

        return array_filter([
            'to' => $device->push_token,
            'title' => $present['title'],
            'body' => $present['body'],
            'data' => $data,
            'sound' => $alert['sound'] ? 'default' : null,
            'channelId' => $channel,
            'priority' => 'high',
            'categoryId' => $category,
            '_contentAvailable' => $device->platform === 'ios' && $alert['voice'] ? true : null,
            'badge' => $device->platform === 'ios' && $device->user ? $this->unread($device->user) : null,
        ], fn ($v) => $v !== null);
    }

    private function unread(User $user): int
    {
        return AppNotification::query()->visibleTo($user)->unreadBy($user)->count();
    }

    /** @param  Collection<int, array<string, mixed>>  $messages */
    private function deliver(Collection $messages): int
    {
        if ($messages->isEmpty()) {
            return 0;
        }
        $sent = 0;
        foreach ($messages->chunk(100) as $chunk) {
            $chunk = $chunk->values();
            try {
                $response = Http::acceptJson()->asJson()->timeout(10)
                    ->when(config('mauzo.mobile.expo_access_token'), fn ($r, $token) => $r->withToken($token))
                    ->post(config('mauzo.mobile.expo_push_url'), $chunk->all());
                if (! $response->successful()) {
                    Log::warning('Expo push failed: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300));

                    continue;
                }
                foreach ((array) $response->json('data', []) as $i => $ticket) {
                    if (($ticket['status'] ?? null) === 'ok') {
                        $sent++;

                        continue;
                    }
                    $token = $chunk[$i]['to'] ?? null;
                    if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered' && $token) {
                        // The app was uninstalled or the token changed: stop sending to it.
                        MobileDevice::query()->where('push_token', $token)->update(['push_token' => null, 'push_failed_at' => now()]);
                    } else {
                        Log::info('Expo push ticket error', ['ticket' => $ticket]);
                    }
                }
            } catch (Throwable $e) {
                Log::warning('Expo push failed: '.$e->getMessage());
            }
        }

        return $sent;
    }
}

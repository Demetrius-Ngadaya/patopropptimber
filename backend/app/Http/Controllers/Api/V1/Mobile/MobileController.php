<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Core\PersonalAccessToken;
use App\Models\Mobile\MobileDevice;
use App\Services\Core\SettingsService;
use App\Services\Mobile\PushNotifications;
use App\Services\Mobile\SyncPuller;
use App\Services\Mobile\SyncPusher;
use App\Support\NotificationCategories;
use App\Support\UserPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The PatoPro mobile app: this phone's registration and notification
 * settings, and the two-way offline sync.
 */
class MobileController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    /** Everything the app needs after login or when it opens online. */
    public function bootstrap(Request $request): JsonResponse
    {
        $public = $this->settings->public();
        $public['business_logo_url'] = ! empty($public['business_logo']) ? Storage::disk('public')->url($public['business_logo']) : null;
        // Staff-only rules the offline till needs to price and check a sale like the server does.
        foreach (['allow_negative_stock', 'auto_wholesale_by_quantity', 'prices_include_tax'] as $key) {
            $public[$key] = $this->settings->bool($key);
        }

        return $this->ok([
            'user' => UserPayload::make($request->user()),
            'settings' => $public,
            'server_time' => now()->toIso8601String(),
            'notification_categories' => collect(NotificationCategories::keys())
                ->mapWithKeys(fn ($c) => [$c => NotificationCategories::defaultMode($c)]),
            'sync' => ['entities' => SyncPuller::ENTITIES, 'mutations' => array_keys(SyncPusher::TYPES)],
        ]);
    }

    /** Register this phone (or update its push token and notification settings). */
    public function registerDevice(Request $request): JsonResponse
    {
        $categories = NotificationCategories::keys();
        $data = $request->validate([
            'installation_id' => ['required', 'uuid'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
            'push_token' => ['nullable', 'string', 'max:255', 'regex:/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_\-]+\]$/'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'locale' => ['nullable', Rule::in(['en', 'sw'])],
            'preferences' => ['nullable', 'array'],
            'preferences.categories' => ['nullable', 'array'],
            'preferences.categories.*' => ['array'],
            'preferences.categories.*.enabled' => ['boolean'],
            'preferences.categories.*.voice' => ['boolean'],
            'preferences.categories.*.sound' => ['boolean'],
            'preferences.categories.*.vibrate' => ['boolean'],
            'preferences.quiet_hours' => ['nullable', 'array'],
            'preferences.quiet_hours.enabled' => ['boolean'],
            'preferences.quiet_hours.from' => ['nullable', 'date_format:H:i'],
            'preferences.quiet_hours.to' => ['nullable', 'date_format:H:i'],
            'preferences.quiet_hours.timezone' => ['nullable', 'timezone'],
        ]);
        if (isset($data['preferences']['categories'])) {
            $data['preferences']['categories'] = array_intersect_key($data['preferences']['categories'], array_flip($categories));
        }

        $user = $request->user();
        $token = $user->currentAccessToken();
        // A push token belongs to one installation: a reinstall gets a new installation id.
        if (! empty($data['push_token'])) {
            MobileDevice::query()->where('push_token', $data['push_token'])->where('installation_id', '!=', $data['installation_id'])->update(['push_token' => null]);
        }

        $device = MobileDevice::query()->updateOrCreate(['installation_id' => $data['installation_id']], array_filter([
            'user_id' => $user->id,
            'token_id' => $token instanceof PersonalAccessToken ? $token->id : null,
            'platform' => $data['platform'],
            'push_token' => array_key_exists('push_token', $data) ? ($data['push_token'] ?: null) : null,
            'device_name' => $data['device_name'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'locale' => $data['locale'] ?? $user->locale,
            'preferences' => $data['preferences'] ?? null,
            'last_seen_at' => now(),
            'push_failed_at' => null,
        ], fn ($v, $k) => $v !== null || in_array($k, ['push_failed_at'], true), ARRAY_FILTER_USE_BOTH));

        return $this->ok($this->presentDevice($device), __('This phone is ready for notifications.'));
    }

    /** On logout: this phone stops getting this user's notifications. */
    public function forgetDevice(Request $request, string $installationId): JsonResponse
    {
        MobileDevice::query()->where('installation_id', $installationId)->where('user_id', $request->user()->id)->delete();

        return $this->ok(null);
    }

    /** A test alert to this phone, through the real push service. */
    public function testNotification(Request $request, PushNotifications $push): JsonResponse
    {
        $data = $request->validate([
            'installation_id' => ['required', 'uuid'],
            'category' => ['required', Rule::in(NotificationCategories::keys())],
        ]);
        $device = MobileDevice::query()->where('installation_id', $data['installation_id'])->where('user_id', $request->user()->id)->firstOrFail();
        if (! $device->push_token) {
            return $this->ok(['sent' => false], __('This phone has no push token yet: allow notifications for PatoPro in the phone settings.'));
        }

        $sent = $push->test($device, $data['category']);

        return $this->ok(['sent' => $sent], $sent ? __('Test notification sent. It arrives in a few seconds.') : __('The push service did not accept the test notification. Try again in a minute.'));
    }

    /** Server → phone: changes since the phone's cursors, one page per kind. */
    public function pull(Request $request, SyncPuller $puller): JsonResponse
    {
        $data = $request->validate([
            'cursors' => ['nullable', 'array'],
            'cursors.*' => ['nullable', 'string', 'max:40'],
            'only' => ['nullable', 'array'],
            'only.*' => [Rule::in(SyncPuller::ENTITIES)],
            'installation_id' => ['nullable', 'uuid'],
        ]);
        $user = $request->user();
        $result = $puller->pull($user, $this->branch()->requireId(), (array) ($data['cursors'] ?? []), $data['only'] ?? null);

        if (! empty($data['installation_id'])) {
            MobileDevice::query()->where('installation_id', $data['installation_id'])->where('user_id', $user->id)
                ->update(['last_synced_at' => now(), 'last_seen_at' => now()]);
        }

        return $this->ok($result);
    }

    /** Phone → server: changes made on the phone, applied in order, each exactly once. */
    public function push(Request $request, SyncPusher $pusher): JsonResponse
    {
        $data = $request->validate([
            'installation_id' => ['nullable', 'uuid'],
            'mutations' => ['required', 'array', 'min:1', 'max:100'],
            'mutations.*.id' => ['required', 'uuid', 'distinct'],
            'mutations.*.type' => ['required', Rule::in(array_keys(SyncPusher::TYPES))],
            'mutations.*.target_id' => ['nullable'],
            'mutations.*.payload' => ['present', 'array'],
            'mutations.*.branch_id' => ['nullable', 'integer'],
            'mutations.*.created_at' => ['nullable', 'date'],
            'mutations.*.base_updated_at' => ['nullable', 'date'],
        ]);
        $user = $request->user();
        $device = ! empty($data['installation_id'])
            ? MobileDevice::query()->where('installation_id', $data['installation_id'])->where('user_id', $user->id)->first()
            : null;

        // Read the raw payloads: validated() keeps only the listed keys.
        $mutations = collect($request->input('mutations'))->map(fn ($m) => [
            'id' => $m['id'], 'type' => $m['type'], 'target_id' => $m['target_id'] ?? null, 'payload' => (array) ($m['payload'] ?? []),
            'branch_id' => $m['branch_id'] ?? null, 'created_at' => $m['created_at'] ?? null, 'base_updated_at' => $m['base_updated_at'] ?? null,
        ])->all();

        $results = $pusher->push($request, $user, $device, $mutations);
        $device?->forceFill(['last_synced_at' => now(), 'last_seen_at' => now()])->save();

        return $this->ok([
            'results' => $results,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function presentDevice(MobileDevice $device): array
    {
        return [
            'installation_id' => $device->installation_id,
            'platform' => $device->platform,
            'has_push_token' => (bool) $device->push_token,
            'preferences' => $device->preferences,
            'last_synced_at' => $device->last_synced_at?->toIso8601String(),
        ];
    }
}

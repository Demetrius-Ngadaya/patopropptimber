<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Services\Core\AuditLogger;
use App\Services\Core\SettingsService;
use App\Services\Payments\OnlinePaymentService;
use App\Support\Channels;
use App\Support\NotificationTypes;
use App\Support\SettingDefinitions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** Groups only a software engineer may see or change (handled on the Modules screen). */
    private const DEVELOPER_GROUPS = ['developer'];

    public function index(): JsonResponse
    {
        $values = $this->settings->all();
        $groups = [];
        foreach (SettingDefinitions::all() as $key => $definition) {
            if (in_array($definition['group'], self::DEVELOPER_GROUPS, true)) {
                continue;
            }
            $groups[$definition['group']][] = [
                'key' => $key,
                'label' => $definition['label'],
                'type' => $definition['type'],
                'value' => $values[$key] ?? $definition['default'],
                'options' => $this->options($definition['rules']),
            ];
        }
        $logo = $values['business_logo'] ?? null;

        return $this->ok([
            'groups' => $groups,
            'logo_url' => $logo ? Storage::disk('public')->url($logo) : null,
            // For the Messages tab: what can be routed where, and what the server is set up for.
            'messaging' => [
                'channels' => Channels::ALL,
                'configured' => Channels::configured(),
                'types' => collect(NotificationTypes::all())->map(fn ($t, $key) => ['key' => $key, 'label' => $t['label'], 'audience' => $t['audience']])->values(),
            ],
            'qr_fields' => SettingDefinitions::QR_FIELDS,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $definitions = array_filter(SettingDefinitions::all(), fn ($d) => ! in_array($d['group'], self::DEVELOPER_GROUPS, true));
        unset($definitions['business_logo']); // changed through the upload endpoint
        $rules = collect($definitions)->mapWithKeys(fn ($d, $key) => [$key => array_merge(['sometimes'], $d['rules'])])->all();
        // Structured settings (e.g. PAYE bands) validate their inner fields too.
        foreach ($definitions as $d) {
            $rules += $d['nested'] ?? [];
        }

        $data = $request->validate($rules);
        $before = array_intersect_key($this->settings->all(), $data);
        $this->settings->update($data);

        $changed = array_filter($data, fn ($v, $k) => ($before[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            $this->audit->event('settings.updated', 'Settings changed: '.implode(', ', array_keys($changed)), null, [
                'before' => array_intersect_key($before, $changed),
                'after' => $changed,
            ], 'security');
        }

        return $this->ok($this->settings->all(), __('Settings saved successfully.'));
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
        ]);

        $old = $this->settings->get('business_logo');
        $path = $request->file('logo')->store('branding', 'public');
        $this->settings->update(['business_logo' => $path]);
        if ($old) {
            Storage::disk('public')->delete($old);
        }
        $this->audit->event('settings.logo', 'Business logo changed', null, [], 'business');

        return $this->ok(['logo_url' => Storage::disk('public')->url($path)], __('Logo uploaded.'));
    }

    /** Public, non-sensitive settings for the login page and the shop. */
    public function public(OnlinePaymentService $online): JsonResponse
    {
        $public = $this->settings->public();
        // The shop offers "pay now by phone" only when it is switched on and set up.
        $public['online_payments_shop'] = $online->availability()['shop'];
        $public['business_logo_url'] = ! empty($public['business_logo']) ? Storage::disk('public')->url($public['business_logo']) : null;

        return $this->ok($public);
    }

    private function options(array $rules): ?array
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'in:')) {
                return explode(',', substr($rule, 3));
            }
        }

        return null;
    }
}

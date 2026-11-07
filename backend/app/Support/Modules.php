<?php

namespace App\Support;

use App\Services\Core\SettingsService;

/**
 * System modules that the Software Engineer can switch off for the whole
 * installation. A switched-off module disappears for every user (super admins
 * included): its permissions are removed, so its menu items, pages, buttons and
 * API routes are no longer available. Data is kept; switching it on restores it.
 */
final class Modules
{
    /** Core administration can never be switched off, or nobody could switch it back on. */
    public const ALWAYS_ON = ['dashboard', 'branches', 'users', 'roles', 'settings', 'audit'];

    /**
     * Modules only some businesses need. They start switched off and the
     * business admin switches them on in Settings → Optional features.
     */
    public const OPTIONAL = ['agency', 'shifts'];

    /** Permissions that belong to one module but only make sense when another is on. */
    public const DEPENDS_ON = [
        'pos.credit_sale' => 'credit',
        'documents.issue' => 'credit',
        'credit.view' => 'sales',
        'savings.view' => 'customers',
        'orders.view' => 'sales',
        'allocations.reconcile' => 'sales',
        'payroll.view' => 'employees',
        'attendance.view' => 'employees',
        'leave.view' => 'employees',
        'purchase_plans.receive' => 'purchases',
    ];

    /** @return array<string, string> module key => label, for the modules screen */
    public static function toggleable(): array
    {
        return collect(PermissionCatalog::modules())
            ->reject(fn ($m, $key) => in_array($key, self::ALWAYS_ON, true))
            ->map(fn ($m) => $m['label'])
            ->all();
    }

    /** @return array<int, string> */
    public static function disabled(): array
    {
        $value = app(SettingsService::class)->get('disabled_modules', []);
        $list = is_array($value) ? $value : (json_decode((string) $value, true) ?: []);
        $optionalOn = (array) app(SettingsService::class)->get('optional_modules', []);
        $list = array_merge($list, array_diff(self::OPTIONAL, $optionalOn));

        return array_values(array_unique(array_intersect($list, array_keys(self::toggleable()))));
    }

    public static function enabled(string $module): bool
    {
        return ! in_array($module, self::disabled(), true);
    }

    /**
     * Remove the permissions of switched-off modules (and of features that depend on them).
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    public static function filter(array $keys): array
    {
        $off = self::disabled();
        if (! $off) {
            return array_values($keys);
        }

        return array_values(array_filter($keys, function (string $key) use ($off) {
            if (in_array(strstr($key, '.', true) ?: $key, $off, true)) {
                return false;
            }
            $needs = self::DEPENDS_ON[$key] ?? null;

            return ! ($needs && in_array($needs, $off, true));
        }));
    }
}

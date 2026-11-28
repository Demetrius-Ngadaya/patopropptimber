<?php

namespace App\Platform\Support;

use App\Support\Modules;

/**
 * What a package can switch on or off.
 *
 * Every optional module of the system (sales, purchases, accounting, payroll…)
 * is a feature with the same key, so a package simply lists modules. A module
 * left out is switched off for that business exactly like the Software
 * Engineer's Modules screen does: its menu items, pages and API routes go.
 * A few extra features are not modules.
 */
final class Features
{
    /** Features that are not one of the system's modules. */
    public const EXTRA = [
        'voice' => 'Selling by voice (mobile app)',
        'online_shop' => 'Online shop for customers',
        'whatsapp' => 'WhatsApp messages',
    ];

    /** Quantities a package can limit. */
    public const LIMITS = [
        'users' => 'Staff users',
        'branches' => 'Branches',
        'products' => 'Products',
        'sms_per_month' => 'SMS per month',
    ];

    /** @return array<string, string> key => label */
    public static function all(): array
    {
        return Modules::toggleable() + self::EXTRA;
    }

    /** @return array<int, string> */
    public static function moduleKeys(): array
    {
        return array_keys(Modules::toggleable());
    }
}

<?php

namespace App\Support;

/**
 * Groups notification types for the mobile app, where each group gets its own
 * alert: voice, sound, vibration (Settings → Notifications on the phone).
 */
final class NotificationCategories
{
    /** @var array<string, array<int, string>> */
    private const TYPES = [
        'orders' => ['new_order', 'online_payment'],
        'stock' => ['low_stock', 'out_of_stock', 'stock_digest'],
        'money' => ['sales_report', 'debt_overdue', 'purchase_due', 'agency_difference', 'sale_cancelled'],
        'team' => ['leave_request', 'shift', 'payroll_prepared', 'purchase_plan', 'note_reminder'],
    ];

    /** @return array<int, string> */
    public static function keys(): array
    {
        return [...array_keys(self::TYPES), 'general'];
    }

    public static function forType(?string $type): string
    {
        foreach (self::TYPES as $category => $types) {
            if (in_array($type, $types, true)) {
                return $category;
            }
        }

        return 'general';
    }

    /**
     * New online orders speak out loud by default: the phone is often in a
     * pocket while the shop is busy. Everything else sounds and vibrates.
     *
     * @return array{enabled: bool, voice: bool, sound: bool, vibrate: bool}
     */
    public static function defaultMode(string $category): array
    {
        return ['enabled' => true, 'voice' => $category === 'orders', 'sound' => true, 'vibrate' => true];
    }
}

<?php

namespace App\Support;

use App\Services\Core\SettingsService;
use App\Services\Core\SmsService;

/**
 * The three ways the system can send a message, which of them the admin has
 * made available, and where each type of message goes.
 */
final class Channels
{
    public const SMS = 'sms';

    public const WHATSAPP = 'whatsapp';

    public const EMAIL = 'email';

    public const ALL = [self::SMS, self::WHATSAPP, self::EMAIL];

    /** @return array<int, string> channels the admin allows anyone to use */
    public static function enabled(): array
    {
        $chosen = (array) self::settings()->get('messaging_channels', []);

        return array_values(array_intersect(self::ALL, $chosen));
    }

    /**
     * Which channels the server is set up for (keys and SMTP in .env).
     *
     * @return array<string, bool>
     */
    public static function configured(): array
    {
        $mailer = (string) config('mail.default');

        return [
            self::SMS => (bool) config('mauzo.sms.enabled') && filled(config('mauzo.sms.token')),
            self::WHATSAPP => (bool) config('mauzo.whatsapp.enabled') && filled(config('mauzo.whatsapp.token')) && filled(config('mauzo.whatsapp.phone_number_id')),
            self::EMAIL => ! in_array($mailer, ['log', ''], true) && filled(config('mail.from.address')),
        ];
    }

    /**
     * Keep only real, enabled channels, in a stable order.
     *
     * @param  array<int, mixed>  $requested
     * @return array<int, string>
     */
    public static function filter(array $requested): array
    {
        return array_values(array_intersect(self::enabled(), $requested));
    }

    /** @return array<int, string> channels an automatic message of this type goes out on */
    public static function forType(string $type): array
    {
        $routing = (array) self::settings()->get('notification_channels', []);
        $chosen = array_key_exists($type, $routing) ? (array) $routing[$type] : (NotificationTypes::get($type)['channels'] ?? []);

        return self::filter($chosen);
    }

    /** The staff recipient lists from Settings → Messages. */
    public static function staffRecipients(): Recipients
    {
        $settings = self::settings();

        return new Recipients(
            sms: self::phones((string) $settings->get('notify_sms_numbers')),
            whatsapp: self::phones((string) $settings->get('notify_whatsapp_numbers')),
            emails: self::emails((string) $settings->get('notify_emails')),
        );
    }

    /** @return array<int, string> normalised, unique phone numbers */
    public static function phones(string|array|null $list): array
    {
        $items = is_array($list) ? $list : preg_split('/[\s,;]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter(array_map([SmsService::class, 'normalizePhone'], $items))));
    }

    /** @return array<int, string> valid, unique, lower-case addresses */
    public static function emails(string|array|null $list): array
    {
        $items = is_array($list) ? $list : preg_split('/[\s,;]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY);
        $items = array_map(fn ($e) => mb_strtolower(trim((string) $e)), $items);

        return array_values(array_unique(array_filter($items, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    }

    private static function settings(): SettingsService
    {
        return app(SettingsService::class);
    }
}

<?php

namespace App\Platform\Support;

use App\Platform\Models\Business;

/** Rules for a business's web address: patopro.shezoni.com/<slug>. */
final class Slug
{
    public static function normalise(string $slug): string
    {
        return trim(mb_strtolower($slug));
    }

    /** null when the slug can be used, otherwise the reason it cannot. */
    public static function problem(string $slug, ?int $ignoreId = null): ?string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/', $slug) || str_contains($slug, '--')) {
            return __('Use 3 to 30 small letters, numbers or single hyphens (e.g. duka-la-juma).');
        }
        if (in_array($slug, (array) config('patopro.platform.reserved_slugs'), true)) {
            return __('This name is reserved. Choose another one.');
        }
        $taken = Business::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();

        return $taken ? __('This name is already taken. Choose another one.') : null;
    }

    /** Tanzanian numbers in one form: 0712 345 678 / +255712345678 → 255712345678. */
    public static function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '255'.substr($digits, 1);
        }
        if (strlen($digits) === 9 && in_array($digits[0], ['6', '7'], true)) {
            return '255'.$digits;
        }

        return $digits;
    }
}

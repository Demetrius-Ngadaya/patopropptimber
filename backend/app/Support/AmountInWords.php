<?php

namespace App\Support;

use NumberFormatter;

/** "One million two hundred thousand" / "milioni moja, laki mbili" for invoices. Needs PHP intl. */
final class AmountInWords
{
    public static function make(float $amount, string $currency, ?string $locale = null): ?string
    {
        if (! class_exists(NumberFormatter::class)) {
            return null;
        }
        $locale ??= app()->getLocale();
        $speller = new NumberFormatter($locale === 'sw' ? 'sw' : 'en', NumberFormatter::SPELLOUT);
        $whole = (int) floor(round($amount, 2));
        $cents = (int) round(($amount - $whole) * 100);
        $words = $speller->format($whole);
        if ($words === false) {
            return null;
        }
        $text = ucfirst($words).' '.$currency;
        if ($cents > 0) {
            $text .= ' '.($locale === 'sw' ? 'na senti' : 'and').' '.$speller->format($cents).($locale === 'sw' ? '' : ' cents');
        }

        return $text.($locale === 'sw' ? ' tu' : ' only');
    }
}

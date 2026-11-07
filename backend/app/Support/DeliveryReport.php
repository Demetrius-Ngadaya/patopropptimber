<?php

namespace App\Support;

/** Turns a Messenger result into one sentence for the person who pressed "Send". */
final class DeliveryReport
{
    /** @param array<string, array{recipients: int, sent: bool}> $result */
    public static function sentAny(array $result): bool
    {
        return collect($result)->contains(fn ($r) => $r['sent']);
    }

    /** @param array<string, array{recipients: int, sent: bool}> $result */
    public static function message(array $result): string
    {
        if ($result === []) {
            return __('Nothing was sent: choose at least one way of sending.');
        }
        $names = [Channels::SMS => __('SMS'), Channels::WHATSAPP => __('WhatsApp'), Channels::EMAIL => __('Email')];
        $parts = [];
        foreach ($result as $channel => $r) {
            $name = $names[$channel] ?? $channel;
            $parts[] = match (true) {
                $r['recipients'] === 0 => __(':channel: no address to send to', ['channel' => $name]),
                $r['sent'] => __(':channel: sent to :count', ['channel' => $name, 'count' => $r['recipients']]),
                default => __(':channel: not sent (see the message log)', ['channel' => $name]),
            };
        }

        return implode(' · ', $parts).'.';
    }
}

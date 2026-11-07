<?php

namespace App\Services\Core;

use App\Services\Messaging\MessageLogger;
use App\Support\Channels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The SMS transport: Webline Africa bulk SMS API. Logs every attempt and never
 * throws, so an SMS failure cannot undo a sale. What to say, and on which
 * channels, is decided by the Messaging services.
 */
class SmsService
{
    /** Convert local formats (07XX..., +2557XX...) to 2557XXXXXXXX. */
    public static function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '255'.substr($digits, 1);
        } elseif (strlen($digits) === 9 && in_array($digits[0], ['6', '7'], true)) {
            $digits = '255'.$digits;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    /**
     * @param  string|array<string>  $to
     * @return bool true when the provider accepted the message
     */
    public function send(string|array $to, string $message, string $purpose = 'manual', ?Model $related = null): bool
    {
        return $this->sendText((array) $to, $message, $purpose, $related?->getMorphClass(), $related?->getKey());
    }

    /** @param array<int, string> $to */
    public function sendText(array $to, string $message, string $purpose, ?string $relatedType = null, ?int $relatedId = null): bool
    {
        $recipients = array_values(array_unique(array_filter(array_map([self::class, 'normalizePhone'], $to))));
        if ($recipients === []) {
            return false;
        }

        $config = config('mauzo.sms');
        $status = 'disabled';
        $response = 'SMS sending is disabled (SMS_ENABLED=false).';

        if ($config['enabled'] && $config['token']) {
            try {
                $http = Http::withToken($config['token'])
                    ->acceptJson()
                    ->timeout($config['timeout'])
                    ->post($config['api_url'], [
                        'recipient' => implode(',', $recipients),
                        'sender_id' => $config['sender_id'],
                        'type' => 'plain',
                        'message' => $message,
                    ]);
                $body = $http->json();
                $ok = $http->successful() && (($body['status'] ?? null) === 'success');
                $status = $ok ? 'sent' : 'failed';
                $response = mb_substr($http->body(), 0, 2000);
            } catch (Throwable $e) {
                $status = 'failed';
                $response = $e->getMessage();
                Log::warning('SMS send failed: '.$e->getMessage());
            }
        }

        foreach ($recipients as $recipient) {
            MessageLogger::log(Channels::SMS, $recipient, $message, $purpose, $status, $response, $relatedType, $relatedId);
        }

        return $status === 'sent';
    }
}

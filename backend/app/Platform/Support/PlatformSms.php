<?php

namespace App\Platform\Support;

use App\Services\Core\SmsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SMS from SHEZONI itself (sign-up codes, welcome, subscription reminders),
 * through the platform's Webline account in .env. Unlike SmsService it keeps
 * no copy in a business database, because there may not be one yet.
 */
class PlatformSms
{
    public function enabled(): bool
    {
        return (bool) config('mauzo.sms.enabled') && (bool) config('mauzo.sms.token');
    }

    public function send(string $phone, string $message): bool
    {
        $to = SmsService::normalizePhone($phone);
        if (! $to || ! $this->enabled()) {
            Log::info('Platform SMS not sent'.($to ? '' : ' (bad number)').": $phone");

            return false;
        }

        $c = config('mauzo.sms');
        try {
            $response = Http::withToken($c['token'])->acceptJson()->timeout($c['timeout'] ?? 20)->post($c['api_url'], [
                'recipient' => $to,
                'sender_id' => $c['sender_id'],
                'type' => 'plain',
                'message' => $message,
            ]);
            $ok = $response->successful() && ($response->json('status') === 'success');
            if (! $ok) {
                Log::warning('Platform SMS failed: '.mb_substr($response->body(), 0, 300));
            }

            return $ok;
        } catch (Throwable $e) {
            Log::warning('Platform SMS failed: '.$e->getMessage());

            return false;
        }
    }
}

<?php

namespace App\Services\Messaging;

use App\Support\Channels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp through Meta's WhatsApp Business Cloud API. Never throws: a failed
 * message must not undo the sale or report that triggered it.
 *
 * With WHATSAPP_TEMPLATE set, messages go out as that approved template with
 * the text as its single variable (the only way to reach people who have not
 * written to the business in the last 24 hours). Without it, plain text.
 */
class WhatsAppService
{
    /** Meta rejects template variables with new lines, tabs or more than 4 spaces in a row. */
    private const TEMPLATE_PARAM_MAX = 1000;

    /** @param array<int, string> $to normalised numbers */
    public function send(array $to, string $text, string $purpose, ?string $relatedType = null, ?int $relatedId = null): bool
    {
        $config = config('mauzo.whatsapp');
        $configured = Channels::configured()[Channels::WHATSAPP];
        $any = false;

        foreach (array_values(array_unique($to)) as $number) {
            if (! $configured) {
                MessageLogger::log(Channels::WHATSAPP, $number, $text, $purpose, 'disabled', 'WhatsApp is not set up on the server (WHATSAPP_ENABLED, WHATSAPP_TOKEN, WHATSAPP_PHONE_NUMBER_ID).', $relatedType, $relatedId);

                continue;
            }
            $status = 'failed';
            try {
                $response = Http::withToken((string) $config['token'])
                    ->acceptJson()
                    ->timeout((int) $config['timeout'])
                    ->post(rtrim((string) $config['base_url'], '/').'/'.$config['api_version'].'/'.$config['phone_number_id'].'/messages', $this->payload($number, $text, $config));
                $status = $response->successful() && $response->json('messages.0.id') ? 'sent' : 'failed';
                $body = $response->body();
            } catch (Throwable $e) {
                $body = $e->getMessage();
                Log::warning('WhatsApp send failed: '.$e->getMessage());
            }
            MessageLogger::log(Channels::WHATSAPP, $number, $text, $purpose, $status, $body, $relatedType, $relatedId);
            $any = $any || $status === 'sent';
        }

        return $any;
    }

    private function payload(string $number, string $text, array $config): array
    {
        $base = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $number];

        if (filled($config['template'])) {
            $param = trim((string) preg_replace(['/\s*\n+\s*/', '/\t/', '/ {4,}/'], [' · ', ' ', '   '], $text));

            return $base + ['type' => 'template', 'template' => [
                'name' => $config['template'],
                'language' => ['code' => $config['template_language']],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($param, 0, self::TEMPLATE_PARAM_MAX)]]]],
            ]];
        }

        return $base + ['type' => 'text', 'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4096)]];
    }
}

<?php

namespace App\Services\Messaging;

use App\Models\Core\SmsLog;
use Illuminate\Support\Facades\Auth;

/** Every message attempt, on every channel, is written to the message log. */
final class MessageLogger
{
    public static function log(string $channel, string $recipient, string $message, string $purpose, string $status, ?string $response, ?string $relatedType, ?int $relatedId, ?string $subject = null, bool $attachment = false): void
    {
        SmsLog::create([
            'channel' => $channel,
            'recipient' => mb_substr($recipient, 0, 150),
            'subject' => $subject ? mb_substr($subject, 0, 200) : null,
            'message' => $message,
            'has_attachment' => $attachment,
            'purpose' => mb_substr($purpose, 0, 40),
            'status' => $status,
            'response' => $response ? mb_substr($response, 0, 2000) : null,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'sent_by' => Auth::id(),
        ]);
    }
}

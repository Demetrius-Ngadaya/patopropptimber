<?php

namespace App\Services\Notifications;

use App\Models\Core\AppNotification;
use App\Services\Messaging\Messenger;
use App\Support\Afterwards;
use App\Support\Channels;
use App\Support\NotificationTypes;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One entry point for staff notifications:
 *  1. always a bell notification, visible by permission and branch;
 *  2. plus SMS / WhatsApp / email to the recipient lists, on the channels the
 *     admin chose for this type (Settings → Messages), after the response.
 */
class Notifier
{
    public function __construct(private readonly Messenger $messenger) {}

    public function notify(StaffNotice $notice): ?AppNotification
    {
        $notification = null;
        if ($notice->bell) {
            try {
                if ($notice->dedupeKey && AppNotification::query()->where('dedupe_key', $notice->dedupeKey)
                    ->where('created_at', '>=', now()->subHours($notice->dedupeHours))->exists()) {
                    return null;
                }

                $notification = AppNotification::create([
                    'type' => $notice->type,
                    'level' => $notice->level,
                    'title' => $notice->title,
                    'body' => $notice->body,
                    'params' => $notice->params,
                    'url' => $notice->url,
                    'permission' => $notice->permission ?? (NotificationTypes::get($notice->type)['permission'] ?? null),
                    'branch_id' => $notice->branchId,
                    'dedupe_key' => $notice->dedupeKey,
                    'role_ids' => $notice->roleIds ? array_values(array_map('intval', $notice->roleIds)) : null,
                    'user_id' => $notice->userId,
                ]);
            } catch (Throwable $e) {
                Log::warning('Notification failed: '.$e->getMessage());

                return null;
            }
        }

        $channels = Channels::forType($notice->type);
        if ($channels && $notice->message) {
            $build = $notice->message;
            Afterwards::run(function () use ($build, $channels) {
                $message = $this->messenger->inMessageLocale($build);
                if ($message) {
                    $this->messenger->send($message, $channels, Channels::staffRecipients());
                }
            });
        }

        return $notification;
    }
}

<?php

namespace App\Services\Notifications;

use App\Services\Messaging\OutgoingMessage;
use Closure;

/**
 * Something staff should know about. Title and body are English with
 * :placeholders; each reader sees them in their own language.
 */
final class StaffNotice
{
    /**
     * @param  Closure(): ?OutgoingMessage|null  $message  builds the SMS / WhatsApp / email version (only if a channel is on)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly array $params = [],
        public readonly string $level = 'info',
        public readonly ?string $url = null,
        public readonly ?int $branchId = null,
        public readonly ?string $dedupeKey = null,
        public readonly int $dedupeHours = 24,
        public readonly ?Closure $message = null,
        /** Default: the permission of the type in NotificationTypes. */
        public readonly ?string $permission = null,
        /** false = only SMS / WhatsApp / email, no bell entry (e.g. a combined summary of per-branch bell entries). */
        public readonly bool $bell = true,
        /** Only users of these roles see it in the bell. */
        public readonly ?array $roleIds = null,
        /** Only this user sees it in the bell. */
        public readonly ?int $userId = null,
    ) {}
}

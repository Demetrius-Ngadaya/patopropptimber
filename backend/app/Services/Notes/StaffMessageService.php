<?php

namespace App\Services\Notes;

use App\Exceptions\BusinessException;
use App\Models\Core\User;
use App\Models\Notes\StaffMessage;
use App\Models\Notes\StaffMessageRecipient;
use App\Services\Core\AuditLogger;
use App\Services\Core\SettingsService;
use App\Services\Messaging\Messenger;
use App\Services\Messaging\OutgoingMessage;
use App\Support\Afterwards;
use App\Support\Channels;
use App\Support\Recipients;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Messages from the administration to staff. Reminders and announcements
 * that need confirming are shown as soon as the person logs in, until they
 * confirm they have read them. A copy can also go by SMS, WhatsApp or email.
 */
class StaffMessageService
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly AuditLogger $audit,
    ) {}

    public function send(User $sender, array $data): StaffMessage
    {
        $message = DB::transaction(function () use ($sender, $data) {
            $message = StaffMessage::create([
                'sender_id' => $sender->id,
                'kind' => $data['kind'],
                'priority' => $data['priority'] ?? 'normal',
                'title' => $data['title'],
                'body' => $data['body'],
                'audience' => $data['audience'],
                'role_ids' => $data['audience'] === 'roles' ? array_values(array_map('intval', $data['role_ids'] ?? [])) : null,
                'user_ids' => $data['audience'] === 'users' ? array_values(array_map('intval', $data['user_ids'] ?? [])) : null,
                'branch_id' => $data['branch_id'] ?? null,
                'requires_ack' => (bool) ($data['requires_ack'] ?? $data['kind'] !== 'message'),
                'show_from' => $data['show_from'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
            ]);
            $users = $this->audienceOf($message, $sender);
            if ($users->isEmpty()) {
                throw new BusinessException(__('Nobody matches the people you chose.'), 'NO_RECIPIENTS');
            }
            $now = now();
            StaffMessageRecipient::insert($users->map(fn (User $u) => [
                'staff_message_id' => $message->id, 'user_id' => $u->id, 'created_at' => $now, 'updated_at' => $now,
            ])->all());

            return $message;
        });

        $this->audit->event('staff_message.sent', __(':kind ":title" sent to :count people', ['kind' => __(ucfirst($message->kind)), 'title' => $message->title, 'count' => $message->recipients()->count()]), null);

        $channels = Channels::filter((array) ($data['channels'] ?? []));
        if ($channels) {
            $users = User::query()->whereIn('id', $message->recipients()->pluck('user_id'))->get(['phone', 'email']);
            $phones = Channels::phones($users->pluck('phone')->filter()->all());
            $to = new Recipients($phones, $phones, Channels::emails($users->pluck('email')->filter()->all()));
            Afterwards::run(function () use ($message, $channels, $to, $sender) {
                $built = $this->messenger->inMessageLocale(fn () => $this->outgoing($message, $sender));
                $this->messenger->send($built, $channels, $to);
            });
        }

        return $message;
    }

    /** Messages this person can see now. */
    public function inboxQuery(User $user): Builder
    {
        return StaffMessageRecipient::query()->where('user_id', $user->id)
            ->whereHas('message', fn ($q) => $q->live())
            ->with(['message' => fn ($q) => $q->with('sender:id,name')]);
    }

    /** Reminders and announcements to show at login, until confirmed. */
    public function pending(User $user): Collection
    {
        return $this->inboxQuery($user)->whereNull('acknowledged_at')
            ->whereHas('message', fn ($q) => $q->where('requires_ack', true))
            ->orderByDesc('id')->limit(20)->get();
    }

    public function recipientFor(int $messageId, User $user): StaffMessageRecipient
    {
        return StaffMessageRecipient::query()->where('staff_message_id', $messageId)->where('user_id', $user->id)->firstOrFail();
    }

    /** @return Collection<int, User> */
    private function audienceOf(StaffMessage $message, User $sender): Collection
    {
        return User::query()->visibleTo($sender)->where('type', User::TYPE_STAFF)->where('is_active', true)
            ->where('id', '!=', $sender->id)
            ->when($message->audience === 'roles', fn ($q) => $q->whereIn('role_id', $message->role_ids ?: [0]))
            ->when($message->audience === 'users', fn ($q) => $q->whereIn('id', $message->user_ids ?: [0]))
            ->when($message->branch_id, fn ($q, $b) => $q->where(fn ($w) => $w->where('branch_id', $b)->orWhere('all_branches', true)))
            ->get(['id', 'phone', 'email']);
    }

    private function outgoing(StaffMessage $message, User $sender): OutgoingMessage
    {
        $business = (string) app(SettingsService::class)->get('business_name');
        $kind = __(ucfirst($message->kind));
        $sms = mb_substr("{$business} - {$kind}: {$message->title}. ".__('Open the system to read it.'), 0, 300);
        $text = "*{$business} - {$kind}*\n*{$message->title}*\n\n{$message->body}\n\n- {$sender->name}";

        return new OutgoingMessage('staff_message', $sms, $text, "{$kind}: {$message->title}", [
            'title' => $message->title,
            'subtitle' => $kind.' · '.$sender->name,
            'intro' => preg_split('/\n{2,}/', $message->body),
            'sections' => [],
        ]);
    }
}

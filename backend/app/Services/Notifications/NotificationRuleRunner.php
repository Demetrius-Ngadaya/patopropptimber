<?php

namespace App\Services\Notifications;

use App\Models\Core\NotificationRule;
use App\Models\Core\User;
use App\Services\Core\SettingsService;
use App\Services\Messaging\Messenger;
use App\Services\Messaging\OutgoingMessage;
use App\Support\Channels;
use App\Support\Recipients;

/**
 * Builds and sends a custom notification: fills the admin's templates (or the
 * defaults) with live figures, puts it in the bell for the chosen roles and
 * sends it on the chosen channels.
 */
class NotificationRuleRunner
{
    public function __construct(
        private readonly NotificationSources $sources,
        private readonly Messenger $messenger,
        private readonly Notifier $notifier,
        private readonly SettingsService $settings,
    ) {}

    /** The rendered messages and who would get them, without sending. */
    public function preview(NotificationRule $rule): array
    {
        [$message, $data] = $this->build($rule);
        $to = $this->recipients($rule);

        return [
            'sms' => $message->sms,
            'whatsapp' => $message->text,
            'email_subject' => $message->subject,
            'email_intro' => implode("\n\n", $message->document['intro'] ?? []),
            'variables' => $data['vars'],
            'empty' => $data['empty'],
            'recipients' => ['sms' => $to->sms, 'whatsapp' => $to->whatsapp, 'emails' => $to->emails],
        ];
    }

    /** @return array{skipped: bool, result: array} */
    public function run(NotificationRule $rule, bool $force = false): array
    {
        [$message, $data] = $this->build($rule);
        if ($data['empty'] && $rule->skip_when_empty && ! $force) {
            $outcome = ['skipped' => true, 'result' => []];
        } else {
            $this->notifier->notify(new StaffNotice(
                type: 'custom',
                title: ':name',
                body: ':text',
                params: ['name' => $rule->name, 'text' => $message->sms],
                level: 'info',
                branchId: $rule->branch_id,
                roleIds: $rule->role_ids ?: null,
                permission: $rule->role_ids ? null : (NotificationSources::catalogue()[$rule->source]['permission'] ?? null),
            ));
            $result = $this->messenger->send($message, Channels::filter((array) $rule->channels), $this->recipients($rule));
            $outcome = ['skipped' => false, 'result' => $result];
        }

        $rule->forceFill([
            'last_run_at' => now(),
            'last_result' => $outcome + ['at' => now()->toIso8601String()],
            'next_run_at' => $rule->nextRun(now()),
        ])->save();

        return $outcome;
    }

    public function recipients(NotificationRule $rule): Recipients
    {
        $sms = Channels::phones((string) $rule->extra_phones);
        $wa = Channels::phones((string) $rule->extra_whatsapp);
        $emails = Channels::emails((string) $rule->extra_emails);

        if ($rule->include_company_contacts) {
            $sms = array_merge($sms, Channels::phones([(string) $this->settings->get('business_phone')]));
            $wa = array_merge($wa, Channels::phones([(string) ($this->settings->get('business_whatsapp') ?: $this->settings->get('business_phone'))]));
            $emails = array_merge($emails, Channels::emails([(string) $this->settings->get('business_email')]));
        }
        if ($rule->send_to_role_users && $rule->role_ids) {
            $users = User::query()->where('type', User::TYPE_STAFF)->where('is_active', true)->whereIn('role_id', $rule->role_ids)
                ->when($rule->branch_id, fn ($q, $b) => $q->where(fn ($w) => $w->where('branch_id', $b)->orWhere('all_branches', true)))
                ->get(['phone', 'email']);
            $phones = Channels::phones($users->pluck('phone')->filter()->all());
            $sms = array_merge($sms, $phones);
            $wa = array_merge($wa, $phones);
            $emails = array_merge($emails, Channels::emails($users->pluck('email')->filter()->all()));
        }

        return new Recipients(array_values(array_unique($sms)), array_values(array_unique($wa)), array_values(array_unique($emails)));
    }

    /** @return array{0: OutgoingMessage, 1: array} */
    private function build(NotificationRule $rule): array
    {
        return $this->messenger->inMessageLocale(function () use ($rule) {
            [$from, $to, $label] = $rule->periodRange();
            $data = $this->sources->build($rule->source, $from, $to, $rule->branch_id ? [$rule->branch_id] : null);
            $defaults = NotificationSources::defaults($rule->source);
            $vars = $data['vars'] + [
                'business' => (string) $this->settings->get('business_name'),
                'name' => $rule->name,
                'period' => $label,
                'date' => now()->format('d/m/Y'),
                'currency' => (string) $this->settings->get('currency'),
            ];
            $fill = fn (?string $template, string $default) => trim(strtr(filled($template) ? $template : __($default), $this->placeholders($vars)));

            $sms = $fill($rule->sms_template, $defaults['sms']);
            $text = $fill($rule->whatsapp_template, $defaults['whatsapp']);
            $subject = $fill($rule->email_subject, $defaults['subject']);
            $intro = $fill($rule->email_template, $defaults['email']);
            $labels = NotificationSources::catalogue()[$rule->source]['variables'] ?? [];

            $doc = [
                'title' => $rule->name,
                'subtitle' => $label,
                'intro' => preg_split('/\n{2,}/', $intro),
                'meta' => collect($labels)->mapWithKeys(fn ($l, $k) => [__($l) => $data['vars'][$k] ?? '-'])->all(),
                'sections' => $data['sections'],
            ];

            return [new OutgoingMessage('custom:'.$rule->id, $sms, $text, $subject, $doc, 'notification-'.now()->format('Ymd-Hi').'.pdf'), $data];
        });
    }

    /** @return array<string, string> {name} => value */
    private function placeholders(array $vars): array
    {
        $out = [];
        foreach ($vars as $k => $v) {
            $out['{'.$k.'}'] = (string) $v;
        }

        return $out;
    }
}

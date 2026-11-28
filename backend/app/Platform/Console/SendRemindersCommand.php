<?php

namespace App\Platform\Console;

use App\Platform\Models\Business;
use App\Platform\Support\BusinessManager;
use App\Platform\Support\PlatformSms;
use App\Platform\Support\SubscriptionState;
use Illuminate\Console\Command;

/**
 * Daily: SMS to each business owner before the package ends (7, 3 and 1 days,
 * and on the last day), and once when the business becomes read-only.
 * Each reminder is sent only once per period.
 */
class SendRemindersCommand extends Command
{
    protected $signature = 'platform:reminders {--dry-run : Show what would be sent}';

    protected $description = 'SMS owners whose package is about to end or has ended';

    public function handle(PlatformSms $sms): int
    {
        if (! BusinessManager::platformMode()) {
            return self::SUCCESS;
        }
        $warnDays = (array) config('patopro.platform.subscription.warn_days');
        $grace = (int) config('patopro.platform.subscription.grace_days');
        $sent = 0;

        foreach (Business::query()->where('status', 'ready')->whereNull('archived_at')->whereNull('suspended_at')->whereNotNull('phone')->cursor() as $business) {
            $end = $business->servedUntil();
            if (! $end) {
                continue;
            }
            $state = $business->state();
            $url = $business->url('admin/billing');
            $kind = $state === SubscriptionState::Trial ? __('free trial', [], 'sw') : __('package', [], 'sw');
            $message = null;
            $key = null;

            if (in_array($state, [SubscriptionState::Trial, SubscriptionState::Active], true)) {
                $days = (int) now()->startOfDay()->diffInDays($end->copy()->startOfDay(), false);
                if (in_array($days, $warnDays, true)) {
                    $key = 'end:'.$end->toDateString().':'.$days;
                    $message = $days === 0
                        ? __('PatoPro: the :kind of :name ends today. You have :grace extra days; pay to keep selling: :url', ['kind' => $kind, 'name' => $business->name, 'grace' => $grace, 'url' => $url], 'sw')
                        : __('PatoPro: the :kind of :name ends in :days day(s), on :date. Pay to continue without interruption: :url', ['kind' => $kind, 'name' => $business->name, 'days' => $days, 'date' => $end->format('d/m/Y'), 'url' => $url], 'sw');
                }
            } elseif ($state === SubscriptionState::ReadOnly) {
                $key = 'read_only:'.$end->toDateString();
                $message = __('PatoPro: :name can now only view its records. Your data is safe. Pay to continue selling: :url', ['name' => $business->name, 'url' => $url], 'sw');
            }

            $meta = (array) $business->meta;
            $reminded = (array) ($meta['reminded'] ?? []);
            if (! $message || in_array($key, $reminded, true)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("{$business->slug}: $message");

                continue;
            }
            if ($sms->send($business->phone, $message)) {
                $meta['reminded'] = array_slice(array_merge($reminded, [$key]), -20);
                $business->forceFill(['meta' => $meta])->save();
                $sent++;
            }
        }

        $this->line("$sent reminder(s) sent.");

        return self::SUCCESS;
    }
}

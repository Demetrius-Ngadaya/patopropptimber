<?php

namespace App\Console\Commands;

use App\Models\Core\SmsLog;
use App\Models\Crm\Customer;
use App\Services\Core\SettingsService;
use App\Services\Messaging\CustomerMessages;
use App\Support\Channels;
use Illuminate\Console\Command;

class SendDebtReminders extends Command
{
    protected $signature = 'mauzo:debt-reminders {--dry-run}';

    protected $description = 'Remind customers whose credit sales are due or overdue (SMS, WhatsApp and/or email)';

    public function handle(SettingsService $settings, CustomerMessages $messages): int
    {
        if (! $settings->bool('sms_debt_reminders')) {
            $this->info('Automatic debt reminders are switched off in settings.');

            return self::SUCCESS;
        }

        $interval = $settings->int('debt_reminder_interval_days');
        $customers = Customer::query()
            ->where('is_active', true)->where('sms_opt_in', true)
            ->where(fn ($q) => $q->whereNotNull('phone')->orWhereNotNull('email'))
            ->where('balance', '>', 0)
            ->whereHas('sales', fn ($q) => $q->completed()->where('balance', '>', 0)->where('due_date', '<=', now()->endOfDay()))
            ->get();

        $channels = Channels::forType('customer_debt_reminder');
        if ($channels === []) {
            $this->info('No way of sending is chosen for debt reminders (Settings → Messages).');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($customers as $customer) {
            $recent = SmsLog::query()->where('related_type', $customer->getMorphClass())->where('related_id', $customer->id)
                ->where('purpose', 'debt_reminder')->where('created_at', '>=', now()->subDays($interval))->exists();
            if ($recent) {
                continue;
            }
            if ($this->option('dry-run')) {
                $this->line("Would remind {$customer->name} ({$customer->phone}): ".number_format((float) $customer->balance));

                continue;
            }
            $sent += collect($messages->debtReminder($customer, $channels))->contains(fn ($r) => $r['sent']) ? 1 : 0;
        }

        $this->info("Reminders sent: {$sent} of {$customers->count()} customers with due debts.");

        return self::SUCCESS;
    }
}

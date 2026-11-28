<?php

namespace App\Platform\Console;

use App\Platform\Billing\BillingService;
use App\Platform\Models\Payment;
use App\Platform\Support\BusinessManager;
use Illuminate\Console\Command;

/** Every few minutes: ask the gateways about payments still waiting (when a callback did not arrive). */
class CheckPaymentsCommand extends Command
{
    protected $signature = 'platform:payments:check';

    protected $description = 'Check package payments that are still waiting for confirmation';

    public function handle(BillingService $billing): int
    {
        if (! BusinessManager::platformMode()) {
            return self::SUCCESS;
        }

        $waiting = Payment::query()->where('status', 'pending')->where('gateway', '!=', 'manual')
            ->where('created_at', '<', now()->subMinute())->orderBy('id')->limit(100)->get();
        foreach ($waiting as $payment) {
            $after = $billing->refresh($payment);
            if ($after->status !== 'pending') {
                $this->line("{$payment->number}: {$after->status}");
            }
        }

        return self::SUCCESS;
    }
}

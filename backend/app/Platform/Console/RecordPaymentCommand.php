<?php

namespace App\Platform\Console;

use App\Platform\Billing\BillingService;
use App\Platform\Models\Business;
use App\Platform\Models\Plan;
use Illuminate\Console\Command;

/**
 * A payment made in cash or by bank:
 *   php artisan platform:pay duka-la-juma --plan=biashara --cycle=yearly --amount=350000 --note="Bank CRDB ref 123"
 */
class RecordPaymentCommand extends Command
{
    protected $signature = 'platform:pay {business : Its address (slug)} {--plan= : Package code (default: its current package)} {--cycle=monthly : monthly or yearly} {--amount= : Amount received (default: the package price)} {--note= : Receipt or bank reference}';

    protected $description = 'Record a cash or bank payment for a business package';

    public function handle(BillingService $billing): int
    {
        $business = Business::query()->where('slug', $this->argument('business'))->first();
        if (! $business) {
            $this->error('No such business.');

            return self::FAILURE;
        }
        $plan = $this->option('plan') ? Plan::query()->where('code', $this->option('plan'))->first() : $business->plan;
        if (! $plan) {
            $this->error('Package not found.');

            return self::FAILURE;
        }
        $cycle = $this->option('cycle') === 'yearly' ? 'yearly' : 'monthly';
        $amount = (float) ($this->option('amount') ?? ($cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly));

        $payment = $billing->recordManual($business, $plan, $cycle, $amount, 'Software Engineer (console)', $this->option('note'));
        $this->info("{$payment->number}: {$business->name} on {$plan->name} until {$payment->period_to->toDateString()}");

        return self::SUCCESS;
    }
}

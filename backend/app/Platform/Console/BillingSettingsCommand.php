<?php

namespace App\Platform\Console;

use App\Platform\Billing\BillingService;
use App\Platform\Models\Setting;
use App\Platform\Support\BusinessManager;
use Illuminate\Console\Command;

/**
 * The Software Engineer chooses how businesses pay for packages:
 *   php artisan platform:billing                                   (show)
 *   php artisan platform:billing --enable=azampay --enable=snippe --default=azampay --choice=no
 *   php artisan platform:billing --disable=snippe
 */
class BillingSettingsCommand extends Command
{
    protected $signature = 'platform:billing {--enable=* : Offer this gateway} {--disable=* : Stop offering this gateway} {--default= : The gateway used when businesses cannot choose} {--choice= : yes = businesses choose among the offered gateways, no = only the default}';

    protected $description = 'Choose which payment gateways businesses use to pay for packages';

    public function handle(BillingService $billing): int
    {
        if (! BusinessManager::platformMode()) {
            $this->error('Not in platform mode.');

            return self::FAILURE;
        }
        $known = array_keys($billing->gateways());

        $enabled = $billing->enabledKeys();
        foreach ((array) $this->option('enable') as $key) {
            if (! in_array($key, $known, true)) {
                $this->error("Unknown gateway '$key'. Known: ".implode(', ', $known));

                return self::FAILURE;
            }
            $enabled[] = $key;
        }
        $enabled = array_values(array_diff(array_unique($enabled), (array) $this->option('disable')));
        if ($this->option('enable') || $this->option('disable')) {
            Setting::put('billing.gateways', $enabled);
        }
        if ($default = $this->option('default')) {
            if (! in_array($default, $known, true)) {
                $this->error("Unknown gateway '$default'.");

                return self::FAILURE;
            }
            Setting::put('billing.default', $default);
        }
        if ($this->option('choice') !== null) {
            Setting::put('billing.customer_choice', in_array(strtolower((string) $this->option('choice')), ['yes', '1', 'true', 'on'], true));
        }

        $this->table(['Gateway', 'Offered', 'Keys in .env', 'Default'], collect($billing->gateways())->map(fn ($g, $key) => [
            $g->label(),
            in_array($key, $billing->enabledKeys(), true) ? 'yes' : 'no',
            $g->configured() ? 'yes' : 'MISSING',
            $key === $billing->defaultKey() ? '★' : '',
        ])->values());
        $this->line('Businesses choose the gateway: '.($billing->customerChoice() ? 'yes' : 'no (they use the default)'));
        $this->line('Businesses see: '.(implode(', ', $billing->offeredKeys()) ?: 'NOTHING – no gateway is offered with its keys set'));

        return self::SUCCESS;
    }
}

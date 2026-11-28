<?php

namespace App\Platform\Console;

use App\Platform\Models\Business;
use App\Platform\Models\Signup;
use App\Platform\Provisioning\BusinessProvisioner;
use App\Platform\Support\BusinessManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Every minute (platform scheduler): finish setting up businesses registered
 * online whose setup did not complete during the sign-up request.
 */
class ProvisionPendingCommand extends Command
{
    protected $signature = 'platform:provision';

    protected $description = 'Finish setting up newly registered businesses';

    public function handle(BusinessProvisioner $provisioner): int
    {
        if (! BusinessManager::platformMode()) {
            return self::SUCCESS;
        }

        $pending = Business::query()
            ->whereIn('status', ['provisioning', 'failed'])
            ->where('updated_at', '<', now()->subMinutes(2))
            ->where('created_at', '>', now()->subDay()) // after a day of failures it needs a person
            ->get()
            ->filter(fn (Business $b) => (int) ($b->meta['attempts'] ?? 0) < 5); // then it needs a person

        foreach ($pending as $business) {
            $signup = Signup::query()->where('business_id', $business->id)->first();
            if (! $signup) {
                continue; // made with platform:business:create: run that command again instead
            }
            $this->line("Setting up {$business->slug}");
            try {
                $provisioner->provision($business, $signup->owner());
                $signup->sendWelcome($business);
            } catch (Throwable $e) {
                $this->error("  {$business->slug}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}

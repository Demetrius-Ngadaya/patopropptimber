<?php

namespace App\Platform\Console;

use App\Platform\Provisioning\BusinessProvisioner;
use App\Platform\Support\BusinessManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan platform:template — create or bring up to date the template database
 * new businesses are copied from. platform:migrate refreshes it too.
 */
class RefreshTemplateCommand extends Command
{
    protected $signature = 'platform:template';

    protected $description = 'Create or update the template database that new businesses are copied from';

    public function handle(BusinessProvisioner $provisioner): int
    {
        if (! BusinessManager::platformMode()) {
            $this->error('Not in platform mode.');

            return self::FAILURE;
        }

        try {
            $provisioner->refreshTemplate(fn (string $line) => $this->line($line));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Template ready: '.BusinessProvisioner::templateName());

        return self::SUCCESS;
    }
}

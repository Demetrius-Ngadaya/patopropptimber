<?php

namespace App\Console\Commands;

use App\Models\Core\NotificationRule;
use App\Services\Notifications\NotificationRuleRunner;
use Illuminate\Console\Command;
use Throwable;

/** Sends the custom notifications that are due. Runs every minute from the scheduler. */
class RunNotificationRules extends Command
{
    protected $signature = 'mauzo:notification-rules';

    protected $description = 'Send the custom notifications that are due';

    public function handle(NotificationRuleRunner $runner): int
    {
        $due = NotificationRule::query()->where('is_active', true)->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->get();
        foreach ($due as $rule) {
            try {
                $outcome = $runner->run($rule);
                $this->info("{$rule->name}: ".($outcome['skipped'] ? 'nothing to report' : 'sent'));
            } catch (Throwable $e) {
                report($e);
                $rule->forceFill(['last_run_at' => now(), 'last_result' => ['error' => $e->getMessage()], 'next_run_at' => $rule->nextRun(now())])->save();
                $this->error("{$rule->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}

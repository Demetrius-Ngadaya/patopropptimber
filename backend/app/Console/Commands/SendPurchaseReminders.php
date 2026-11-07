<?php

namespace App\Console\Commands;

use App\Services\Purchasing\PurchaseReminders;
use Illuminate\Console\Command;
use Throwable;

/** Remind the company to pay suppliers for goods bought on credit. */
class SendPurchaseReminders extends Command
{
    protected $signature = 'mauzo:purchase-reminders';

    protected $description = 'Supplier payment reminders for credit purchases (bell, SMS, WhatsApp, email with PDF)';

    public function handle(PurchaseReminders $reminders): int
    {
        $due = $reminders->due();
        foreach ($due as $purchase) {
            try {
                $reminders->send($purchase);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info("Reminders sent: {$due->count()}.");

        return self::SUCCESS;
    }
}

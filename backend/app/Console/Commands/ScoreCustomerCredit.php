<?php

namespace App\Console\Commands;

use App\Services\Ai\CreditScorer;
use Illuminate\Console\Command;

class ScoreCustomerCredit extends Command
{
    protected $signature = 'mauzo:score-credit';

    protected $description = 'Recompute AI credit scores for all credit customers and keep a dated snapshot';

    public function handle(CreditScorer $scorer): int
    {
        $this->info(__(':n customer(s) scored.', ['n' => $scorer->scoreAll()]));

        return self::SUCCESS;
    }
}

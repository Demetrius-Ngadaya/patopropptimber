<?php

namespace App\Services\Ai;

use App\Models\Ai\CustomerCreditScore;
use App\Models\Crm\Customer;
use App\Models\Crm\SavingsAccount;
use App\Models\Sales\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Explainable credit score (0-100) for a customer, from their own history:
 * repaying on time, how late, what is overdue now, use of the credit limit,
 * how long and how often they buy, and their savings. Every point is shown
 * with its reason, so staff can explain a decision to the customer.
 */
class CreditScorer
{
    private const GRACE_DAYS = 3;

    public const BANDS = ['A' => 80, 'B' => 65, 'C' => 50, 'D' => 35, 'E' => 0];

    private const LIMIT_MULTIPLIER = ['A' => 1.5, 'B' => 1.0, 'C' => 0.5, 'D' => 0.25, 'E' => 0.0];

    /** @return array{score: int, band: string, suggested_limit: float, factors: array, summary: array} */
    public function score(Customer $customer): array
    {
        $today = today();
        $credit = Sale::query()->where('customer_id', $customer->id)->completed()
            ->where(fn ($q) => $q->where('is_credit', true)->orWhere('credit_amount', '>', 0))
            ->get(['id', 'credit_amount', 'balance', 'due_date', 'fully_paid_at', 'sold_at']);
        $settled = $credit->filter(fn ($s) => (float) $s->balance <= 0 && $s->fully_paid_at);
        $open = $credit->filter(fn ($s) => (float) $s->balance > 0);

        $factors = [
            $this->paymentHistory($settled),
            $this->lateness($settled),
            $this->overdue($open, $today),
            $this->utilisation($customer, $open),
            $this->relationship($customer, $today),
            $this->savings($customer),
        ];
        $score = (int) max(0, min(100, round(array_sum(array_column($factors, 'points')))));
        $band = collect(self::BANDS)->filter(fn ($min) => $score >= $min)->keys()->first();

        $monthly = (float) Sale::query()->where('customer_id', $customer->id)->completed()
            ->where('sold_at', '>=', $today->copy()->subMonths(6))->sum('total') / 6;
        $suggested = round($monthly * self::LIMIT_MULTIPLIER[$band] / 1000) * 1000;

        return [
            'score' => $score,
            'band' => $band,
            'suggested_limit' => (float) $suggested,
            'factors' => $factors,
            'summary' => [
                'credit_sales' => $credit->count(),
                'settled' => $settled->count(),
                'outstanding' => round((float) $open->sum('balance'), 2),
                'overdue' => round((float) $open->filter(fn ($s) => $s->due_date && $s->due_date->lt($today))->sum('balance'), 2),
                'avg_monthly_purchases' => round($monthly, 2),
                'current_limit' => (float) $customer->credit_limit,
            ],
        ];
    }

    /** Score every credit customer and keep a dated snapshot. */
    public function scoreAll(): int
    {
        $count = 0;
        Customer::query()->where(fn ($q) => $q->where('allow_credit', true)->orWhere('balance', '>', 0))
            ->orderBy('id')->each(function (Customer $c) use (&$count) {
                $r = $this->score($c);
                CustomerCreditScore::create([
                    'customer_id' => $c->id, 'score' => $r['score'], 'band' => $r['band'],
                    'suggested_limit' => $r['suggested_limit'], 'factors' => $r['factors'], 'computed_at' => now(),
                ]);
                $count++;
            });

        return $count;
    }

    // -------------------------------------------------------------- factors

    private function paymentHistory(Collection $settled): array
    {
        if ($settled->isEmpty()) {
            return $this->factor('payment_history', 20, 40, __('No repaid credit yet: neutral score.'));
        }
        $total = (float) $settled->sum('credit_amount') ?: 1;
        $onTime = (float) $settled->filter(fn ($s) => ! $s->due_date || $s->fully_paid_at->copy()->startOfDay()->lte($s->due_date->copy()->addDays(self::GRACE_DAYS)))->sum('credit_amount');
        $share = $onTime / $total;

        return $this->factor('payment_history', round(40 * $share, 1), 40, __(':pct% of repaid credit was paid on time.', ['pct' => round($share * 100)]));
    }

    private function lateness(Collection $settled): array
    {
        if ($settled->isEmpty()) {
            return $this->factor('lateness', 8, 15, __('No history of late payment.'));
        }
        $avg = $settled->avg(fn ($s) => $s->due_date ? max(0, $s->due_date->diffInDays($s->fully_paid_at->copy()->startOfDay(), false)) : 0);

        return $this->factor('lateness', round(15 * max(0, 1 - $avg / 60), 1), 15, __('Pays on average :days day(s) after the due date.', ['days' => round($avg)]));
    }

    private function overdue(Collection $open, Carbon $today): array
    {
        $outstanding = (float) $open->sum('balance');
        if ($outstanding <= 0) {
            return $this->factor('overdue', 20, 20, __('Nothing overdue.'));
        }
        $overdue = $open->filter(fn ($s) => $s->due_date && $s->due_date->lt($today));
        $oldest = $overdue->map(fn ($s) => $s->due_date->diffInDays($today))->max() ?? 0;
        $ratio = (float) $overdue->sum('balance') / $outstanding;
        $points = 20 * (1 - $ratio) * ($oldest > 30 ? 0.5 : 1);

        return $this->factor('overdue', round($points, 1), 20, $overdue->isEmpty()
            ? __('Has debt but none of it is overdue.')
            : __(':amount overdue, the oldest by :days day(s).', ['amount' => number_format((float) $overdue->sum('balance'), 2), 'days' => $oldest]));
    }

    private function utilisation(Customer $customer, Collection $open): array
    {
        $limit = (float) $customer->credit_limit;
        if ($limit <= 0) {
            return $this->factor('utilisation', 5, 10, __('No credit limit set.'));
        }
        $use = (float) $open->sum('balance') / $limit;

        return $this->factor('utilisation', round(10 * max(0, 1 - $use), 1), 10, __('Using :pct% of the credit limit.', ['pct' => round($use * 100)]));
    }

    private function relationship(Customer $customer, Carbon $today): array
    {
        $months = $customer->created_at ? $customer->created_at->diffInMonths($today) : 0;
        $visits = Sale::query()->where('customer_id', $customer->id)->completed()->where('sold_at', '>=', $today->copy()->subDays(180))->count();
        $points = min(5, $months / 12 * 5) + min(5, $visits / 12 * 5);

        return $this->factor('relationship', round($points, 1), 10, __('Customer for :months month(s), :visits purchase(s) in the last 6 months.', ['months' => (int) $months, 'visits' => $visits]));
    }

    private function savings(Customer $customer): array
    {
        $balance = (float) (SavingsAccount::query()->where('customer_id', $customer->id)->value('balance') ?? 0);
        $owed = max(1, (float) $customer->balance);
        $points = $balance > 0 ? min(5, 5 * $balance / $owed) : 0;

        return $this->factor('savings', round($points, 1), 5, $balance > 0
            ? __('Has :amount in savings.', ['amount' => number_format($balance, 2)])
            : __('No savings.'));
    }

    private function factor(string $key, float $points, int $max, string $reason): array
    {
        return ['key' => $key, 'label' => __(str_replace('_', ' ', ucfirst($key))), 'points' => $points, 'max' => $max, 'reason' => $reason];
    }
}

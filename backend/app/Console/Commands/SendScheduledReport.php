<?php

namespace App\Console\Commands;

use App\Models\Core\Branch;
use App\Models\Finance\Expense;
use App\Models\Sales\Payment;
use App\Models\Sales\Sale;
use App\Services\Core\SettingsService;
use App\Services\Messaging\Messenger;
use App\Services\Messaging\OutgoingMessage;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\StaffNotice;
use App\Support\Channels;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The period sales / credit / expense report: a bell notification for staff with
 * reports.sales, plus SMS (short totals), WhatsApp (per branch) and email (details
 * + PDF of every sale and expense) on the channels chosen in Settings → Messages.
 * Scheduled in routes/console.php.
 */
class SendScheduledReport extends Command
{
    protected $signature = 'mauzo:send-report {period=daily : daily|weekly|monthly|yearly} {--date= : Reference date (Y-m-d), defaults to today} {--dry-run : Print the SMS and WhatsApp text instead of sending} {--force : Send again even if this period was already sent}';

    protected $description = 'Send the sales, credit and expense report (bell, SMS, WhatsApp, email)';

    public function handle(SettingsService $settings, Messenger $messenger, Notifier $notifier): int
    {
        $period = $this->argument('period');
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();

        [$from, $to] = match ($period) {
            'weekly' => [$date->copy()->startOfWeek(), $date->copy()->endOfWeek()],
            'monthly' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
            'yearly' => [$date->copy()->startOfYear(), $date->copy()->endOfYear()],
            'daily' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            default => [null, null],
        };
        if (! $from) {
            $this->error('Period must be daily, weekly, monthly or yearly.');

            return self::INVALID;
        }

        $excluded = array_filter(array_map('trim', explode(',', strtoupper((string) $settings->get('report_exclude_branch_codes')))));
        $branches = Branch::query()->where('is_active', true)->whereNotIn('code', $excluded ?: [''])->orderBy('name')->get();

        $rows = [];
        $totals = ['sales' => 0.0, 'credit_unpaid' => 0.0, 'credit_paid' => 0.0, 'expenses' => 0.0];
        foreach ($branches as $branch) {
            $sales = Sale::query()->completed()->where('branch_id', $branch->id)->whereBetween('sold_at', [$from, $to]);
            $row = [
                'name' => $branch->location ?: $branch->name,
                'sales' => (float) (clone $sales)->sum(DB::raw('total - returned_amount')),
                'credit_unpaid' => (float) (clone $sales)->whereNotNull('customer_id')->sum('balance'),
                'credit_paid' => (float) Payment::query()->where('branch_id', $branch->id)->where('type', 'debt')->whereBetween('paid_at', [$from, $to])->sum('amount'),
                'expenses' => (float) Expense::query()->where('branch_id', $branch->id)->whereBetween('expense_date', [$from, $to])->sum('amount'),
                'sales_rows' => (clone $sales)->with('cashier')->orderBy('sold_at')->get(),
                'expense_rows' => Expense::query()->where('branch_id', $branch->id)->whereBetween('expense_date', [$from, $to])->with('creator')->orderBy('expense_date')->get(),
            ];
            foreach (array_keys($totals) as $k) {
                $totals[$k] += $row[$k];
            }
            $rows[] = $row;
        }

        $build = fn () => $this->message($settings, $period, $from, $to, $rows, $totals);

        if ($this->option('dry-run')) {
            $message = $messenger->inMessageLocale($build);
            $this->line('SMS:');
            $this->line($message->sms);
            $this->line('');
            $this->line('WhatsApp:');
            $this->line($message->text);

            return self::SUCCESS;
        }

        $range = $from->isSameDay($to) ? $from->format('d/m/Y') : $from->format('d/m/Y').' – '.$to->format('d/m/Y');
        $currency = (string) $settings->get('currency');
        $notifier->notify(new StaffNotice(
            type: 'sales_report',
            title: 'Sales report :range',
            body: 'Sales :currency :sales · expenses :currency :expenses · unpaid credit :currency :credit.',
            params: ['range' => $range, 'currency' => $currency, 'sales' => number_format($totals['sales']), 'expenses' => number_format($totals['expenses']), 'credit' => number_format($totals['credit_unpaid'])],
            level: 'info',
            url: '/admin/reports',
            dedupeKey: $this->option('force') ? null : "sales_report:{$period}:".$from->format('Ymd'),
            dedupeHours: 6,
            message: $build,
        ));

        $channels = Channels::forType('sales_report');
        $this->info($channels ? 'Report sent on: '.implode(', ', $channels).'.' : 'Report saved to notifications only (no channel chosen in Settings → Messages).');
        if ($channels && Channels::staffRecipients()->isEmpty()) {
            $this->warn('No recipients set in Settings → Messages.');
        }

        return self::SUCCESS;
    }

    private function message(SettingsService $settings, string $period, Carbon $from, Carbon $to, array $rows, array $totals): OutgoingMessage
    {
        $business = (string) $settings->get('business_name');
        $currency = (string) $settings->get('currency');
        $fmt = fn ($n) => number_format((float) $n);
        $label = match ($period) {
            'weekly' => __('week :from - :to', ['from' => $from->format('d/m'), 'to' => $to->format('d/m/Y')]),
            'monthly' => __('month :month', ['month' => $from->translatedFormat('F Y')]),
            'yearly' => __('year :year', ['year' => $from->format('Y')]),
            default => $from->format('d/m/Y'),
        };

        // SMS: one short line with the totals of all branches.
        $sms = __(':business report :label: sales :sales, expenses :expenses, unpaid credit :credit, debts collected :collected (:currency).', [
            'business' => $business, 'label' => $label, 'sales' => $fmt($totals['sales']), 'expenses' => $fmt($totals['expenses']),
            'credit' => $fmt($totals['credit_unpaid']), 'collected' => $fmt($totals['credit_paid']), 'currency' => $currency,
        ]);

        $title = __('Sales report (:label)', ['label' => $label]);
        $doc = [
            'title' => $title,
            'subtitle' => $from->format('d/m/Y').' – '.$to->format('d/m/Y'),
            'meta' => [
                __('Sales') => "{$currency} ".$fmt($totals['sales']),
                __('Expenses') => "{$currency} ".$fmt($totals['expenses']),
                __('Credit not yet paid') => "{$currency} ".$fmt($totals['credit_unpaid']),
                __('Debts collected') => "{$currency} ".$fmt($totals['credit_paid']),
            ],
            'sections' => [[
                'heading' => __('By branch'),
                'columns' => [__('Branch'), __('Sales'), __('Credit (unpaid)'), __('Debts collected'), __('Expenses')],
                'numeric' => [1, 2, 3, 4],
                'rows' => array_map(fn ($r) => [$r['name'], $fmt($r['sales']), $fmt($r['credit_unpaid']), $fmt($r['credit_paid']), $fmt($r['expenses'])], $rows),
                'total' => [__('All branches'), $fmt($totals['sales']), $fmt($totals['credit_unpaid']), $fmt($totals['credit_paid']), $fmt($totals['expenses'])],
            ]],
            'closing' => [__('Every sale and expense is listed in the attached PDF.')],
        ];

        return new OutgoingMessage(
            'report', $sms, $this->buildText($business, $label, $rows, $totals, $currency), "{$title} · {$business}", $doc, "{$period}-report-".$from->format('Ymd').'.pdf',
            pdfView: 'pdf.period-report',
            pdfData: ['business' => $business, 'title' => $title, 'period' => $from->format('d/m/Y').' - '.$to->format('d/m/Y'), 'branches' => $rows, 'totals' => $totals],
        );
    }

    /** WhatsApp: the breakdown per branch. */
    private function buildText(string $business, string $label, array $rows, array $totals, string $currency): string
    {
        $fmt = fn ($n) => number_format($n);
        $lines = ['*'.__(':business sales report', ['business' => $business]).'*', $label];
        foreach ($rows as $r) {
            $lines[] = '';
            $lines[] = '*'.$r['name'].'*';
            $lines[] = __('Sales').": {$currency} ".$fmt($r['sales']);
            if ($r['credit_unpaid'] > 0) {
                $lines[] = __('Credit (unpaid)').": {$currency} ".$fmt($r['credit_unpaid']);
            }
            if ($r['credit_paid'] > 0) {
                $lines[] = __('Debts collected').": {$currency} ".$fmt($r['credit_paid']);
            }
            $lines[] = __('Expenses').": {$currency} ".$fmt($r['expenses']);
        }
        if (count($rows) > 1) {
            $lines[] = '';
            $lines[] = '*'.__('All branches').'*';
            $lines[] = __('Sales').": {$currency} ".$fmt($totals['sales']);
            $lines[] = __('Credit (unpaid)').": {$currency} ".$fmt($totals['credit_unpaid']);
            $lines[] = __('Debts collected').": {$currency} ".$fmt($totals['credit_paid']);
            $lines[] = __('Expenses').": {$currency} ".$fmt($totals['expenses']);
        }

        return implode("\n", $lines);
    }
}

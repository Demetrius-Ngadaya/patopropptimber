<?php

namespace App\Console\Commands;

use App\Models\Core\AppNotification;
use App\Models\Core\Branch;
use App\Models\Inventory\ProductStock;
use App\Models\Inventory\StockBatch;
use App\Models\Sales\Sale;
use App\Services\Core\SettingsService;
use App\Services\Messaging\OutgoingMessage;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\StaffNotice;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Morning summaries: stock (low, out, expiring, expired) and overdue debts.
 * One bell entry per branch (so each branch sees its own), one combined
 * SMS / WhatsApp / email for the recipient lists.
 */
class SendDailyAlerts extends Command
{
    protected $signature = 'mauzo:daily-alerts {--only= : stock or debts}';

    protected $description = 'Morning stock and overdue-debt summaries (bell, SMS, WhatsApp, email)';

    private const MAX_ROWS = 300;

    public function handle(Notifier $notifier, SettingsService $settings): int
    {
        $branches = Branch::query()->where('is_active', true)->orderBy('name')->get()->keyBy('id');
        $only = $this->option('only');

        if (! $only || $only === 'stock') {
            $this->stock($notifier, $settings, $branches);
        }
        if (! $only || $only === 'debts') {
            $this->debts($notifier, $settings, $branches);
        }

        $pruned = AppNotification::query()->where('created_at', '<', now()->subDays(AppNotification::KEEP_DAYS))->delete();
        $this->info("Done. Old notifications removed: {$pruned}.");

        return self::SUCCESS;
    }

    private function stock(Notifier $notifier, SettingsService $settings, Collection $branches): void
    {
        $today = now()->startOfDay();
        $defaultDays = $settings->int('expiry_alert_days');

        $levels = ProductStock::query()
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->whereNull('products.deleted_at')->where('products.is_active', true)->where('products.is_service', false)
            ->whereIn('product_stocks.branch_id', $branches->keys())
            ->where(fn ($q) => $q->where('product_stocks.quantity', '<=', 0)
                ->orWhere(fn ($w) => $w->where('products.reorder_level', '>', 0)->whereColumn('product_stocks.quantity', '<=', 'products.reorder_level')))
            ->orderBy('products.name')
            ->get(['product_stocks.branch_id', 'product_stocks.quantity', 'products.name', 'products.sku', 'products.reorder_level']);

        $batches = StockBatch::query()
            ->join('products', 'products.id', '=', 'stock_batches.product_id')
            ->whereNull('products.deleted_at')->where('products.is_active', true)
            ->whereIn('stock_batches.branch_id', $branches->keys())
            ->where('stock_batches.quantity_remaining', '>', 0)->whereNotNull('stock_batches.expiry_date')
            ->where('stock_batches.expiry_date', '<=', $today->copy()->addDays(max(1, $defaultDays))->toDateString())
            ->orderBy('stock_batches.expiry_date')
            ->get(['stock_batches.branch_id', 'stock_batches.expiry_date', 'stock_batches.quantity_remaining', 'stock_batches.batch_no', 'products.name', 'products.expiry_alert_days'])
            // A product's own warning period wins over the general one.
            ->filter(fn ($b) => $b->expiry_date->lte($today->copy()->addDays((int) ($b->expiry_alert_days ?: $defaultDays))));

        $summary = [];
        foreach ($branches as $id => $branch) {
            $mine = $levels->where('branch_id', $id);
            $out = $mine->filter(fn ($r) => (float) $r->quantity <= 0)->count();
            $low = $mine->count() - $out;
            $exp = $batches->where('branch_id', $id);
            $expired = $exp->filter(fn ($b) => $b->expiry_date->lt($today))->count();
            $expiring = $exp->count() - $expired;
            if ($low + $out + $expiring + $expired === 0) {
                continue;
            }
            $summary[$id] = compact('low', 'out', 'expiring', 'expired');
            $notifier->notify(new StaffNotice(
                type: 'stock_digest',
                title: 'Stock check for :branch',
                body: ':low low, :out out of stock, :expiring expiring soon, :expired expired.',
                params: ['branch' => $branch->name] + $summary[$id],
                level: $out || $expired ? 'warning' : 'info',
                url: '/admin/stock',
                branchId: $id,
                dedupeKey: "stock_digest:{$id}:".now()->format('Ymd'),
            ));
        }

        if (! $summary) {
            $this->info('Stock: nothing to report.');

            return;
        }

        $notifier->notify(new StaffNotice(
            type: 'stock_digest',
            title: 'Stock check',
            bell: false,
            message: function () use ($settings, $branches, $levels, $batches, $summary, $today) {
                $b = $settings->get('business_name');
                $t = fn ($k) => array_sum(array_column($summary, $k));
                $sms = __(':business stock: :low low, :out out of stock, :expiring expiring, :expired expired. Details by email.', ['business' => $b, 'low' => $t('low'), 'out' => $t('out'), 'expiring' => $t('expiring'), 'expired' => $t('expired')]);
                $lines = ['*'.__(':business stock check', ['business' => $b]).'*', now()->format('d/m/Y'), ''];
                foreach ($summary as $id => $s) {
                    $lines[] = '*'.$branches[$id]->name.'*: '.__(':low low, :out out of stock, :expiring expiring soon, :expired expired.', $s);
                }
                $branchName = fn ($id) => $branches[$id]->name ?? '-';

                $doc = [
                    'title' => __('Stock check'),
                    'subtitle' => now()->format('d/m/Y'),
                    'meta' => [__('Low') => (string) $t('low'), __('Out of stock') => (string) $t('out'), __('Expiring soon') => (string) $t('expiring'), __('Expired') => (string) $t('expired')],
                    'sections' => [
                        [
                            'heading' => __('Out of stock'), 'columns' => [__('Product'), __('SKU'), __('Branch')],
                            'rows' => $levels->filter(fn ($r) => (float) $r->quantity <= 0)->take(self::MAX_ROWS)->map(fn ($r) => [$r->name, $r->sku, $branchName($r->branch_id)])->values()->all(),
                            'empty' => __('Nothing is out of stock.'),
                        ],
                        [
                            'heading' => __('Low stock'), 'columns' => [__('Product'), __('SKU'), __('Branch'), __('Left'), __('Reorder level')], 'numeric' => [3, 4],
                            'rows' => $levels->filter(fn ($r) => (float) $r->quantity > 0)->take(self::MAX_ROWS)->map(fn ($r) => [$r->name, $r->sku, $branchName($r->branch_id), 0 + (float) $r->quantity, 0 + (float) $r->reorder_level])->values()->all(),
                            'empty' => __('Nothing is low.'),
                        ],
                        [
                            'heading' => __('Expired and expiring'), 'columns' => [__('Product'), __('Batch'), __('Branch'), __('Expiry date'), __('Quantity'), ''], 'numeric' => [4],
                            'rows' => $batches->take(self::MAX_ROWS)->map(fn ($x) => [$x->name, $x->batch_no ?? '-', $branchName($x->branch_id), $x->expiry_date->format('d/m/Y'), 0 + (float) $x->quantity_remaining, $x->expiry_date->lt($today) ? __('Expired') : __(':days days left', ['days' => (int) $today->diffInDays($x->expiry_date)])])->values()->all(),
                            'empty' => __('Nothing expires soon.'),
                        ],
                    ],
                ];

                return new OutgoingMessage('stock_digest', $sms, implode("\n", $lines), __(':business stock check :date', ['business' => $b, 'date' => now()->format('d/m/Y')]), $doc, 'stock-check-'.now()->format('Ymd').'.pdf');
            },
        ));
        $this->info('Stock: '.count($summary).' branch(es) with alerts.');
    }

    private function debts(Notifier $notifier, SettingsService $settings, Collection $branches): void
    {
        $sales = Sale::query()->completed()->where('balance', '>', 0)->whereNotNull('customer_id')
            ->whereDate('due_date', '<', now()->toDateString())
            ->with('customer:id,name,phone')->orderBy('due_date')->get(['id', 'number', 'branch_id', 'customer_id', 'due_date', 'balance']);
        if ($sales->isEmpty()) {
            $this->info('Debts: nothing overdue.');

            return;
        }
        $currency = (string) $settings->get('currency');

        foreach ($sales->groupBy('branch_id') as $branchId => $rows) {
            $notifier->notify(new StaffNotice(
                type: 'debt_overdue',
                title: ':count overdue debts in :branch',
                body: ':customers customers owe :currency :amount past the due date.',
                params: ['count' => $rows->count(), 'branch' => $branches[$branchId]->name ?? '-', 'customers' => $rows->pluck('customer_id')->unique()->count(), 'currency' => $currency, 'amount' => number_format((float) $rows->sum('balance'))],
                level: 'warning',
                url: '/admin/credit-sales?status=overdue',
                branchId: (int) $branchId,
                dedupeKey: "debt_overdue:{$branchId}:".now()->format('Ymd'),
            ));
        }

        $notifier->notify(new StaffNotice(
            type: 'debt_overdue',
            title: 'Overdue debts',
            bell: false,
            message: function () use ($settings, $sales, $currency) {
                $b = $settings->get('business_name');
                $byCustomer = $sales->groupBy('customer_id')->map(fn ($rows) => [
                    'name' => $rows->first()->customer?->name ?? '-',
                    'phone' => $rows->first()->customer?->phone ?? '',
                    'count' => $rows->count(),
                    'amount' => (float) $rows->sum('balance'),
                    'oldest' => $rows->min('due_date'),
                ])->sortByDesc('amount')->values();
                $total = number_format((float) $sales->sum('balance'));
                $sms = __(':business: :customers customers owe :currency :amount past the due date. Details by email.', ['business' => $b, 'customers' => $byCustomer->count(), 'currency' => $currency, 'amount' => $total]);
                $lines = ['*'.__('Overdue debts').'*', $sms, ''];
                foreach ($byCustomer->take(15) as $c) {
                    $lines[] = "• {$c['name']}: {$currency} ".number_format($c['amount']);
                }
                $doc = [
                    'title' => __('Overdue debts'),
                    'subtitle' => now()->format('d/m/Y'),
                    'meta' => [__('Customers') => (string) $byCustomer->count(), __('Invoices') => (string) $sales->count(), __('Total overdue') => "{$currency} {$total}"],
                    'sections' => [[
                        'heading' => __('By customer'),
                        'columns' => [__('Customer'), __('Phone'), __('Invoices'), __('Oldest due date'), __('Days late'), __('Amount')],
                        'numeric' => [2, 4, 5],
                        'rows' => $byCustomer->take(self::MAX_ROWS)->map(fn ($c) => [$c['name'], $c['phone'], $c['count'], $c['oldest']?->format('d/m/Y'), (int) $c['oldest']?->diffInDays(now()), number_format($c['amount'])])->all(),
                        'total' => [__('Total'), '', $sales->count(), '', '', $total],
                    ]],
                    'closing' => [__('Customers are reminded automatically if debt reminders are on in Settings → Messages.')],
                ];

                return new OutgoingMessage('debt_overdue', $sms, implode("\n", $lines), __(':business overdue debts :date', ['business' => $b, 'date' => now()->format('d/m/Y')]), $doc, 'overdue-debts-'.now()->format('Ymd').'.pdf');
            },
        ));
        $this->info('Debts: '.$sales->count().' overdue invoice(s).');
    }
}

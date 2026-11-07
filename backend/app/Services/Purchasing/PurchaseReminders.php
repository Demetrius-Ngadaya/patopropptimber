<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\Purchase;
use App\Services\Core\SettingsService;
use App\Services\Messaging\Messenger;
use App\Services\Messaging\OutgoingMessage;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\StaffNotice;
use App\Support\Channels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reminders to pay suppliers for goods bought on credit. Each purchase owed
 * has a day to remind on (by default a few days before it is due) and the ways
 * to remind (SMS short, WhatsApp, email with the full details and a PDF).
 * A reminder goes on that day and again on the due date if still unpaid.
 */
class PurchaseReminders
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Messenger $messenger,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Set the reminder of a purchase that is not fully paid.
     *
     * @param  array{remind_on?: ?string, reminder_channels?: ?array, days_before?: ?int}  $data
     */
    public function apply(Purchase $purchase, array $data): void
    {
        if ((float) $purchase->balance <= 0.004 || ! $purchase->due_date) {
            $purchase->forceFill(['remind_on' => null, 'reminder_channels' => null])->save();

            return;
        }
        $days = $data['days_before'] ?? $this->settings->int('purchase_reminder_days_before');
        $remindOn = ! empty($data['remind_on'])
            ? Carbon::parse($data['remind_on'])
            : $purchase->due_date->copy()->subDays(max(0, (int) $days));
        if ($remindOn->lt($purchase->purchase_date)) {
            $remindOn = Carbon::parse($purchase->purchase_date);
        }
        $channels = $data['reminder_channels'] ?? null;
        $purchase->forceFill([
            'remind_on' => $remindOn->toDateString(),
            'reminder_channels' => Channels::filter(is_array($channels) ? $channels : (array) $this->settings->get('purchase_reminder_channels', ['sms', 'email'])),
            'reminded_on' => null,
        ])->save();
    }

    /** Purchases whose reminder day or due date has come and that were not reminded since. */
    public function due(): Collection
    {
        $today = today()->toDateString();

        return Purchase::query()->with(['supplier', 'branch:id,name', 'items.product:id,name', 'payments'])
            ->where('balance', '>', 0)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereNotNull('remind_on')->where('remind_on', '<=', $today)->where(fn ($r) => $r->whereNull('reminded_on')->orWhereColumn('reminded_on', '<', 'remind_on')))
                ->orWhere(fn ($w) => $w->whereNotNull('remind_on')->where('due_date', '<=', $today)->where(fn ($r) => $r->whereNull('reminded_on')->orWhereColumn('reminded_on', '<', 'due_date'))))
            ->orderBy('due_date')->get();
    }

    public function send(Purchase $purchase): array
    {
        $daysLeft = (int) today()->diffInDays($purchase->due_date, false);
        $params = [
            'number' => $purchase->number,
            'supplier' => $purchase->supplier?->name ?? '-',
            'amount' => number_format((float) $purchase->balance, 2),
            'date' => $purchase->due_date->format('d/m/Y'),
        ];
        $this->notifier->notify(new StaffNotice(
            type: 'purchase_due',
            title: $daysLeft < 0 ? 'Supplier payment overdue: :supplier' : 'Supplier payment due: :supplier',
            body: 'Pay :amount for purchase :number by :date.',
            params: $params,
            level: $daysLeft <= 0 ? 'warning' : 'info',
            url: '/admin/purchases/'.$purchase->id,
            branchId: $purchase->branch_id,
            dedupeKey: 'purchase_due:'.$purchase->id.':'.today()->format('Ymd'),
        ));

        $result = [];
        $channels = Channels::filter((array) $purchase->reminder_channels);
        if ($channels) {
            $message = $this->messenger->inMessageLocale(fn () => $this->message($purchase, $daysLeft));
            $result = $this->messenger->send($message, $channels, Channels::staffRecipients());
        }
        $purchase->forceFill(['reminded_on' => today()->toDateString()])->save();

        return $result;
    }

    private function message(Purchase $purchase, int $daysLeft): OutgoingMessage
    {
        $business = (string) $this->settings->get('business_name');
        $currency = (string) $this->settings->get('currency');
        $supplier = $purchase->supplier;
        $when = $daysLeft < 0 ? __(':days days late', ['days' => -$daysLeft]) : ($daysLeft === 0 ? __('due today') : __('due in :days days', ['days' => $daysLeft]));
        $amount = number_format((float) $purchase->balance, 2);

        $sms = __(':business: pay :supplier :currency :amount (purchase :number), :when, by :date.', [
            'business' => $business, 'supplier' => $supplier?->name, 'currency' => $currency, 'amount' => $amount,
            'number' => $purchase->number, 'when' => $when, 'date' => $purchase->due_date->format('d/m/Y'),
        ]);
        $text = implode("\n", array_filter([
            '*'.__(':business - supplier payment reminder', ['business' => $business]).'*',
            '',
            __('Supplier').': '.$supplier?->name.($supplier?->phone ? " ({$supplier->phone})" : ''),
            __('Purchase').': '.$purchase->number.($purchase->supplier_invoice_no ? ' / '.$purchase->supplier_invoice_no : ''),
            __('Bought on').': '.Carbon::parse($purchase->purchase_date)->format('d/m/Y'),
            __('Total').': '.$currency.' '.number_format((float) $purchase->supplier_total, 2),
            __('Paid').': '.$currency.' '.number_format((float) $purchase->amount_paid, 2),
            '*'.__('Still to pay').': '.$currency.' '.$amount.'*',
            __('Pay by').': '.$purchase->due_date->format('d/m/Y').' ('.$when.')',
        ]));

        $doc = [
            'title' => __('Supplier payment reminder'),
            'subtitle' => $supplier?->name.' · '.$purchase->number,
            'intro' => [__('This purchase was bought on credit and is :when. Please arrange payment by :date.', ['when' => $when, 'date' => $purchase->due_date->format('d/m/Y')])],
            'meta' => [
                __('Supplier') => (string) $supplier?->name,
                __('Supplier phone') => (string) ($supplier?->phone ?? '-'),
                __('Supplier email') => (string) ($supplier?->email ?? '-'),
                __('Purchase') => $purchase->number,
                __('Supplier invoice') => (string) ($purchase->supplier_invoice_no ?: '-'),
                __('Branch') => (string) $purchase->branch?->name,
                __('Bought on') => Carbon::parse($purchase->purchase_date)->format('d/m/Y'),
                __('Pay by') => $purchase->due_date->format('d/m/Y'),
                __('Total') => number_format((float) $purchase->supplier_total, 2),
                __('Paid') => number_format((float) $purchase->amount_paid, 2),
                __('Still to pay') => $amount,
                __('Supplier owed in total') => number_format((float) ($supplier?->balance ?? 0), 2),
            ],
            'sections' => [
                [
                    'heading' => __('Goods'), 'columns' => [__('Product'), __('Quantity'), __('Unit cost'), __('Amount')], 'numeric' => [1, 2, 3],
                    'rows' => $purchase->items->map(fn ($i) => [$i->product?->name, 0 + (float) $i->quantity, number_format((float) $i->unit_cost, 2), number_format((float) $i->line_total, 2)])->all(),
                ],
                [
                    'heading' => __('Payments made'), 'columns' => [__('Date'), __('Method'), __('Reference'), __('Amount')], 'numeric' => [3],
                    'rows' => $purchase->payments->map(fn ($p) => [Carbon::parse($p->paid_on)->format('d/m/Y'), __(ucfirst(str_replace('_', ' ', (string) $p->method))), $p->reference ?? '-', number_format((float) $p->amount, 2)])->all(),
                    'empty' => __('Nothing paid yet.'),
                ],
            ],
        ];

        return (new OutgoingMessage('purchase_due', $sms, $text, __(':business: pay :supplier :amount by :date', ['business' => $business, 'supplier' => $supplier?->name, 'amount' => $currency.' '.$amount, 'date' => $purchase->due_date->format('d/m/Y')]), $doc, 'payment-reminder-'.$purchase->number.'.pdf'))->withRelated($purchase);
    }
}

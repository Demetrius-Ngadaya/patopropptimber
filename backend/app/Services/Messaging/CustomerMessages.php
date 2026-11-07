<?php

namespace App\Services\Messaging;

use App\Models\Crm\Customer;
use App\Models\Sales\Order;
use App\Models\Sales\Sale;
use App\Services\Core\SettingsService;
use App\Support\Channels;
use App\Support\Recipients;

/**
 * Messages to a customer: receipts, debt reminders and order updates.
 * Each is written short for SMS, fuller for WhatsApp, and in full detail
 * (with a PDF) for email.
 */
class CustomerMessages
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array<int, string>|null  $channels  null = the channels set for receipts in Settings
     * @return array<string, array{recipients: int, sent: bool}>
     */
    public function receipt(Sale $sale, ?array $channels = null, ?string $phone = null, ?string $email = null): array
    {
        $sale->loadMissing(['items', 'payments', 'customer', 'cashier', 'branch']);
        $to = Recipients::person($phone ?? $sale->customer_phone ?? $sale->customer?->phone, $email ?? $sale->customer?->email);
        $message = $this->messenger->inMessageLocale(fn () => $this->receiptMessage($sale));

        return $this->messenger->send($message, $channels ?? Channels::forType('customer_receipt'), $to);
    }

    /** @return array<string, array{recipients: int, sent: bool}> */
    public function debtReminder(Customer $customer, ?array $channels = null): array
    {
        $message = $this->messenger->inMessageLocale(fn () => $this->debtMessage($customer));

        return $this->messenger->send($message, $channels ?? Channels::forType('customer_debt_reminder'), Recipients::person($customer->phone, $customer->email));
    }

    /** @return array<string, array{recipients: int, sent: bool}> */
    public function orderUpdate(Order $order, ?array $channels = null): array
    {
        $message = $this->messenger->inMessageLocale(fn () => $this->orderMessage($order));
        if (! $message) {
            return [];
        }
        $order->loadMissing('customer');

        return $this->messenger->send($message, $channels ?? Channels::forType('customer_order_status'), Recipients::person($order->contact_phone, $order->customer?->email));
    }

    /**
     * A message typed by staff, sent to one customer. Debtors also get their
     * unpaid invoices in the email / PDF version.
     */
    public function custom(Customer $customer, string $subject, string $body, array $channels): array
    {
        $message = $this->messenger->inMessageLocale(function () use ($customer, $subject, $body) {
            $doc = [
                'title' => $subject,
                'subtitle' => __('To :name', ['name' => $customer->name]),
                'intro' => preg_split('/\n{2,}/', trim($body)),
                'sections' => (float) $customer->balance > 0 ? [$this->unpaidSection($customer)] : [],
            ];

            return new OutgoingMessage('manual', $body, $body, $subject, $doc, 'message.pdf');
        });

        return $this->messenger->send($message->withRelated($customer), $channels, Recipients::person($customer->phone, $customer->email));
    }

    // ------------------------------------------------------------------ builders

    private function receiptMessage(Sale $sale): OutgoingMessage
    {
        $b = $this->settings->get('business_name');
        $cur = $this->settings->get('currency');
        $m = fn ($n) => number_format((float) $n);
        $owes = (float) $sale->balance > 0;

        $sms = __(':business: Thank you. Receipt :number, total :currency :total, paid :paid.', ['business' => $b, 'number' => $sale->number, 'currency' => $cur, 'total' => $m($sale->total), 'paid' => $m($sale->amount_paid)])
            .($owes ? ' '.__('Balance :balance due :date.', ['balance' => $m($sale->balance), 'date' => $sale->due_date?->format('d/m/Y') ?? '-']) : '');

        $lines = ["*{$b}*", __('Receipt :number', ['number' => $sale->number]).' · '.$sale->sold_at?->format('d/m/Y H:i'), ''];
        foreach ($sale->items as $item) {
            $lines[] = (0 + (float) $item->quantity).' × '.$item->product_name.' — '.$m($item->line_total);
        }
        $lines[] = '';
        $lines[] = __('Total').": {$cur} ".$m($sale->total);
        $lines[] = __('Paid').": {$cur} ".$m($sale->amount_paid);
        if ($owes) {
            $lines[] = __('Balance').": {$cur} ".$m($sale->balance).' ('.__('due :date', ['date' => $sale->due_date?->format('d/m/Y') ?? '-']).')';
        }
        $lines[] = '';
        $lines[] = (string) ($this->settings->get('receipt_footer') ?: __('Thank you for your business!'));
        $lines[] = '_'.config('mauzo.brand.powered_by').'_';

        $items = $sale->items->map(fn ($i) => [
            $i->product_name.($i->unit_name ? " ({$i->unit_name})" : ''),
            0 + (float) $i->quantity,
            $m($i->unit_price),
            $m($i->line_total),
        ])->all();

        $doc = [
            'title' => __('Receipt :number', ['number' => $sale->number]),
            'subtitle' => $sale->sold_at?->format('d/m/Y H:i').' · '.$sale->branch?->name,
            'meta' => array_filter([
                __('Customer') => $sale->customer?->name ?? $sale->customer_name ?? __('Walk-in customer'),
                __('Served by') => $sale->cashier?->name,
                __('Payment status') => __(ucfirst((string) $sale->payment_status)),
                __('Due date') => $owes ? $sale->due_date?->format('d/m/Y') : null,
            ]),
            'sections' => [
                [
                    'heading' => __('Items'),
                    'columns' => [__('Product'), __('Quantity'), __('Price'), __('Total')],
                    'numeric' => [1, 2, 3],
                    'rows' => array_merge($items, array_values(array_filter([
                        (float) $sale->discount + (float) $sale->item_discount > 0 ? ['', '', __('Discount'), '-'.$m((float) $sale->discount + (float) $sale->item_discount)] : null,
                        (float) $sale->tax > 0 ? ['', '', __('Tax'), $m($sale->tax)] : null,
                        (float) $sale->delivery_fee > 0 ? ['', '', __('Delivery'), $m($sale->delivery_fee)] : null,
                    ]))),
                    'total' => [__('Total')." ({$cur})", '', '', $m($sale->total)],
                ],
                [
                    'heading' => __('Payments'),
                    'columns' => [__('Method'), __('Reference'), __('Amount')],
                    'numeric' => [2],
                    'rows' => $sale->payments->map(fn ($p) => [__(ucfirst(str_replace('_', ' ', (string) $p->method))), $p->reference ?? '', $m($p->amount)])->all(),
                    'total' => [__('Paid'), '', $m($sale->amount_paid)],
                    'empty' => __('No payment yet.'),
                ],
            ],
            'closing' => array_values(array_filter([
                $owes ? __('Balance to pay: :currency :balance by :date.', ['currency' => $cur, 'balance' => $m($sale->balance), 'date' => $sale->due_date?->format('d/m/Y') ?? '-']) : null,
                (string) $this->settings->get('receipt_footer'),
            ])),
        ];

        return (new OutgoingMessage('receipt', $sms, implode("\n", $lines), __('Receipt :number from :business', ['number' => $sale->number, 'business' => $b]), $doc, "receipt-{$sale->number}.pdf"))->withRelated($sale);
    }

    private function debtMessage(Customer $customer): OutgoingMessage
    {
        $b = $this->settings->get('business_name');
        $cur = $this->settings->get('currency');
        $phone = $this->settings->get('business_phone');
        $balance = number_format((float) $customer->balance);

        $sms = __('Hello :name, a reminder from :business: your balance is :currency :amount. Please pay soon.', ['name' => $customer->name, 'business' => $b, 'currency' => $cur, 'amount' => $balance])
            .($phone ? ' '.__('Tel: :phone', ['phone' => $phone]) : '');

        $section = $this->unpaidSection($customer);
        $lines = [__('Hello :name,', ['name' => $customer->name]), '', __('This is a reminder from :business. Your balance is *:currency :amount*.', ['business' => $b, 'currency' => $cur, 'amount' => $balance]), ''];
        foreach (array_slice($section['rows'], 0, 10) as $r) {
            $lines[] = "• {$r[0]} ({$r[1]}): {$cur} {$r[5]}";
        }
        $lines[] = '';
        $lines[] = __('Please pay soon.').($phone ? ' '.__('Tel: :phone', ['phone' => $phone]) : '');

        $doc = [
            'title' => __('Statement of account'),
            'subtitle' => $customer->name.($customer->code ? " · {$customer->code}" : ''),
            'intro' => [__('Dear :name, this is a friendly reminder that the following invoices are not yet fully paid.', ['name' => $customer->name])],
            'meta' => [__('Total balance') => "{$cur} {$balance}", __('Date') => now()->format('d/m/Y')],
            'sections' => [$section],
            'closing' => [__('Please pay at any of our branches.').($phone ? ' '.__('For questions call :phone.', ['phone' => $phone]) : '')],
        ];

        return (new OutgoingMessage('debt_reminder', $sms, implode("\n", $lines), __('Payment reminder from :business', ['business' => $b]), $doc, 'statement-'.($customer->code ?: $customer->id).'.pdf'))->withRelated($customer);
    }

    private function orderMessage(Order $order): ?OutgoingMessage
    {
        $labels = [
            'confirmed' => __('confirmed'),
            'processing' => __('being prepared'),
            'ready' => __('ready for collection'),
            'out_for_delivery' => __('on the way to you'),
            'completed' => __('completed'),
            'cancelled' => __('cancelled'),
        ];
        if (! isset($labels[$order->status])) {
            return null;
        }
        $order->loadMissing(['items', 'branch', 'customer']);
        $b = $this->settings->get('business_name');
        $cur = $this->settings->get('currency');
        $m = fn ($n) => number_format((float) $n);
        $sms = __(':business: Your order :number is :status. Thank you!', ['business' => $b, 'number' => $order->number, 'status' => $labels[$order->status]]);

        $lines = ["*{$b}*", __('Your order :number is :status.', ['number' => $order->number, 'status' => $labels[$order->status]]), ''];
        foreach ($order->items as $item) {
            $lines[] = (0 + (float) $item->quantity).' × '.$item->product_name;
        }
        $lines[] = '';
        $lines[] = __('Total').": {$cur} ".$m($order->total);
        if ($order->status === 'cancelled' && $order->cancel_reason) {
            $lines[] = __('Reason: :reason', ['reason' => $order->cancel_reason]);
        }

        $doc = [
            'title' => __('Order :number', ['number' => $order->number]),
            'subtitle' => __('Status: :status', ['status' => $labels[$order->status]]),
            'meta' => array_filter([
                __('Customer') => $order->customer?->name,
                __('Branch') => $order->branch?->name,
                __('Delivery') => $order->delivery_method === 'delivery' ? __('Delivery to :address', ['address' => $order->delivery_address ?? '-']) : __('Pickup at the branch'),
                __('Payment') => __(ucfirst(str_replace('_', ' ', (string) $order->payment_method))),
                __('Reason') => $order->status === 'cancelled' ? $order->cancel_reason : null,
            ]),
            'sections' => [[
                'heading' => __('Items'),
                'columns' => [__('Product'), __('Quantity'), __('Price'), __('Total')],
                'numeric' => [1, 2, 3],
                'rows' => $order->items->map(fn ($i) => [$i->product_name, 0 + (float) $i->quantity, $m($i->unit_price), $m($i->line_total)])->all(),
                'total' => [__('Total')." ({$cur})", '', '', $m($order->total)],
            ]],
            'closing' => [__('Thank you for shopping with :business.', ['business' => $b])],
        ];

        return (new OutgoingMessage('order_status', $sms, implode("\n", $lines), __('Order :number: :status', ['number' => $order->number, 'status' => $labels[$order->status]]), $doc, "order-{$order->number}.pdf"))->withRelated($order);
    }

    /** Unpaid credit sales of a customer as a table. */
    private function unpaidSection(Customer $customer): array
    {
        $m = fn ($n) => number_format((float) $n);
        $sales = $customer->sales()->completed()->where('balance', '>', 0)->orderBy('sold_at')->limit(100)->get();
        $rows = $sales->map(function ($s) use ($m) {
            $late = $s->due_date && $s->due_date->isPast() ? (int) $s->due_date->diffInDays(now()) : 0;

            return [$s->number, $s->sold_at?->format('d/m/Y'), $s->due_date?->format('d/m/Y') ?? '-', $m($s->total), $m($s->amount_paid), $m($s->balance), $late ? __(':days days late', ['days' => $late]) : ''];
        })->all();

        return [
            'heading' => __('Unpaid invoices'),
            'columns' => [__('Invoice'), __('Date'), __('Due'), __('Total'), __('Paid'), __('Balance'), ''],
            'numeric' => [3, 4, 5],
            'rows' => $rows,
            'total' => [__('Total'), '', '', '', '', $m($sales->sum('balance')), ''],
            'empty' => __('No unpaid invoices.'),
        ];
    }
}

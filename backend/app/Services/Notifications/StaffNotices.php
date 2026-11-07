<?php

namespace App\Services\Notifications;

use App\Models\Hr\LeaveRequest;
use App\Models\Hr\PayrollRun;
use App\Models\Sales\Order;
use App\Models\Sales\Sale;
use App\Services\Core\SettingsService;
use App\Services\Messaging\OutgoingMessage;

/** The staff notifications raised by everyday events (orders, cancelled sales, leave, payroll). */
class StaffNotices
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly SettingsService $settings,
    ) {}

    /** A cancelled order that the customer already paid online. */
    public function refundNeeded(Order $order): void
    {
        $this->notifier->notify(new StaffNotice(
            type: 'online_payment',
            title: 'Cancelled order :number was paid online: refund the customer',
            body: ':amount was paid by phone from :phone.',
            params: ['number' => $order->number, 'amount' => number_format((float) $order->paid_online), 'phone' => $order->contact_phone ?? ''],
            level: 'warning',
            url: "/admin/orders/{$order->id}",
            branchId: $order->branch_id,
            dedupeKey: "order_refund:{$order->id}",
        ));
    }

    public function orderPlaced(Order $order): void
    {
        if ($order->source !== 'online') {
            return;
        }
        $order->loadMissing(['customer', 'branch', 'items']);
        $params = ['number' => $order->number, 'customer' => $order->customer?->name ?? '', 'total' => number_format((float) $order->total), 'currency' => $this->settings->get('currency'), 'branch' => $order->branch?->name ?? ''];

        $this->notifier->notify(new StaffNotice(
            type: 'new_order',
            title: 'New online order :number',
            body: ':customer ordered for :currency :total at :branch.',
            params: $params,
            level: 'success',
            url: "/admin/orders/{$order->id}",
            branchId: $order->branch_id,
            message: function () use ($order, $params) {
                $b = $this->settings->get('business_name');
                $sms = __(':business: new online order :number from :customer, :currency :total.', $params + ['business' => $b]);
                $rows = $order->items->map(fn ($i) => [$i->product_name, 0 + (float) $i->quantity, number_format((float) $i->line_total)])->all();
                $doc = [
                    'title' => __('New online order :number', $params),
                    'subtitle' => $order->created_at?->format('d/m/Y H:i').' · '.$params['branch'],
                    'meta' => array_filter([
                        __('Customer') => $params['customer'],
                        __('Phone') => $order->contact_phone,
                        __('Delivery') => $order->delivery_method === 'delivery' ? __('Delivery to :address', ['address' => $order->delivery_address ?? '-']) : __('Pickup at the branch'),
                        __('Notes') => $order->customer_notes,
                    ]),
                    'sections' => [[
                        'heading' => __('Items'), 'columns' => [__('Product'), __('Quantity'), __('Total')], 'numeric' => [1, 2],
                        'rows' => $rows, 'total' => [__('Total')." ({$params['currency']})", '', $params['total']],
                    ]],
                    'closing' => [__('Confirm the order in PatoPro → Orders.')],
                ];
                $text = "*{$b}*\n".$sms."\n\n".collect($rows)->map(fn ($r) => "• {$r[1]} × {$r[0]}")->implode("\n");

                return (new OutgoingMessage('new_order', $sms, $text, $doc['title'], $doc, "order-{$order->number}.pdf"))->withRelated($order);
            },
        ));
    }

    public function saleCancelled(Sale $sale, string $reason, string $by): void
    {
        $params = ['number' => $sale->number, 'total' => number_format((float) $sale->total), 'currency' => $this->settings->get('currency'), 'user' => $by, 'reason' => $reason];

        $this->notifier->notify(new StaffNotice(
            type: 'sale_cancelled',
            title: 'Sale :number was cancelled',
            body: ':user cancelled :currency :total. Reason: :reason',
            params: $params,
            level: 'warning',
            url: "/admin/sales/{$sale->id}",
            branchId: $sale->branch_id,
            message: function () use ($sale, $params) {
                $b = $this->settings->get('business_name');
                $sms = __(':business: sale :number (:currency :total) cancelled by :user. Reason: :reason', $params + ['business' => $b]);
                $doc = [
                    'title' => __('Sale :number was cancelled', $params),
                    'meta' => [
                        __('Total') => "{$params['currency']} {$params['total']}",
                        __('Cancelled by') => $params['user'],
                        __('Reason') => $params['reason'],
                        __('Sold on') => $sale->sold_at?->format('d/m/Y H:i'),
                        __('Branch') => $sale->branch?->name,
                    ],
                    'sections' => [[
                        'heading' => __('Items'), 'columns' => [__('Product'), __('Quantity'), __('Total')], 'numeric' => [1, 2],
                        'rows' => $sale->items->map(fn ($i) => [$i->product_name, 0 + (float) $i->quantity, number_format((float) $i->line_total)])->all(),
                    ]],
                ];

                return (new OutgoingMessage('sale_cancelled', $sms, $sms, $doc['title'], $doc, "cancelled-{$sale->number}.pdf"))->withRelated($sale);
            },
        ));
    }

    public function leaveRequested(LeaveRequest $leave): void
    {
        $leave->loadMissing(['employee', 'leaveType']);
        $params = ['name' => $leave->employee?->full_name ?? '', 'days' => 0 + (float) $leave->days, 'type' => $leave->leaveType?->name ?? '', 'from' => $leave->start_date?->format('d/m/Y'), 'to' => $leave->end_date?->format('d/m/Y')];

        $this->notifier->notify(new StaffNotice(
            type: 'leave_request',
            title: ':name asks for :days days of :type',
            body: 'From :from to :to. Approve or reject it under Leave.',
            params: $params,
            url: '/admin/hr/leave',
            branchId: $leave->employee?->branch_id,
            message: function () use ($leave, $params) {
                $b = $this->settings->get('business_name');
                $sms = __(':business: :name asks for :days days of :type (:from - :to).', $params + ['business' => $b]);
                $doc = [
                    'title' => __('Leave request :number', ['number' => $leave->number]),
                    'meta' => array_filter([__('Employee') => $params['name'], __('Type') => $params['type'], __('From') => $params['from'], __('To') => $params['to'], __('Days') => (string) $params['days'], __('Reason') => $leave->reason]),
                ];

                return (new OutgoingMessage('leave_request', $sms, $sms, $doc['title'], $doc, "leave-{$leave->number}.pdf"))->withRelated($leave);
            },
        ));
    }

    public function payrollPrepared(PayrollRun $run): void
    {
        $params = ['number' => $run->number, 'period' => $run->period, 'net' => number_format((float) $run->net), 'currency' => $this->settings->get('currency')];

        $this->notifier->notify(new StaffNotice(
            type: 'payroll_prepared',
            title: 'Payroll :period is ready for approval',
            body: ':number: net pay :currency :net.',
            params: $params,
            url: "/admin/hr/payroll/{$run->id}",
            branchId: $run->branch_id,
            message: function () use ($run, $params) {
                $b = $this->settings->get('business_name');
                $sms = __(':business: payroll :period is ready for approval, net :currency :net.', $params + ['business' => $b]);
                $doc = [
                    'title' => __('Payroll :period is ready for approval', $params),
                    'meta' => [__('Number') => $run->number, __('Employees') => (string) $run->payslips()->count(), __('Gross pay') => number_format((float) $run->gross), __('Net pay') => $params['net'], __('Cost to the business') => number_format((float) $run->employer_cost)],
                ];

                return (new OutgoingMessage('payroll_prepared', $sms, $sms, $doc['title'], $doc, "payroll-{$run->number}.pdf"))->withRelated($run);
            },
        ));
    }
}

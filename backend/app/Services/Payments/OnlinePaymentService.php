<?php

namespace App\Services\Payments;

use App\Exceptions\BusinessException;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Payments\OnlinePayment;
use App\Models\Payments\PaymentWebhookEvent;
use App\Models\Sales\Order;
use App\Models\Sales\OrderStatusHistory;
use App\Models\Sales\Sale;
use App\Services\Core\NumberGenerator;
use App\Services\Core\SettingsService;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\StaffNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mobile-money payments through Snippe. The customer gets a USSD push and
 * enters their PIN; the result arrives by webhook (or by asking Snippe when
 * the till or the shop checks the status). Only a completed payment can be
 * used for a sale, and only once.
 */
class OnlinePaymentService
{
    /** Ask Snippe for the status at most this often per payment while waiting. */
    private const CHECK_EVERY_SECONDS = 4;

    public function __construct(
        private readonly SnippeClient $gateway,
        private readonly SettingsService $settings,
        private readonly NumberGenerator $numbers,
        private readonly Notifier $notifier,
    ) {}

    /** @return array{configured: bool, enabled: bool, pos: bool, shop: bool, webhook_secret: bool, webhook_url: string, webhook_https: bool, minimum: int} */
    public function availability(): array
    {
        $configured = $this->gateway->configured();
        $enabled = $this->settings->bool('online_payments_enabled');

        return [
            'configured' => $configured,
            'enabled' => $enabled,
            'pos' => $configured && $enabled && $this->settings->bool('online_payments_pos'),
            'shop' => $configured && $enabled && $this->settings->bool('online_payments_shop'),
            'webhook_secret' => $this->gateway->webhookConfigured(),
            'webhook_url' => $this->gateway->webhookAddress(),
            'webhook_https' => $this->gateway->webhookUrl() !== null,
            'minimum' => (int) config('mauzo.snippe.minimum', 500),
        ];
    }

    public function assertAvailable(string $purpose): void
    {
        $a = $this->availability();
        if (! $a['configured']) {
            throw new BusinessException(__('Online payments are not set up yet: the Snippe API key is missing on the server.'), 'GATEWAY_NOT_CONFIGURED');
        }
        if (! ($purpose === 'pos' ? $a['pos'] : $a['shop'])) {
            throw new BusinessException($purpose === 'pos'
                ? __('Payment by phone is switched off for the till.')
                : __('Paying online is switched off for shop orders.'), 'ONLINE_PAYMENTS_OFF');
        }
    }

    /**
     * Send a USSD push to the customer's phone.
     *
     * @param  array{purpose: string, amount: float, phone: string, payer_name?: ?string, branch_id: int, customer?: ?Customer, order?: ?Order, user?: ?User}  $p
     */
    public function request(array $p): OnlinePayment
    {
        $this->assertAvailable($p['purpose']);

        $phone = SnippeClient::normalizePhone($p['phone']);
        if (! $phone) {
            throw new BusinessException(__('Enter a Tanzanian mobile number, e.g. 0712 345 678.'), 'INVALID_PHONE');
        }
        $amount = (float) $p['amount'];
        if (abs($amount - round($amount)) > 0.001) {
            throw new BusinessException(__('Mobile money takes whole shillings only. Round the amount, e.g. :amount.', ['amount' => number_format(ceil($amount))]));
        }
        $minimum = (int) config('mauzo.snippe.minimum', 500);
        if ($amount < $minimum) {
            throw new BusinessException(__('The smallest amount that can be paid by phone is :amount.', ['amount' => number_format($minimum)]));
        }

        $customer = $p['customer'] ?? null;
        $order = $p['order'] ?? null;
        $name = trim((string) ($p['payer_name'] ?? $customer?->name ?? ''));

        $number = $this->numbers->next('online_payment');
        $payment = OnlinePayment::create([
            'number' => $number,
            'purpose' => $p['purpose'],
            'branch_id' => $p['branch_id'],
            'order_id' => $order?->id,
            'customer_id' => $customer?->id ?? $order?->customer_id,
            'amount' => $amount,
            'phone' => $phone,
            'payer_name' => $name !== '' ? mb_substr($name, 0, 150) : null,
            'status' => 'pending',
            'idempotency_key' => mb_substr('mz-'.$number, 0, 30),
            'requested_by' => ($p['user'] ?? null)?->id,
        ]);

        [$first, $last] = $this->splitName($name);
        try {
            $data = $this->gateway->createMobilePayment([
                'amount' => (int) round($amount),
                'phone' => $phone,
                'firstname' => $first,
                'lastname' => $last,
                'email' => $this->email($customer),
                'idempotency_key' => $payment->idempotency_key,
                'metadata' => array_filter([
                    'online_payment' => $number,
                    'order' => $order?->number,
                    'branch_id' => (string) $p['branch_id'],
                ]),
            ]);
        } catch (BusinessException $e) {
            $payment->forceFill(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 255)])->save();
            throw $e;
        }

        $payment->forceFill([
            'reference' => $data['reference'] ?? null,
            'status' => in_array($data['status'] ?? 'pending', OnlinePayment::STATUSES, true) ? $data['status'] : 'pending',
            'expires_at' => isset($data['expires_at']) ? $this->time($data['expires_at']) : now()->addHours(4),
            'last_checked_at' => now(),
        ])->save();

        return $payment->fresh();
    }

    /** While waiting, ask Snippe now and then (in case the webhook cannot reach us). */
    public function refresh(OnlinePayment $payment, bool $force = false): OnlinePayment
    {
        if ($payment->is_final || ! $payment->reference) {
            return $payment;
        }
        if (! $force && $payment->last_checked_at && $payment->last_checked_at->gt(now()->subSeconds(self::CHECK_EVERY_SECONDS))) {
            return $payment;
        }
        $payment->forceFill(['last_checked_at' => now()])->save();

        try {
            $data = $this->gateway->payment($payment->reference);
        } catch (BusinessException $e) {
            Log::info('Online payment status check failed', ['payment' => $payment->number, 'error' => $e->getMessage()]);

            return $payment;
        }

        $status = (string) ($data['status'] ?? 'pending');
        if ($status !== 'pending') {
            $this->apply($payment, $status, $data);
        } elseif ($payment->expires_at && $payment->expires_at->isPast()) {
            $this->apply($payment, 'expired', []);
        }

        return $payment->fresh();
    }

    public function resend(OnlinePayment $payment): OnlinePayment
    {
        if ($payment->status !== 'pending' || ! $payment->reference) {
            throw new BusinessException(__('This payment is no longer waiting. Send a new request instead.'));
        }
        $this->gateway->push($payment->reference);

        return $payment;
    }

    /** Stop waiting at the till. If the customer still pays later, it shows as paid but not used. */
    public function cancel(OnlinePayment $payment): OnlinePayment
    {
        if ($payment->status === 'pending') {
            $payment->forceFill(['status' => 'cancelled', 'failure_reason' => __('Cancelled at the till')])->save();
        }

        return $payment;
    }

    /**
     * Webhook from Snippe. Returns a short result for the log; never throws for
     * a well-formed but unknown event (Snippe would retry forever).
     *
     * @param  array<string, string|null>  $headers
     */
    public function handleWebhook(string $raw, array $headers): array
    {
        $verified = $this->gateway->verifySignature($raw, $headers['timestamp'] ?? null, $headers['signature'] ?? null);
        $event = json_decode($raw, true);
        if (! is_array($event)) {
            return ['status' => 400, 'result' => 'invalid JSON'];
        }

        $type = (string) ($event['type'] ?? $event['event'] ?? $headers['event'] ?? '');
        $data = (array) ($event['data'] ?? $event);
        $eventId = isset($event['id']) ? (string) $event['id'] : null;
        $reference = isset($data['reference']) ? (string) $data['reference'] : null;

        if ($eventId && PaymentWebhookEvent::query()->where('event_id', $eventId)->whereNotNull('processed_at')->exists()) {
            return ['status' => 200, 'result' => 'duplicate'];
        }

        $log = PaymentWebhookEvent::query()->updateOrCreate(
            ['event_id' => $eventId ?? 'noid-'.sha1($raw)],
            ['type' => mb_substr($type, 0, 40), 'reference' => $reference, 'verified' => $verified, 'payload' => $event],
        );

        if (! $verified) {
            $log->forceFill(['result' => 'signature rejected'])->save();
            Log::warning('Snippe webhook with a bad or missing signature', ['event' => $eventId, 'reference' => $reference]);

            return ['status' => 401, 'result' => 'invalid signature'];
        }

        $payment = $reference ? OnlinePayment::query()->where('reference', $reference)->first() : null;
        if (! $payment && ! empty($data['metadata']['online_payment'])) {
            $payment = OnlinePayment::query()->where('number', (string) $data['metadata']['online_payment'])->first();
        }
        if (! $payment) {
            $log->forceFill(['result' => 'no matching payment', 'processed_at' => now()])->save();

            return ['status' => 200, 'result' => 'ignored'];
        }

        $status = match ($type) {
            'payment.completed' => 'completed',
            'payment.failed' => 'failed',
            'payment.voided' => 'voided',
            'payment.expired' => 'expired',
            default => null,
        };
        if ($status) {
            $this->apply($payment, $status, $data);
        }
        $log->forceFill(['result' => $status ? "payment {$payment->number} {$status}" : 'event not used', 'processed_at' => now()])->save();

        return ['status' => 200, 'result' => 'ok'];
    }

    /**
     * Use a completed payment for a sale (at the till, or when an order paid
     * online is handed over). Locks the row so it is used only once.
     */
    public function useForSale(int $id, float $amount, Sale $sale, int $branchId, ?Order $order = null): OnlinePayment
    {
        $payment = OnlinePayment::query()->whereKey($id)->lockForUpdate()->first();
        if (! $payment) {
            throw new BusinessException(__('The phone payment was not found.'), 'ONLINE_PAYMENT_NOT_FOUND');
        }
        if ($payment->status === 'pending') {
            $payment = $this->refresh($payment, true);
        }
        if ($payment->status !== 'completed') {
            throw new BusinessException(__('The phone payment :number is not confirmed yet (:status).', ['number' => $payment->number, 'status' => __($payment->status)]), 'ONLINE_PAYMENT_NOT_PAID');
        }
        if ($payment->sale_id || $payment->used_at) {
            throw new BusinessException(__('The phone payment :number was already used for another sale.', ['number' => $payment->number]), 'ONLINE_PAYMENT_USED');
        }
        if ($payment->order_id && (! $order || (int) $order->id !== (int) $payment->order_id)) {
            throw new BusinessException(__('The phone payment :number belongs to another order.', ['number' => $payment->number]));
        }
        if ((int) $payment->branch_id !== $branchId) {
            throw new BusinessException(__('The phone payment :number was taken at another branch.', ['number' => $payment->number]));
        }
        if (abs((float) $payment->amount - $amount) > 0.01) {
            throw new BusinessException(__('The phone payment :number was for :paid, not :amount.', ['number' => $payment->number, 'paid' => number_format((float) $payment->amount, 2), 'amount' => number_format($amount, 2)]));
        }

        $payment->forceFill(['sale_id' => $sale->id, 'used_at' => now(), 'customer_id' => $payment->customer_id ?? $sale->customer_id])->save();

        return $payment;
    }

    /** Completed payments of an order that have not been used for its sale yet. */
    public function unusedForOrder(Order $order)
    {
        return OnlinePayment::query()->where('order_id', $order->id)->where('status', 'completed')->whereNull('sale_id')->whereNull('used_at')->get();
    }

    /** Record a final status once (later events for a final payment change nothing, except a late completion). */
    private function apply(OnlinePayment $payment, string $status, array $data): void
    {
        DB::transaction(function () use ($payment, $status, $data) {
            $p = OnlinePayment::query()->whereKey($payment->id)->lockForUpdate()->first();
            if (! $p || $p->status === 'completed' || $p->status === $status) {
                return;
            }
            // Money taken after the till stopped waiting is still recorded as received.
            if ($p->is_final && $status !== 'completed') {
                return;
            }

            $fill = ['status' => $status];
            if ($status === 'completed') {
                $fill += [
                    'completed_at' => isset($data['completed_at']) ? $this->time($data['completed_at']) : now(),
                    'provider' => $data['channel']['provider'] ?? $p->provider,
                    'external_reference' => $data['external_reference'] ?? $p->external_reference,
                    'fee' => (float) ($data['settlement']['fees']['value'] ?? 0),
                    'failure_reason' => null,
                ];
                if (isset($data['amount']['value']) && abs((float) $data['amount']['value'] - (float) $p->amount) > 0.01) {
                    // Should never happen; keep what was really received and tell the managers.
                    Log::error('Online payment amount differs from the request', ['payment' => $p->number, 'requested' => $p->amount, 'received' => $data['amount']['value']]);
                    $fill['amount'] = (float) $data['amount']['value'];
                }
            } else {
                $fill['failure_reason'] = mb_substr((string) ($data['failure_reason'] ?? __(match ($status) {
                    'expired' => 'The customer did not confirm in time.',
                    'voided' => 'Cancelled before it was paid.',
                    default => 'The payment did not go through.',
                })), 0, 255);
            }
            $p->forceFill($fill)->save();

            if ($status === 'completed' && $p->order_id) {
                $this->orderPaid($p);
            }
        });
    }

    private function orderPaid(OnlinePayment $p): void
    {
        $order = Order::query()->whereKey($p->order_id)->lockForUpdate()->first();
        if (! $order) {
            return;
        }
        $order->forceFill([
            'paid_online' => round((float) $order->paid_online + (float) $p->amount, 2),
            'paid_online_at' => $p->completed_at ?? now(),
        ])->save();

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'note' => __('Paid online: :amount by :provider (:reference)', ['amount' => number_format((float) $p->amount), 'provider' => $p->provider_name ?? __('mobile money'), 'reference' => $p->external_reference ?? $p->number]),
            'user_id' => null,
        ]);

        $cancelled = $order->status === 'cancelled';
        try {
            $this->notifier->notify(new StaffNotice(
                type: 'online_payment',
                title: $cancelled ? 'Cancelled order :number was paid online: refund the customer' : 'Order :number paid online',
                body: ':amount by :provider from :phone.',
                params: ['number' => $order->number, 'amount' => number_format((float) $p->amount), 'provider' => $p->provider_name ?? 'mobile money', 'phone' => $p->phone],
                level: $cancelled ? 'warning' : 'success',
                url: "/admin/orders/{$order->id}",
                branchId: $order->branch_id,
                dedupeKey: "online_payment:{$p->id}",
            ));
        } catch (Throwable $e) {
            Log::warning('Could not notify about an online payment', ['error' => $e->getMessage()]);
        }
    }

    /** The gateway sends UTC ("...Z"); the database keeps the business's local time. */
    private function time(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone((string) config('app.timezone'));
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));
        if (! $parts) {
            return [__('Customer'), mb_substr((string) $this->settings->get('business_name') ?: 'Shop', 0, 60)];
        }
        $first = array_shift($parts);

        return [mb_substr($first, 0, 60), mb_substr($parts ? implode(' ', $parts) : $first, 0, 60)];
    }

    /** Snippe requires an email; the customer's, else the business's. */
    private function email(?Customer $customer): string
    {
        foreach ([$customer?->email, $this->settings->get('business_email'), config('mail.from.address')] as $email) {
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return 'payments@patopro.co.tz';
    }
}

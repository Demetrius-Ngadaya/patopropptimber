<?php

namespace App\Platform\Billing;

use App\Exceptions\BusinessException;
use App\Platform\Models\Business;
use App\Platform\Models\Payment;
use App\Platform\Models\Plan;
use App\Platform\Models\Setting;
use App\Platform\Support\PlatformSms;
use App\Services\Core\SmsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Paying for a package:
 *   start    → payment recorded as pending, USSD push sent to the payer's phone
 *   refresh  → the gateway is asked (by the callback, the page, or every few minutes)
 *   complete → the package is extended: from today, or from the end of the current paid/trial period
 * The gateways offered are chosen by the Software Engineer (platform:billing).
 */
class BillingService
{
    public function __construct(private readonly PlatformSms $sms) {}

    /** @return array<string, PaymentGateway> */
    public function gateways(): array
    {
        return [
            'azampay' => app(AzamPayGateway::class),
            'snippe' => app(SnippeGateway::class),
        ];
    }

    public function gateway(string $key): PaymentGateway
    {
        return $this->gateways()[$key] ?? throw new BusinessException(__('This payment method is not offered.'), 'GATEWAY_NOT_OFFERED', 422);
    }

    // ------------------------------------------------------------- the Software Engineer's choice

    /** @return array<int, string> */
    public function enabledKeys(): array
    {
        $keys = (array) Setting::get('billing.gateways', config('patopro.platform.billing.gateways'));

        return array_values(array_intersect($keys, array_keys($this->gateways())));
    }

    public function defaultKey(): ?string
    {
        $default = (string) Setting::get('billing.default', config('patopro.platform.billing.default'));
        $enabled = $this->offeredKeys(true);

        return in_array($default, $enabled, true) ? $default : ($enabled[0] ?? null);
    }

    public function customerChoice(): bool
    {
        return (bool) Setting::get('billing.customer_choice', config('patopro.platform.billing.customer_choice'));
    }

    /**
     * Gateways a business may pay with: enabled and with their keys in place.
     * Without customer choice that is only the default one.
     *
     * @return array<int, string>
     */
    public function offeredKeys(bool $all = false): array
    {
        $keys = array_values(array_filter($this->enabledKeys(), fn ($k) => $this->gateways()[$k]->configured()));
        if ($all || $this->customerChoice()) {
            return $keys;
        }
        $default = (string) Setting::get('billing.default', config('patopro.platform.billing.default'));

        return in_array($default, $keys, true) ? [$default] : array_slice($keys, 0, 1);
    }

    /** For the payment page. */
    public function options(): array
    {
        return collect($this->offeredKeys())->map(fn ($key) => [
            'key' => $key,
            'label' => $this->gateways()[$key]->label(),
            'default' => $key === $this->defaultKey(),
            'methods' => collect($this->gateways()[$key]->methods())->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
        ])->values()->all();
    }

    // ------------------------------------------------------------- paying

    /**
     * @param  array{plan: string, billing_cycle: string, gateway?: ?string, method?: ?string, phone: string}  $input
     * @param  array{name?: ?string, email?: ?string, by?: ?string}  $payer
     */
    public function start(Business $business, array $input, array $payer): Payment
    {
        $plan = Plan::query()->where('code', $input['plan'])->where('is_active', true)->first()
            ?? throw new BusinessException(__('Choose one of the packages shown.'), 'PLAN_NOT_FOUND', 422);
        $cycle = $input['billing_cycle'] === 'yearly' ? 'yearly' : 'monthly';
        $amount = (float) ($cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly);
        if ($amount <= 0) {
            throw new BusinessException(__('This package has no price set. Please contact SHEZONI.'), 'NO_PRICE', 422);
        }

        $key = ($input['gateway'] ?? null) ?: $this->defaultKey();
        if (! $key || ! in_array($key, $this->offeredKeys(), true)) {
            throw new BusinessException(__('This payment method is not offered.'), 'GATEWAY_NOT_OFFERED', 422);
        }
        $gateway = $this->gateway($key);

        $phone = SmsService::normalizePhone($input['phone']);
        if (! $phone || ! preg_match('/^255[67]\d{8}$/', $phone)) {
            throw new BusinessException(__('Enter a valid Tanzanian mobile number, e.g. 0712 345 678.'), 'BAD_PHONE', 422);
        }

        $methods = $gateway->methods();
        $method = ($input['method'] ?? null) ?: null;
        if ($key === 'azampay') {
            $method ??= AzamPayGateway::detect($phone); // the network comes from the number
            if (! array_key_exists((string) $method, $methods)) {
                throw new BusinessException(__('Use an Airtel, Mixx by Yas (Tigo) or HaloPesa number to pay. M-Pesa is not available yet.'), 'METHOD_NOT_SUPPORTED', 422);
            }
        }

        // One push at a time: a second tap within a minute returns the same payment.
        $recent = Payment::query()->where('business_id', $business->id)->where('status', 'pending')
            ->where('created_at', '>', now()->subMinute())->latest('id')->first();
        if ($recent) {
            return $recent;
        }

        $payment = Payment::query()->create([
            'number' => Payment::nextNumber(),
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'billing_cycle' => $cycle,
            'months' => $cycle === 'yearly' ? 12 : 1,
            'amount' => $amount,
            'currency' => $plan->currency,
            'gateway' => $key,
            'method' => $method,
            'phone' => $phone,
            'status' => 'pending',
            'recorded_by' => $payer['by'] ?? null,
        ]);

        try {
            $gateway->start($payment, $payer);
        } catch (Throwable $e) {
            $payment->forceFill(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 255)])->save();

            throw $e;
        }

        return $payment->refresh();
    }

    /** Ask the gateway; complete, fail or expire the payment accordingly. */
    public function refresh(Payment $payment): Payment
    {
        if (! $payment->isPending() || $payment->gateway === 'manual') {
            return $payment;
        }

        try {
            $status = $this->gateway($payment->gateway)->check($payment);
        } catch (Throwable $e) {
            report($e);
            $status = 'pending';
        }
        $payment->forceFill(['checked_at' => now()])->save();

        if ($status === 'completed') {
            return $this->complete($payment);
        }
        if ($status === 'failed') {
            $payment->forceFill(['status' => 'failed', 'failure_reason' => __('The payment was not completed.')])->save();
        } elseif ($payment->created_at->lt(now()->subMinutes((int) config('patopro.platform.billing.pending_minutes')))) {
            $payment->forceFill(['status' => 'expired', 'failure_reason' => __('The payment was not confirmed in time.')])->save();
        }

        return $payment;
    }

    /** The money is in: extend the business's package (once, however many times it is called). */
    public function complete(Payment $payment, ?string $note = null): Payment
    {
        $done = DB::connection(config('patopro.platform.connection'))->transaction(function () use ($payment, $note) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status === 'completed') {
                return false;
            }
            $business = Business::query()->lockForUpdate()->findOrFail($locked->business_id);

            $from = self::periodStart($business);
            $to = $from->copy()->addMonthsNoOverflow($locked->months);
            $locked->forceFill([
                'status' => 'completed',
                'paid_at' => now(),
                'period_from' => $from,
                'period_to' => $to,
                'failure_reason' => null,
                'note' => $note ?? $locked->note,
            ])->save();

            $meta = (array) $business->meta;
            unset($meta['reminded']);
            $business->forceFill([
                'paid_until' => $to,
                'plan_id' => $locked->plan_id,
                'billing_cycle' => $locked->billing_cycle,
                'meta' => $meta,
            ])->save();

            return true;
        });

        $payment->refresh();
        if ($done) {
            $this->sendReceipt($payment);
        }

        return $payment;
    }

    /** A cash or bank payment recorded by the Software Engineer. */
    public function recordManual(Business $business, Plan $plan, string $cycle, float $amount, string $by, ?string $note = null): Payment
    {
        $payment = Payment::query()->create([
            'number' => Payment::nextNumber(),
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'billing_cycle' => $cycle === 'yearly' ? 'yearly' : 'monthly',
            'months' => $cycle === 'yearly' ? 12 : 1,
            'amount' => $amount,
            'currency' => $plan->currency,
            'gateway' => 'manual',
            'phone' => $business->phone,
            'status' => 'pending',
            'recorded_by' => $by,
            'note' => $note,
        ]);

        return $this->complete($payment, $note);
    }

    /** Paying early keeps the days already paid for (or left in the trial). */
    public static function periodStart(Business $business): Carbon
    {
        $end = $business->servedUntil();

        return $end && $end->isFuture() ? $end->copy() : now();
    }

    private function sendReceipt(Payment $payment): void
    {
        $business = $payment->business;
        $phone = $payment->phone ?: $business->phone;
        if (! $phone) {
            return;
        }
        $this->sms->send($phone, __('PatoPro: payment received, :currency :amount for :plan (:number). :name is paid until :date. Thank you!', [
            'currency' => $payment->currency,
            'amount' => number_format((float) $payment->amount),
            'plan' => $payment->plan?->name,
            'number' => $payment->number,
            'name' => $business->name,
            'date' => $payment->period_to?->format('d/m/Y'),
        ], 'sw'));
    }
}

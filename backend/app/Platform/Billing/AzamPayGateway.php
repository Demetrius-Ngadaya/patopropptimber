<?php

namespace App\Platform\Billing;

use App\Exceptions\BusinessException;
use App\Platform\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AzamPay through the SHEZONI payment service (the same one used for selling
 * domains, hosting and SMS): payment_data → items_azam_pay[0].externalId,
 * then the status URL tells whether it was paid.
 */
class AzamPayGateway implements PaymentGateway
{
    private function c(string $key): mixed
    {
        return config('patopro.platform.billing.azampay.'.$key);
    }

    public function key(): string
    {
        return 'azampay';
    }

    public function label(): string
    {
        return 'AzamPay';
    }

    public function configured(): bool
    {
        return filled($this->c('initiation_url')) && filled($this->c('status_url')) && filled($this->c('user_id'));
    }

    public function methods(): array
    {
        return (array) $this->c('methods');
    }

    public function start(Payment $payment, array $payer): void
    {
        $names = preg_split('/\s+/', trim((string) ($payer['name'] ?? '')), 2) ?: [];
        $data = [
            'billing_firstname' => $names[0] ?? 'PatoPro',
            'billing_lastname' => $names[1] ?? ($names[0] ?? 'Customer'),
            'mechant_email' => $this->c('merchant_email'), // spelled as the service expects
            'amount' => (int) round((float) $payment->amount),
            'payment_method' => $payment->method,
            'billing_phone' => $payment->phone,
            'user_id' => (int) $this->c('user_id'),
            'user_status' => $this->c('user_status'),
            'total_kazi' => 1,
        ];

        try {
            $response = Http::connectTimeout(5)->timeout(20)->acceptJson()->post($this->c('initiation_url'), ['payment_data' => [$data]]);
        } catch (Throwable $e) {
            Log::error('AzamPay initiation error', ['payment' => $payment->number, 'error' => $e->getMessage()]);
            throw new BusinessException(__('Could not connect to the payment service. Please try again.'), 'GATEWAY_UNAVAILABLE', 502);
        }

        $externalId = $response->json('items_azam_pay.0.externalId');
        $payment->forceFill(['gateway_response' => ['start' => $response->json()]])->save();
        if ($response->failed() || ! $externalId) {
            Log::warning('AzamPay initiation refused', ['payment' => $payment->number, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
            throw new BusinessException(__('The payment service did not accept the request. Check the phone number and network, then try again.'), 'GATEWAY_REFUSED', 502);
        }

        $payment->forceFill(['gateway_reference' => (string) $externalId])->save();
    }

    public function check(Payment $payment): string
    {
        if (! $payment->gateway_reference) {
            return 'pending';
        }
        $query = http_build_query([
            'externalId' => $payment->gateway_reference,
            'user_id' => (int) $this->c('user_id'),
            'user_status' => $this->c('user_status'),
        ]);

        try {
            $response = Http::connectTimeout(5)->timeout(20)->acceptJson()->post($this->c('status_url').'?'.$query, [
                'payment_data' => [[
                    'externalId' => $payment->gateway_reference,
                    'user_id' => (int) $this->c('user_id'),
                    'user_status' => $this->c('user_status'),
                ]],
            ]);
        } catch (Throwable $e) {
            Log::warning('AzamPay status check error', ['payment' => $payment->number, 'error' => $e->getMessage()]);

            return 'pending';
        }

        $status = (string) $response->json('items_azam_pay.0.status');
        $payment->forceFill(['gateway_response' => array_merge((array) $payment->gateway_response, ['status' => $response->json()])])->save();

        return self::meaning($status);
    }

    /**
     * The network from the number, as in the SHEZONI hosting portal:
     * Airtel 068/069/078, Tigo / Mixx by Yas 065/067/071/077, HaloPesa 061/062.
     * Vodacom (074/075/076) is M-Pesa, which this service does not take: null.
     */
    public static function detect(string $phone): ?string
    {
        $prefix = substr($phone, 3, 2); // 255 7x …
        foreach (['Airtel' => ['68', '69', '78'], 'Tigo' => ['65', '67', '71', '77'], 'Halopesa' => ['61', '62']] as $method => $prefixes) {
            if (in_array($prefix, $prefixes, true)) {
                return $method;
            }
        }

        return null;
    }

    /** The service's words for paid / not paid. */
    public static function meaning(string $status): string
    {
        $s = strtolower(trim($status));

        return match (true) {
            in_array($s, ['success', 'successful', 'succeeded', 'completed', 'complete', 'paid', 'approved', 'settled'], true) => 'completed',
            in_array($s, ['failed', 'failure', 'fail', 'cancelled', 'canceled', 'rejected', 'declined', 'expired', 'timeout', 'error', 'reversed'], true) => 'failed',
            default => 'pending',
        };
    }
}

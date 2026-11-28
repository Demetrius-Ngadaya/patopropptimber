<?php

namespace App\Platform\Billing;

use App\Platform\Models\Payment;
use App\Services\Payments\SnippeClient;

/** Snippe (snippe.sh), with the same SNIPPE_* keys the online shop uses. */
class SnippeGateway implements PaymentGateway
{
    public function __construct(private readonly SnippeClient $client) {}

    public function key(): string
    {
        return 'snippe';
    }

    public function label(): string
    {
        return 'Snippe';
    }

    public function configured(): bool
    {
        return $this->client->configured();
    }

    public function methods(): array
    {
        return (array) config('patopro.platform.billing.snippe.methods');
    }

    public function start(Payment $payment, array $payer): void
    {
        $names = preg_split('/\s+/', trim((string) ($payer['name'] ?? '')), 2) ?: [];
        $result = $this->client->createMobilePayment([
            'amount' => (int) round((float) $payment->amount),
            'phone' => (string) $payment->phone,
            'firstname' => $names[0] ?? 'PatoPro',
            'lastname' => $names[1] ?? ($names[0] ?? 'Customer'),
            'email' => (string) ($payer['email'] ?? '') ?: 'billing@shezoni.com',
            'metadata' => ['platform_payment' => $payment->number],
            'idempotency_key' => 'platform-'.$payment->number,
            'webhook_url' => rtrim((string) config('app.url'), '/').'/api/v1/platform/webhooks/snippe',
        ]);
        $payment->forceFill(['gateway_reference' => (string) ($result['reference'] ?? ''), 'gateway_response' => ['start' => $result]])->save();
    }

    public function check(Payment $payment): string
    {
        if (! $payment->gateway_reference) {
            return 'pending';
        }
        $data = $this->client->payment($payment->gateway_reference);

        return match ((string) ($data['status'] ?? '')) {
            'completed' => 'completed',
            'failed', 'voided', 'expired' => 'failed',
            default => 'pending',
        };
    }
}

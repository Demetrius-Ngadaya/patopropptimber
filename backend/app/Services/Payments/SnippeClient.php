<?php

namespace App\Services\Payments;

use App\Exceptions\BusinessException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for the Snippe payments API (version 2026-01-25).
 * Only collections are used: create a mobile-money payment, read its status,
 * resend the USSD push, and read the balance to test the connection.
 */
class SnippeClient
{
    public function configured(): bool
    {
        return filled(config('mauzo.snippe.api_key'));
    }

    public function webhookConfigured(): bool
    {
        return filled(config('mauzo.snippe.webhook_secret'));
    }

    /** Where Snippe should send payment results; null when it cannot reach us (no HTTPS). */
    public function webhookUrl(): ?string
    {
        $url = config('mauzo.snippe.webhook_url') ?: rtrim((string) config('app.url'), '/').'/api/v1/webhooks/snippe';

        return str_starts_with($url, 'https://') ? $url : null;
    }

    /** The address to paste in the Snippe dashboard, even when it is not HTTPS yet. */
    public function webhookAddress(): string
    {
        return config('mauzo.snippe.webhook_url') ?: rtrim((string) config('app.url'), '/').'/api/v1/webhooks/snippe';
    }

    /**
     * @param  array{amount: int, phone: string, firstname: string, lastname: string, email: string, metadata?: array, idempotency_key: string}  $p
     * @return array<string, mixed> the payment object (reference, status, expires_at ...)
     */
    public function createMobilePayment(array $p): array
    {
        $body = array_filter([
            'payment_type' => 'mobile',
            'details' => ['amount' => $p['amount'], 'currency' => 'TZS'],
            'phone_number' => $p['phone'],
            'customer' => ['firstname' => $p['firstname'], 'lastname' => $p['lastname'], 'email' => $p['email']],
            'webhook_url' => $this->webhookUrl(),
            'metadata' => $p['metadata'] ?? null,
        ], fn ($v) => $v !== null);

        return $this->data($this->send(fn (PendingRequest $r) => $r->withHeaders(['Idempotency-Key' => $p['idempotency_key']])->post('/v1/payments', $body)));
    }

    /** @return array<string, mixed> */
    public function payment(string $reference): array
    {
        return $this->data($this->send(fn (PendingRequest $r) => $r->get('/v1/payments/'.rawurlencode($reference))));
    }

    public function push(string $reference): void
    {
        $this->send(fn (PendingRequest $r) => $r->post('/v1/payments/'.rawurlencode($reference).'/push'));
    }

    /** @return array{available: float, balance: float} */
    public function balance(): array
    {
        $d = $this->data($this->send(fn (PendingRequest $r) => $r->get('/v1/payments/balance')));

        return ['available' => (float) ($d['available']['value'] ?? 0), 'balance' => (float) ($d['balance']['value'] ?? 0)];
    }

    /** Signature = hex(HMAC-SHA256(secret, "{timestamp}.{raw body}")), and not older than 5 minutes. */
    public function verifySignature(string $rawBody, ?string $timestamp, ?string $signature): bool
    {
        $secret = (string) config('mauzo.snippe.webhook_secret');
        if ($secret === '' || ! $timestamp || ! $signature || ! ctype_digit($timestamp)) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret), strtolower($signature));
    }

    /** 0712 345 678, +255712345678 and 255712345678 all become 255712345678. */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '255'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '255'.$digits;
        }

        return preg_match('/^255[67]\d{8}$/', $digits) ? $digits : null;
    }

    private function send(callable $call): Response
    {
        if (! $this->configured()) {
            throw new BusinessException(__('Online payments are not set up yet: the Snippe API key is missing on the server.'), 'GATEWAY_NOT_CONFIGURED');
        }
        $request = Http::baseUrl(rtrim((string) config('mauzo.snippe.base_url'), '/'))
            ->withToken((string) config('mauzo.snippe.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('mauzo.snippe.timeout', 30));

        try {
            $response = $call($request);
        } catch (ConnectionException $e) {
            Log::warning('Snippe unreachable', ['error' => $e->getMessage()]);
            throw new BusinessException(__('The payment service could not be reached. Check the internet connection and try again.'), 'GATEWAY_UNREACHABLE', 503);
        }

        if ($response->successful()) {
            return $response;
        }

        $code = (string) ($response->json('error_code') ?? '');
        $message = (string) ($response->json('message') ?? '');
        Log::warning('Snippe error', ['status' => $response->status(), 'code' => $code, 'message' => $message]);

        throw new BusinessException(match (true) {
            $response->status() === 401 => __('The payment service refused the API key. Check SNIPPE_API_KEY on the server.'),
            $response->status() === 403 => __('The Snippe API key is missing the permission to collect payments (collection:create).'),
            $response->status() === 429 => __('Too many payment requests at once. Wait a minute and try again.'),
            $code === 'validation_error' && str_contains($message, 'phone') => __('The phone number was not accepted. Use a Tanzanian mobile number, e.g. 0712 345 678.'),
            $code === 'validation_error' && str_contains($message, 'minimum') => __('The amount is below the smallest online payment allowed.'),
            $code === 'validation_error' => __('The payment service did not accept the request: :message', ['message' => $message]),
            default => __('The payment service had a problem. Try again in a moment.'),
        }, 'GATEWAY_ERROR', $response->status() >= 500 ? 503 : 422);
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        return (array) ($response->json('data') ?? []);
    }
}

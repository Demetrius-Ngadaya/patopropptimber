<?php

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Billing\BillingService;
use App\Platform\Models\Payment;
use App\Services\Payments\SnippeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Gateways telling the platform about package payments. Nothing is trusted
 * from the message itself: the payment is always checked with the gateway.
 */
class PlatformWebhookController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    /** POST platform/webhooks/azampay/{secret} {payment_data: [{externalId, …}]} */
    public function azampay(Request $request, string $secret = ''): JsonResponse
    {
        $expected = (string) config('patopro.platform.billing.azampay.callback_secret');
        if ($expected === '' || ! hash_equals($expected, $secret)) {
            return response()->json(['status' => 'error', 'message' => 'Not found.'], 404);
        }

        $externalId = (string) ($request->json('payment_data.0.externalId') ?? $request->input('externalId', ''));
        if ($externalId === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing external ID.'], 400);
        }

        $payment = Payment::query()->where('gateway', 'azampay')->where('gateway_reference', $externalId)->first();
        if (! $payment) {
            Log::info('AzamPay callback for an unknown payment', ['externalId' => $externalId]);

            return response()->json(['status' => 'success', 'message' => 'Callback received.']);
        }

        $payment = $this->billing->refresh($payment);

        return response()->json(['status' => 'success', 'message' => 'Callback received.', 'payment' => $payment->status]);
    }

    /** POST platform/webhooks/snippe (signed like the shop's Snippe callbacks). */
    public function snippe(Request $request, SnippeClient $snippe): JsonResponse
    {
        $raw = $request->getContent();
        if (! $snippe->verifySignature($raw, $request->header('X-Webhook-Timestamp'), $request->header('X-Webhook-Signature'))) {
            return response()->json(['success' => false, 'message' => 'invalid signature'], 401);
        }

        $data = (array) (json_decode($raw, true)['data'] ?? []);
        $payment = Payment::query()->where('gateway', 'snippe')
            ->where(fn ($q) => $q->where('gateway_reference', (string) ($data['reference'] ?? '-'))->orWhere('number', (string) ($data['metadata']['platform_payment'] ?? '-')))
            ->first();
        if ($payment) {
            $this->billing->refresh($payment);
        }

        return response()->json(['success' => true]);
    }
}

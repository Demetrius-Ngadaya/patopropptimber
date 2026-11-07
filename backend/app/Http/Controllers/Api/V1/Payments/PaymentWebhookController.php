<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\OnlinePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Snippe calls this address when a payment is completed, fails or expires.
 * The signature over the raw body is checked before anything is trusted.
 */
class PaymentWebhookController extends Controller
{
    public function snippe(Request $request, OnlinePaymentService $payments): JsonResponse
    {
        $result = $payments->handleWebhook($request->getContent(), [
            'timestamp' => $request->header('X-Webhook-Timestamp'),
            'signature' => $request->header('X-Webhook-Signature'),
            'event' => $request->header('X-Webhook-Event'),
        ]);

        return response()->json(['received' => $result['status'] === 200, 'result' => $result['result']], $result['status']);
    }
}

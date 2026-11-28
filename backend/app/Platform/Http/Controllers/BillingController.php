<?php

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Billing\BillingService;
use App\Platform\Models\Payment;
use App\Platform\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A business paying for its package (works while read-only too).
 * Routes: /api/v1/platform/billing…, with the business's X-Business header and a staff sign-in.
 */
class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    /** The business paying (controllers are made before the business is known, so this is checked per call). */
    private function business(): \App\Platform\Models\Business
    {
        return business()->record() ?? throw new \App\Exceptions\BusinessException(__('Choose a business first.'), 'BUSINESS_REQUIRED', 400);
    }

    /** GET platform/billing — the subscription, the packages, how to pay and past payments. */
    public function show(): JsonResponse
    {
        $record = $this->business();

        return response()->json(['success' => true, 'data' => [
            'business' => business()->toArray(),
            'billing_cycle' => $record->billing_cycle,
            'next_period_starts' => BillingService::periodStart($record)->toIso8601String(),
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort_order')->get()->map->toPublicArray()->values(),
            'gateways' => $this->billing->options(),
            'phone' => $record->phone,
            'payments' => Payment::query()->with('plan')->where('business_id', $record->id)->latest('id')->limit(20)->get()->map->toPublicArray(),
        ]]);
    }

    /** POST platform/billing/pay — send the push to the phone. */
    public function pay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:40'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'gateway' => ['nullable', 'string', 'max:20'],
            'method' => ['nullable', 'string', 'max:30'],
            'phone' => ['required', 'string', 'max:20'],
        ]);
        $user = $request->user();
        $record = $this->business();
        $payment = $this->billing->start($record, $data, [
            'name' => $user?->name ?: $record->owner_name,
            'email' => $user?->email ?: $record->email,
            'by' => $user?->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Check your phone and enter your PIN to pay :currency :amount.', ['currency' => $payment->currency, 'amount' => number_format((float) $payment->amount)]),
            'data' => $payment->load('plan')->toPublicArray(),
        ], 201);
    }

    /** GET platform/billing/payments/{number} — the page asks every few seconds until it is settled. */
    public function payment(string $number): JsonResponse
    {
        $payment = Payment::query()->with('plan')->where('business_id', $this->business()->id)->where('number', $number)->firstOrFail();
        if ($payment->isPending() && (! $payment->checked_at || $payment->checked_at->lt(now()->subSeconds(5)))) {
            $payment = $this->billing->refresh($payment);
            app(\App\Platform\Support\BusinessManager::class)->refresh(); // new end date and state
        }

        return response()->json(['success' => true, 'data' => $payment->toPublicArray() + ['business' => business()->toArray()]]);
    }
}

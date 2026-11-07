<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Core\Branch;
use App\Models\Delivery\Delivery;
use App\Services\Delivery\DeliveryFees;
use App\Services\Delivery\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's side of a delivery, opened from the tracking link (no
 * login needed: the 40-character token is the key). Also the shop's
 * delivery fee calculator.
 */
class DeliveryTrackingController extends Controller
{
    public function __construct(private readonly DeliveryService $deliveries) {}

    public function show(string $token): JsonResponse
    {
        $d = $this->find($token)->load(['courier:id,name,phone', 'branch:id,name,phone,latitude,longitude', 'order:id,number,total,status', 'sale:id,number,total']);
        $live = $d->status === 'on_the_way' && $d->courier_located_at && $d->courier_located_at->gt(now()->subMinutes(30));

        return $this->ok([
            'number' => $d->number,
            'status' => $d->status,
            'customer_name' => $d->customer_name,
            'address' => $d->address,
            'destination' => $d->latitude !== null ? [$d->latitude, $d->longitude] : null,
            'origin' => app(DeliveryFees::class)->origin($d->branch),
            'branch' => $d->branch?->only(['name', 'phone']),
            'courier' => $d->courier ? ['name' => $d->courier->name, 'phone' => $d->courier->phone] : null,
            'courier_position' => $live ? [$d->courier_latitude, $d->courier_longitude] : null,
            'courier_located_at' => $live ? $d->courier_located_at->toIso8601String() : null,
            'distance_km' => $d->distance_km,
            'fee' => (float) $d->fee,
            'discount' => (float) $d->discount,
            'final_fee' => $d->final_fee,
            'reference' => $d->order?->number ?? $d->sale?->number,
            'total' => (float) ($d->order?->total ?? $d->sale?->total ?? 0),
            'assigned_at' => $d->assigned_at?->toIso8601String(),
            'picked_up_at' => $d->picked_up_at?->toIso8601String(),
            'delivered_at' => $d->delivered_at?->toIso8601String(),
            'received_at' => $d->received_at?->toIso8601String(),
            'can_confirm' => in_array($d->status, ['on_the_way', 'delivered'], true),
            'can_chat' => ! in_array($d->status, ['cancelled', 'received', 'failed'], true),
        ]);
    }

    public function received(string $token): JsonResponse
    {
        $this->deliveries->markReceived($this->find($token));

        return $this->ok(null, __('Thank you! We are glad your goods arrived.'));
    }

    public function messages(Request $request, string $token): JsonResponse
    {
        return $this->ok($this->deliveries->messages($this->find($token), 'customer', (int) $request->query('after', 0)));
    }

    public function sendMessage(Request $request, string $token): JsonResponse
    {
        $body = $request->validate(['body' => ['required', 'string', 'min:1', 'max:1000']])['body'];
        $message = $this->deliveries->postMessage($this->find($token), 'customer', null, $body);

        return $this->created(['id' => $message->id], __('Message sent.'));
    }

    /** Delivery fee for the place the customer picked on the map. */
    public function quote(Request $request, DeliveryFees $fees): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return $this->ok($fees->quote(Branch::query()->find($data['branch_id']), (float) $data['latitude'], (float) $data['longitude']));
    }

    private function find(string $token): Delivery
    {
        return Delivery::query()->where('public_token', $token)->firstOrFail();
    }
}

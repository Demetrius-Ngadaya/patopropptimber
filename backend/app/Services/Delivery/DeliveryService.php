<?php

namespace App\Services\Delivery;

use App\Exceptions\BusinessException;
use App\Models\Core\User;
use App\Models\Delivery\Delivery;
use App\Models\Delivery\DeliveryMessage;
use App\Models\Sales\Order;
use App\Models\Sales\Sale;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use App\Services\Core\SettingsService;
use App\Services\Messaging\Messenger;
use App\Services\Messaging\OutgoingMessage;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\StaffNotice;
use App\Services\Sales\OrderService;
use App\Support\Afterwards;
use App\Support\Channels;
use App\Support\Recipients;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A delivery from assignment to the customer confirming receipt:
 *   assigned → on_the_way (location shared) → delivered (by the courier) → received (by the customer)
 * plus failed / cancelled, a courier discount on the fee, and the chat.
 */
class DeliveryService
{
    /** Positions kept for the route line on the map. */
    private const TRAIL = 60;

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly DeliveryFees $fees,
        private readonly Notifier $notifier,
        private readonly Messenger $messenger,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** People who can deliver (an active staff account allowed to take deliveries). */
    public function couriers(?int $branchId = null): Collection
    {
        return User::query()->where('type', User::TYPE_STAFF)->where('is_active', true)->with('role')
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhere('all_branches', true)->orWhereHas('role', fn ($r) => $r->where('all_branches', true))))
            ->orderBy('name')->get()
            // Delivery people: allowed to deliver, and not simply an all-powerful administrator.
            ->filter(fn (User $u) => ! $u->isSuperAdmin() && $u->hasPermission('deliveries.own'))
            ->values();
    }

    /**
     * Give an order (with delivery) or a sale to a delivery person. Re-assigning
     * an open delivery hands it to someone else.
     *
     * @param  array{address?: ?string, latitude?: ?float, longitude?: ?float, contact_phone?: ?string, fee?: ?float, note?: ?string}  $data
     */
    public function assign(Order|Sale $source, User $courier, User $by, array $data = []): Delivery
    {
        if (! $courier->hasPermission('deliveries.own') || ! $courier->is_active) {
            throw new BusinessException(__(':name cannot take deliveries.', ['name' => $courier->name]));
        }
        if ($source instanceof Order && (! $source->isOpen() && $source->status !== 'completed')) {
            throw new BusinessException(__('This order is :status.', ['status' => __($source->status)]));
        }
        if ($source instanceof Sale && $source->status !== Sale::STATUS_COMPLETED) {
            throw new BusinessException(__('Only a completed sale can be delivered.'));
        }

        return DB::transaction(function () use ($source, $courier, $by, $data) {
            $existing = Delivery::query()->where($source instanceof Order ? 'order_id' : 'sale_id', $source->id)->whereIn('status', Delivery::OPEN)->lockForUpdate()->first();
            if ($existing) {
                $previous = $existing->assigned_to;
                $existing->forceFill(['assigned_to' => $courier->id, 'assigned_by' => $by->id, 'assigned_at' => now()])->save();
                if ($previous !== $courier->id) {
                    $this->tellCourier($existing);
                }

                return $existing->fresh(['courier', 'order', 'sale']);
            }

            $source->loadMissing('customer');
            $lat = $data['latitude'] ?? ($source instanceof Order ? $source->delivery_latitude : null);
            $lng = $data['longitude'] ?? ($source instanceof Order ? $source->delivery_longitude : null);
            $quote = $this->fees->quote($source->branch, $lat !== null ? (float) $lat : null, $lng !== null ? (float) $lng : null);
            $fee = $data['fee'] ?? ($source instanceof Order ? (float) $source->delivery_fee : ((float) $source->delivery_fee ?: $quote['fee']));

            $delivery = new Delivery([
                'number' => $this->numbers->next('delivery'),
                'branch_id' => $source->branch_id,
                'order_id' => $source instanceof Order ? $source->id : null,
                'sale_id' => $source instanceof Sale ? $source->id : $source->sale_id,
                'customer_id' => $source->customer_id,
                'customer_name' => $source->customer?->name ?? ($source instanceof Sale ? ($source->customer_name ?: __('Walk-in customer')) : '-'),
                'contact_phone' => $data['contact_phone'] ?? ($source instanceof Order ? $source->contact_phone : ($source->customer_phone ?? $source->customer?->phone)),
                'address' => $data['address'] ?? ($source instanceof Order ? $source->delivery_address : $source->customer?->address),
                'latitude' => $lat,
                'longitude' => $lng,
                'distance_km' => $source instanceof Order ? ($source->delivery_distance_km ?? $quote['distance_km']) : $quote['distance_km'],
                'fee' => round((float) $fee, 2),
                'status' => 'assigned',
                'assigned_to' => $courier->id,
                'assigned_by' => $by->id,
                'assigned_at' => now(),
                'note' => $data['note'] ?? null,
            ]);
            $delivery->public_token = Str::random(40);
            $delivery->save();

            $this->audit->event('delivery.assigned', __('Delivery :number given to :name', ['number' => $delivery->number, 'name' => $courier->name]), $delivery);
            $this->tellCourier($delivery);
            $this->tellCustomer($delivery, 'assigned');

            return $delivery->fresh(['courier', 'order', 'sale']);
        });
    }

    /** The courier sets off: the customer can follow them on the map. */
    public function start(Delivery $delivery, User $courier): Delivery
    {
        $this->assertCourier($delivery, $courier);
        if ($delivery->status !== 'assigned') {
            throw new BusinessException(__('This delivery has already started.'));
        }
        $delivery->forceFill(['status' => 'on_the_way', 'picked_up_at' => now()])->save();

        // The order follows (it tells the customer itself); a till sale gets its own message.
        if ($delivery->order && $delivery->order->isOpen()) {
            app(OrderService::class)->markOutForDelivery($delivery->order, __('With :name (:number)', ['name' => $courier->name, 'number' => $delivery->number]));
        } else {
            $this->tellCustomer($delivery, 'on_the_way');
        }

        return $delivery->fresh(['courier', 'order', 'sale']);
    }

    public function updateLocation(Delivery $delivery, User $courier, float $lat, float $lng, ?int $accuracy = null): void
    {
        $this->assertCourier($delivery, $courier);
        if (! $delivery->isOpen()) {
            return; // the trip is over: stop recording
        }
        $trail = (array) $delivery->trail;
        $trail[] = [round($lat, 6), round($lng, 6), now()->timestamp];
        $delivery->forceFill([
            'courier_latitude' => $lat,
            'courier_longitude' => $lng,
            'courier_accuracy' => $accuracy,
            'courier_located_at' => now(),
            'trail' => array_slice($trail, -self::TRAIL),
        ])->saveQuietly();
    }

    /** The courier lowers the fee (e.g. a regular customer, a short trip). */
    public function discount(Delivery $delivery, User $courier, float $amount, ?string $reason): Delivery
    {
        $this->assertCourier($delivery, $courier, allowStaff: true);
        if (in_array($delivery->status, ['received', 'cancelled', 'failed'], true)) {
            throw new BusinessException(__('This delivery is finished.'));
        }
        if ($amount < 0 || $amount > (float) $delivery->fee) {
            throw new BusinessException(__('The discount can be at most the delivery fee (:fee).', ['fee' => number_format((float) $delivery->fee)]));
        }

        return DB::transaction(function () use ($delivery, $courier, $amount, $reason) {
            $delivery->forceFill(['discount' => round($amount, 2), 'discount_reason' => $reason, 'discount_by' => $courier->id])->save();
            // An order not yet handed over is charged the lower fee.
            $order = $delivery->order;
            if ($order && $order->isOpen()) {
                $order->forceFill([
                    'delivery_fee' => $delivery->final_fee,
                    'total' => round((float) $order->subtotal - (float) $order->discount + $delivery->final_fee, 2),
                ])->save();
            }
            $this->audit->event('delivery.discount', __('Delivery :number: fee lowered by :amount', ['number' => $delivery->number, 'amount' => number_format($amount, 2)]), $delivery, ['reason' => $reason]);

            return $delivery->fresh(['courier', 'order', 'sale']);
        });
    }

    public function markDelivered(Delivery $delivery, User $courier, ?string $note = null): Delivery
    {
        $this->assertCourier($delivery, $courier, allowStaff: true);
        if (! $delivery->isOpen()) {
            throw new BusinessException(__('This delivery is :status.', ['status' => __($delivery->status)]));
        }
        $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'note' => $note ?? $delivery->note])->save();
        $this->tellCustomer($delivery, 'delivered');

        return $delivery->fresh(['courier', 'order', 'sale']);
    }

    public function fail(Delivery $delivery, User $courier, string $reason): Delivery
    {
        $this->assertCourier($delivery, $courier, allowStaff: true);
        if (! $delivery->isOpen()) {
            throw new BusinessException(__('This delivery is :status.', ['status' => __($delivery->status)]));
        }
        $delivery->forceFill(['status' => 'failed', 'failed_reason' => $reason])->save();
        $this->notifier->notify(new StaffNotice(
            type: 'delivery_failed', title: 'Delivery :number could not be completed', body: ':reason',
            params: ['number' => $delivery->number, 'reason' => $reason], level: 'warning', url: "/admin/deliveries/{$delivery->id}",
            branchId: $delivery->branch_id, permission: 'deliveries.assign',
        ));

        return $delivery->fresh(['courier', 'order', 'sale']);
    }

    public function cancel(Delivery $delivery, string $reason): Delivery
    {
        if (! $delivery->isOpen()) {
            throw new BusinessException(__('This delivery is :status.', ['status' => __($delivery->status)]));
        }
        $delivery->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'failed_reason' => $reason])->save();

        return $delivery->fresh(['courier', 'order', 'sale']);
    }

    /** The customer confirms the goods arrived. */
    public function markReceived(Delivery $delivery): Delivery
    {
        if (! in_array($delivery->status, ['on_the_way', 'delivered'], true)) {
            throw new BusinessException(__('This delivery is not on its way yet.'));
        }
        $delivery->forceFill(['status' => 'received', 'received_at' => now(), 'delivered_at' => $delivery->delivered_at ?? now()])->save();
        if ($delivery->assigned_to) {
            $this->notifier->notify(new StaffNotice(
                type: 'delivery_received', title: ':customer received delivery :number', params: ['customer' => $delivery->customer_name, 'number' => $delivery->number],
                level: 'success', url: "/admin/deliveries/{$delivery->id}", userId: $delivery->assigned_to,
            ));
        }

        return $delivery->fresh();
    }

    public function postMessage(Delivery $delivery, string $sender, ?User $user, string $body): DeliveryMessage
    {
        if (in_array($delivery->status, ['cancelled'], true)) {
            throw new BusinessException(__('This delivery was cancelled.'));
        }
        $message = $delivery->messages()->create(['sender' => $sender, 'user_id' => $user?->id, 'body' => trim($body)]);
        if ($sender === 'customer' && $delivery->assigned_to) {
            $this->notifier->notify(new StaffNotice(
                type: 'delivery_message', title: 'Message from :customer', body: ':body',
                params: ['customer' => $delivery->customer_name, 'body' => Str::limit($body, 120)], url: "/admin/deliveries/{$delivery->id}",
                userId: $delivery->assigned_to, dedupeKey: "delivery_message:{$delivery->id}", dedupeHours: 0,
            ));
        }

        return $message;
    }

    /** Messages, marking the other side's ones as read. */
    public function messages(Delivery $delivery, string $reader, int $afterId = 0): array
    {
        $delivery->messages()->where('sender', '!=', $reader)->whereNull('read_at')->update(['read_at' => now()]);

        return $delivery->messages()->with('user:id,name')->where('id', '>', $afterId)->limit(200)->get()
            ->map(fn (DeliveryMessage $m) => ['id' => $m->id, 'sender' => $m->sender, 'name' => $m->sender === 'staff' ? $m->user?->name : $delivery->customer_name, 'body' => $m->body, 'read' => $m->read_at !== null, 'created_at' => $m->created_at?->toIso8601String()])
            ->all();
    }

    public function trackingLink(Delivery $delivery): string
    {
        return rtrim((string) config('mauzo.frontend_url'), '/').'/t/'.$delivery->public_token;
    }

    // ------------------------------------------------------------------ helpers

    private function assertCourier(Delivery $delivery, User $user, bool $allowStaff = false): void
    {
        if ($delivery->assigned_to === $user->id) {
            return;
        }
        if ($allowStaff && $user->hasPermission('deliveries.assign')) {
            return;
        }
        throw new BusinessException(__('This delivery is assigned to someone else.'), 'FORBIDDEN', 403);
    }

    private function tellCourier(Delivery $delivery): void
    {
        $this->notifier->notify(new StaffNotice(
            type: 'delivery_assigned',
            title: 'New delivery :number for :customer',
            body: ':address',
            params: ['number' => $delivery->number, 'customer' => $delivery->customer_name, 'address' => $delivery->address ?? '-'],
            level: 'info',
            url: "/admin/deliveries/{$delivery->id}",
            userId: $delivery->assigned_to,
        ));
    }

    /** Tell the customer (SMS / WhatsApp / email as set for delivery updates), with the tracking link. */
    private function tellCustomer(Delivery $delivery, string $step): void
    {
        $channels = Channels::forType('customer_delivery');
        if (! $channels) {
            return;
        }
        Afterwards::run(function () use ($delivery, $step, $channels) {
            $delivery->loadMissing(['courier', 'customer']);
            $message = $this->messenger->inMessageLocale(function () use ($delivery, $step) {
                $b = (string) $this->settings->get('business_name');
                $p = ['business' => $b, 'number' => $delivery->number, 'name' => $delivery->courier?->name ?? '-', 'phone' => $delivery->courier?->phone ?? '', 'link' => $this->trackingLink($delivery), 'fee' => number_format($delivery->final_fee)];
                $text = match ($step) {
                    'assigned' => __(':business: :name will deliver your goods (:number). Follow and chat here: :link', $p),
                    'on_the_way' => __(':business: :name is on the way with your goods. Track them: :link', $p),
                    default => __(':business: your goods have been delivered. Please confirm you received them: :link', $p),
                };
                $doc = ['title' => __('Delivery :number', $p), 'intro' => [$text], 'meta' => array_filter([__('Delivery person') => $p['name'], __('Phone') => $p['phone'], __('Delivery fee') => $p['fee']])];

                return new OutgoingMessage('delivery', $text, $text, __('Delivery :number', $p).' · '.$b, $doc, "delivery-{$delivery->number}.pdf", $delivery->getMorphClass(), $delivery->id);
            });
            $this->messenger->send($message, $channels, Recipients::person($delivery->contact_phone, $delivery->customer?->email));
        });
    }
}

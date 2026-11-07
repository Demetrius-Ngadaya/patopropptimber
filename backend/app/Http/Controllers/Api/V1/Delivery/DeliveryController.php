<?php

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Delivery\Delivery;
use App\Models\Sales\Order;
use App\Models\Sales\Sale;
use App\Services\Delivery\DeliveryFees;
use App\Services\Delivery\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deliveries for staff. Delivery people (deliveries.own) see and act on their
 * own; deliveries.view / deliveries.assign see the branch's.
 */
class DeliveryController extends Controller
{
    public function __construct(private readonly DeliveryService $deliveries) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $this->visible($request)
            ->with(['courier:id,name,phone', 'order:id,number,status,total', 'sale:id,number,total', 'branch:id,name'])
            ->withCount(['messages as unread_messages' => fn ($q) => $q->where('sender', 'customer')->whereNull('read_at')])
            ->when($request->query('status') === 'open', fn ($q) => $q->whereIn('status', Delivery::OPEN))
            ->when($request->query('status') && $request->query('status') !== 'open', fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->boolean('mine') || ! $user->hasAnyPermission(['deliveries.view', 'deliveries.assign']), fn ($q) => $q->where('assigned_to', $user->id))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('customer_name', 'like', "%{$t}%")->orWhere('contact_phone', 'like', "%{$t}%")))
            ->orderByRaw("CASE status WHEN 'on_the_way' THEN 0 WHEN 'assigned' THEN 1 WHEN 'delivered' THEN 2 ELSE 3 END")
            ->latest('id');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function couriers(Request $request): JsonResponse
    {
        return $this->ok($this->deliveries->couriers($this->branch()->id())->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone,
            'open_deliveries' => Delivery::query()->where('assigned_to', $u->id)->whereIn('status', Delivery::OPEN)->count(),
        ])->values());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $delivery = $this->find($request, $id)->load(['courier:id,name,phone', 'order.items:id,order_id,product_name,quantity,line_total', 'sale.items:id,sale_id,product_name,quantity,line_total', 'branch:id,name,latitude,longitude,phone']);
        $origin = app(DeliveryFees::class)->origin($delivery->branch);

        return $this->ok($delivery->toArray() + [
            'trail' => $delivery->trail ?? [],
            'origin' => $origin,
            'tracking_url' => $this->deliveries->trackingLink($delivery),
            'can_act' => $delivery->assigned_to === $request->user()->id || $request->user()->hasPermission('deliveries.assign'),
        ]);
    }

    /** Give an order or a sale to a delivery person. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required_without:sale_id', 'nullable', 'integer', 'exists:orders,id'],
            'sale_id' => ['required_without:order_id', 'nullable', 'integer', 'exists:sales,id'],
            'courier_id' => ['required', 'integer', 'exists:users,id'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $source = ! empty($data['order_id'])
            ? $this->branch()->scope(Order::query())->findOrFail($data['order_id'])
            : $this->branch()->scope(Sale::query())->findOrFail($data['sale_id']);
        if ($source instanceof Order && $source->delivery_method !== 'delivery' && empty($data['address'])) {
            throw new BusinessException(__('This order is for pickup. Enter an address to deliver it anyway.'));
        }
        $courier = User::query()->findOrFail($data['courier_id']);
        $delivery = $this->deliveries->assign($source, $courier, $request->user(), $data);

        return $this->created($delivery, __('Delivery :number given to :name.', ['number' => $delivery->number, 'name' => $courier->name]));
    }

    public function start(Request $request, int $id): JsonResponse
    {
        return $this->ok($this->deliveries->start($this->find($request, $id), $request->user()), __('On the way. Keep this page open so the customer can follow you.'));
    }

    public function location(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'accuracy' => ['nullable', 'numeric', 'min:0']]);
        $this->deliveries->updateLocation($this->find($request, $id), $request->user(), (float) $data['latitude'], (float) $data['longitude'], isset($data['accuracy']) ? (int) $data['accuracy'] : null);

        return $this->ok(null);
    }

    public function discount(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0'], 'reason' => ['nullable', 'string', 'max:255']]);
        $delivery = $this->deliveries->discount($this->find($request, $id), $request->user(), (float) $data['amount'], $data['reason'] ?? null);

        return $this->ok($delivery, __('Delivery fee is now :fee.', ['fee' => number_format($delivery->final_fee)]));
    }

    public function delivered(Request $request, int $id): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->ok($this->deliveries->markDelivered($this->find($request, $id), $request->user(), $note), __('Marked as delivered. The customer is asked to confirm.'));
    }

    public function fail(Request $request, int $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];

        return $this->ok($this->deliveries->fail($this->find($request, $id), $request->user(), $reason), __('Recorded. The shop has been told.'));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];

        return $this->ok($this->deliveries->cancel($this->find($request, $id), $reason), __('Delivery cancelled.'));
    }

    public function messages(Request $request, int $id): JsonResponse
    {
        return $this->ok($this->deliveries->messages($this->find($request, $id), 'staff', (int) $request->query('after', 0)));
    }

    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $body = $request->validate(['body' => ['required', 'string', 'min:1', 'max:1000']])['body'];
        $message = $this->deliveries->postMessage($this->find($request, $id), 'staff', $request->user(), $body);

        return $this->created(['id' => $message->id], __('Message sent.'));
    }

    /** Fee for a place, e.g. when giving a till sale to a delivery person. */
    public function quote(Request $request, DeliveryFees $fees): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['nullable', 'integer'], 'latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180']]);

        return $this->ok($fees->quote(Branch::query()->find($data['branch_id'] ?? $this->branch()->id()), (float) $data['latitude'], (float) $data['longitude']));
    }

    private function visible(Request $request)
    {
        return $this->branch()->scope(Delivery::query());
    }

    private function find(Request $request, int $id): Delivery
    {
        $user = $request->user();
        $delivery = $this->visible($request)->findOrFail($id);
        if ($delivery->assigned_to !== $user->id && ! $user->hasAnyPermission(['deliveries.view', 'deliveries.assign'])) {
            abort(404);
        }

        return $delivery;
    }
}

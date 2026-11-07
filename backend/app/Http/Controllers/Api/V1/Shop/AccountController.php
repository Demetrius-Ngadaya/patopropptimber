<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Delivery\Delivery;
use App\Models\Payments\OnlinePayment;
use App\Models\Sales\Order;
use App\Services\Core\SettingsService;
use App\Services\Crm\CustomerService;
use App\Services\Payments\OnlinePaymentService;
use App\Services\Payments\SnippeClient;
use App\Services\Sales\OrderService;
use App\Support\UserPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Customer self-service: register, manage profile, place and track orders.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly SettingsService $settings,
    ) {}

    public function register(Request $request): JsonResponse
    {
        if (! $this->settings->bool('shop_enabled') || ! $this->settings->bool('shop_allow_registration')) {
            throw new BusinessException(__('Online registration is currently closed. Please contact the shop.'));
        }

        $request->merge(['phone' => $this->customers->cleanPhone($request->input('phone'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')],
            'email' => ['nullable', 'email:rfc', 'max:120', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'accept_terms' => ['accepted'],
        ], [
            'phone.unique' => __('An account with this phone number already exists. Please log in.'),
            'email.unique' => __('An account with this email already exists. Please log in.'),
            'accept_terms.accepted' => __('Please accept the terms to create an account.'),
        ]);

        $user = $this->customers->register($data);
        $token = $user->createToken(Str::limit($request->userAgent() ?: 'shop', 100, ''), ['*'], now()->addDays(30));
        $token->accessToken->forceFill(['ip_address' => $request->ip(), 'last_activity_at' => now()])->save();

        return $this->created([
            'token' => $token->plainTextToken,
            'user' => UserPayload::make($user),
        ], __('Welcome! Your account has been created.'));
    }

    public function profile(Request $request): JsonResponse
    {
        return $this->ok(UserPayload::make($request->user()) + [
            'profile' => $request->user()->customer->only(['code', 'name', 'phone', 'alt_phone', 'email', 'address', 'city', 'balance', 'sms_opt_in']),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $customer = $user->customer;
        if ($request->filled('phone')) {
            $request->merge(['phone' => $this->customers->cleanPhone($request->input('phone'))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($user->id)],
            'alt_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'sms_opt_in' => ['boolean'],
        ]);

        $user->fill(array_intersect_key($data, array_flip(['name', 'phone', 'email'])))->save();
        $customer->fill($data)->save();

        return $this->ok(UserPayload::make($user->fresh()), __('Your profile has been updated.'));
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('customer_id', $request->user()->customer->id)
            ->with(['branch:id,name'])
            ->withCount('items')
            ->latest()
            ->paginate(15);

        return $this->paginated($orders);
    }

    public function order(Request $request, string $number): JsonResponse
    {
        $order = Order::query()
            ->where('customer_id', $request->user()->customer->id)
            ->where('number', $number)
            ->with(['items.product:id,name,slug,image_path', 'branch:id,name,phone,address', 'histories:id,order_id,to_status,note,created_at'])
            ->firstOrFail();

        $delivery = Delivery::query()->where('order_id', $order->id)->where('status', '!=', 'cancelled')->latest('id')->first();

        return $this->ok($order->makeHidden(['staff_notes', 'assigned_to', 'created_by', 'updated_by', 'deleted_by'])->toArray() + [
            // The tracking page (map, chat, "received") is opened with this token.
            'delivery' => $delivery ? ['number' => $delivery->number, 'status' => $delivery->status, 'tracking_token' => $delivery->public_token] : null,
        ]);
    }

    public function placeOrder(Request $request, OrderService $orders, OnlinePaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'delivery_method' => ['required', Rule::in(['pickup', 'delivery'])],
            'delivery_address' => ['nullable', 'required_if:delivery_method,delivery', 'string', 'max:255'],
            'delivery_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:delivery_longitude'],
            'delivery_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:delivery_latitude'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'customer_notes' => ['nullable', 'string', 'max:1000'],
            'pay_online' => ['nullable', 'boolean'],
            'payment_phone' => ['nullable', 'required_if_accepted:pay_online', 'string', 'max:20'],
        ], ['delivery_address.required_if' => __('Enter the address where we should deliver.'), 'payment_phone.required_if_accepted' => __('Enter the mobile money number that will pay.')]);

        $payOnline = (bool) ($data['pay_online'] ?? false);
        if ($payOnline) {
            // Check before the order exists, so a switched-off option does not leave an unpaid order behind.
            $payments->assertAvailable('order');
            if (! SnippeClient::normalizePhone($data['payment_phone'])) {
                throw ValidationException::withMessages(['payment_phone' => __('Enter a Tanzanian mobile number, e.g. 0712 345 678.')]);
            }
        }
        unset($data['pay_online'], $data['payment_phone']);

        $order = $orders->place($request->user()->customer, $data, 'online');
        if (! $payOnline) {
            return $this->created($order, __('Thank you! Your order :order has been received. We will contact you to confirm.', ['order' => $order->number]));
        }

        // The order stays even if the push fails: the customer can try again from the order page or pay on delivery.
        $payment = null;
        $error = null;
        try {
            $payment = $this->requestOrderPayment($payments, $order, $request->input('payment_phone'));
        } catch (BusinessException $e) {
            $error = $e->getMessage();
        }

        return $this->created($order->toArray() + ['online_payment' => $payment, 'payment_error' => $error], $error
            ? __('Your order :order has been received, but the payment request did not go through: :error', ['order' => $order->number, 'error' => $error])
            : __('Your order :order has been received. Check your phone and enter your PIN to pay.', ['order' => $order->number]));
    }

    /** The order's latest payment by phone (checked with the gateway while waiting). */
    public function orderPayment(Request $request, string $number, OnlinePaymentService $payments): JsonResponse
    {
        $order = $this->customerOrder($request, $number);
        $latest = OnlinePayment::query()->where('order_id', $order->id)->latest('id')->first();
        if ($latest) {
            $latest = $payments->refresh($latest);
        }
        $order->refresh();

        return $this->ok([
            'payment' => $latest?->only(['number', 'status', 'amount', 'phone', 'provider_name', 'failure_reason', 'completed_at', 'expires_at']),
            'paid_online' => (float) $order->paid_online,
            'total' => (float) $order->total,
            'to_pay' => max(0, round((float) $order->total - (float) $order->paid_online, 2)),
            'can_pay' => $this->canPay($order, $payments),
        ]);
    }

    /** Pay (or try again) by phone for an order. */
    public function payOrder(Request $request, string $number, OnlinePaymentService $payments): JsonResponse
    {
        $order = $this->customerOrder($request, $number);
        $phone = $request->validate(['phone' => ['required', 'string', 'max:20']])['phone'];
        if (! $this->canPay($order, $payments)) {
            throw new BusinessException(__('This order cannot be paid online now.'));
        }
        $waiting = OnlinePayment::query()->where('order_id', $order->id)->where('status', 'pending')->latest('id')->first();
        if ($waiting && ($waiting = $payments->refresh($waiting, true))->status === 'pending' && $waiting->created_at->gt(now()->subMinutes(2))) {
            throw new BusinessException(__('A payment request was just sent. Check your phone, or wait a moment before trying again.'));
        }
        $order->refresh();
        if ((float) $order->total - (float) $order->paid_online <= 0.004) {
            throw new BusinessException(__('This order is already paid.'));
        }

        $payment = $this->requestOrderPayment($payments, $order, $phone);

        return $this->created($payment->only(['number', 'status', 'amount', 'phone']), __('Check your phone and enter your PIN to pay.'));
    }

    private function requestOrderPayment(OnlinePaymentService $payments, Order $order, string $phone): OnlinePayment
    {
        return $payments->request([
            'purpose' => 'order',
            'amount' => round((float) $order->total - (float) $order->paid_online, 2),
            'phone' => $phone,
            'payer_name' => $order->customer?->name,
            'customer' => $order->customer,
            'order' => $order,
            'branch_id' => (int) $order->branch_id,
        ]);
    }

    private function canPay(Order $order, OnlinePaymentService $payments): bool
    {
        return $payments->availability()['shop'] && $order->isOpen() && (float) $order->total - (float) $order->paid_online > 0.004;
    }

    private function customerOrder(Request $request, string $number): Order
    {
        return Order::query()->where('customer_id', $request->user()->customer->id)->where('number', $number)->firstOrFail();
    }

    public function cancelOrder(Request $request, string $number, OrderService $orders): JsonResponse
    {
        $order = Order::query()->where('customer_id', $request->user()->customer->id)->where('number', $number)->firstOrFail();
        if ($order->status !== 'pending') {
            throw new BusinessException(__('Only orders that are not yet confirmed can be cancelled online. Please call the shop.'));
        }
        if ((float) $order->paid_online > 0) {
            throw new BusinessException(__('This order is already paid. Please call the shop to cancel it and get your money back.'));
        }
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? 'Cancelled by customer';

        return $this->ok($orders->cancel($order, $reason), __('Your order has been cancelled.'));
    }
}

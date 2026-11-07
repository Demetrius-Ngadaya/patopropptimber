<?php

namespace Tests\Feature;

use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Payments\OnlinePayment;
use App\Models\Sales\Order;
use App\Services\Core\SettingsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnlinePaymentsTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mauzo.snippe.api_key' => 'snp_test_key',
            'mauzo.snippe.webhook_secret' => self::SECRET,
            'mauzo.snippe.base_url' => 'https://api.snippe.test',
            'app.url' => 'https://api.shop.test',
        ]);
    }

    private function switchOn(bool $pos = true, bool $shop = true): void
    {
        app(SettingsService::class)->update(['online_payments_enabled' => true, 'online_payments_pos' => $pos, 'online_payments_shop' => $shop]);
    }

    /** Snippe: every new payment gets its own reference; status reads return $status. */
    private function fakeSnippe(string $status = 'pending'): void
    {
        Http::fake([
            'api.snippe.test/v1/payments/balance' => Http::response(['status' => 'success', 'code' => 200, 'data' => ['available' => ['currency' => 'TZS', 'value' => 6943], 'balance' => ['currency' => 'TZS', 'value' => 6943]]]),
            'api.snippe.test/v1/payments/*' => fn (HttpRequest $r) => Http::response(['status' => 'success', 'code' => 200, 'data' => [
                'reference' => basename($r->url()), 'status' => $status,
                'channel' => ['provider' => 'airtel', 'type' => 'mobile_money'], 'completed_at' => $status === 'completed' ? now()->toIso8601String() : null,
            ]]),
            'api.snippe.test/v1/payments' => function (HttpRequest $r) {
                $this->seq++;

                return Http::response(['status' => 'success', 'code' => 201, 'data' => [
                    'amount' => ['currency' => 'TZS', 'value' => $r['details']['amount']], 'reference' => "ref-{$this->seq}",
                    'status' => 'pending', 'expires_at' => now()->addHours(4)->toIso8601String(), 'object' => 'payment', 'payment_type' => 'mobile',
                ]], 201);
            },
        ]);
    }

    private function webhook(array $event, ?string $secret = self::SECRET, ?int $time = null)
    {
        $body = json_encode($event);
        $ts = (string) ($time ?? time());

        return $this->call('POST', '/api/v1/webhooks/snippe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_EVENT' => $event['type'],
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$body, (string) $secret),
        ], $body);
    }

    private function completed(string $reference, float $amount, string $id = 'evt_1'): array
    {
        return ['id' => $id, 'type' => 'payment.completed', 'api_version' => '2026-01-25', 'data' => [
            'reference' => $reference, 'external_reference' => 'MP2610ABCD', 'status' => 'completed',
            'amount' => ['value' => $amount, 'currency' => 'TZS'],
            'settlement' => ['gross' => ['value' => $amount], 'fees' => ['value' => 150], 'net' => ['value' => $amount - 150]],
            'channel' => ['type' => 'mobile_money', 'provider' => 'mpesa'], 'completed_at' => now()->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
        ]];
    }

    public function test_till_takes_payment_by_phone_after_the_gateway_confirms(): void
    {
        $this->fakeSnippe();
        $product = $this->productWithStock(10, ['selling_price' => 5000]);
        $cashier = $this->staff('cashier');
        Sanctum::actingAs($cashier);

        // Switched off: not offered, and refused.
        $this->getJson('/api/v1/admin/lookups?include=payment')->assertOk()->assertJsonPath('data.payment.online', false);
        $this->postJson('/api/v1/admin/online-payments', ['amount' => 10000, 'phone' => '0754123456'])->assertUnprocessable();

        $this->switchOn(pos: true, shop: false);
        $this->getJson('/api/v1/admin/lookups?include=payment')->assertJsonPath('data.payment.online', true);
        $this->postJson('/api/v1/admin/online-payments', ['amount' => 10000.5, 'phone' => '0754123456'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/online-payments', ['amount' => 10000, 'phone' => '12345'])->assertUnprocessable();

        $payment = $this->postJson('/api/v1/admin/online-payments', ['amount' => 10000, 'phone' => '0754 123 456', 'payer_name' => 'Asha Mohamed'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.phone', '255754123456')->json('data');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.snippe.test/v1/payments'
            && $r->hasHeader('Authorization', 'Bearer snp_test_key')
            && strlen($r->header('Idempotency-Key')[0]) <= 30
            && $r['details']['amount'] === 10000 && $r['phone_number'] === '255754123456'
            && $r['customer']['firstname'] === 'Asha' && $r['webhook_url'] === 'https://api.shop.test/api/v1/webhooks/snippe');

        // Not paid yet: the sale is refused.
        $sale = ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'payments' => [['method' => 'online', 'amount' => 10000, 'online_payment_id' => $payment['id']]]];
        $this->postJson('/api/v1/admin/pos/checkout', $sale)->assertUnprocessable();

        // A forged or stale webhook changes nothing.
        $this->webhook($this->completed($payment['reference'], 10000), 'wrong-secret')->assertStatus(401);
        $this->webhook($this->completed($payment['reference'], 10000, 'evt_old'), self::SECRET, time() - 900)->assertStatus(401);
        $this->assertSame('pending', OnlinePayment::find($payment['id'])->status);

        $this->webhook($this->completed($payment['reference'], 10000))->assertOk();
        $this->webhook($this->completed($payment['reference'], 10000))->assertOk()->assertJsonPath('result', 'duplicate');
        $this->getJson("/api/v1/admin/online-payments/{$payment['id']}")->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.provider_name', 'M-Pesa')->assertJsonPath('data.is_usable', true);

        $created = $this->postJson('/api/v1/admin/pos/checkout', $sale)->assertCreated()->json('data');
        $this->assertSame('paid', $created['payment_status']);
        $this->assertDatabaseHas('payments', ['sale_id' => $created['id'], 'method' => 'online', 'provider' => 'M-Pesa', 'reference' => 'MP2610ABCD', 'payer_phone' => '255754123456']);
        $this->assertSame($created['id'], OnlinePayment::find($payment['id'])->sale_id);
        // The gateway's UTC time is kept as local time.
        $this->assertLessThan(120, abs(OnlinePayment::find($payment['id'])->completed_at->diffInSeconds(now())));
        $this->assertLedgerBalanced();

        // Used once only.
        $this->postJson('/api/v1/admin/pos/checkout', $sale)->assertUnprocessable();
        // Another amount than what was paid is refused.
        $other = $this->postJson('/api/v1/admin/online-payments', ['amount' => 5000, 'phone' => '0754123456'])->json('data');
        $this->webhook($this->completed($other['reference'], 5000, 'evt_2'))->assertOk();
        $this->postJson('/api/v1/admin/pos/checkout', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'payments' => [['method' => 'online', 'amount' => 10000, 'online_payment_id' => $other['id']]]])->assertUnprocessable();
    }

    public function test_status_check_with_the_gateway_when_no_webhook_arrives(): void
    {
        $this->switchOn();
        $this->fakeSnippe('failed');
        Sanctum::actingAs($this->staff('cashier'));

        $payment = $this->postJson('/api/v1/admin/online-payments', ['amount' => 2000, 'phone' => '+255 712 000 111'])->json('data');
        $this->getJson("/api/v1/admin/online-payments/{$payment['id']}?force=1")->assertOk()->assertJsonPath('data.status', 'failed');

        // The cashier stops waiting; a late completion is still recorded as received but unused.
        $second = $this->postJson('/api/v1/admin/online-payments', ['amount' => 2000, 'phone' => '0712000111'])->json('data');
        $this->postJson("/api/v1/admin/online-payments/{$second['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->webhook($this->completed($second['reference'], 2000, 'evt_late'))->assertOk();
        $this->assertSame('completed', OnlinePayment::find($second['id'])->status);

        Sanctum::actingAs($this->staff('accountant'));
        $this->getJson('/api/v1/admin/online-payments?status=unused')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.unused', 2000);
        $this->getJson('/api/v1/admin/reports/online_payments')->assertOk()->assertJsonPath('report.summary.received', 2000);
    }

    public function test_shop_customer_pays_order_by_phone_and_staff_completes_it(): void
    {
        $this->fakeSnippe('completed');
        $product = $this->productWithStock(10, ['selling_price' => 2000, 'show_online' => true]);
        $user = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $user->id, 'name' => 'Juma Ali', 'phone' => '255713000222']);
        Sanctum::actingAs($user);
        $place = ['branch_id' => $this->mainBranch()->id, 'items' => [['product_id' => $product->id, 'quantity' => 3]], 'delivery_method' => 'pickup', 'pay_online' => true, 'payment_phone' => '0713000222'];

        // Switched off for the shop: refused before any order is created.
        $this->switchOn(pos: true, shop: false);
        $this->getJson('/api/v1/public/settings')->assertJsonPath('data.online_payments_shop', false);
        $this->postJson('/api/v1/shop/account/orders', $place)->assertUnprocessable();
        $this->assertSame(0, Order::count());

        $this->switchOn(pos: false, shop: true);
        $this->getJson('/api/v1/public/settings')->assertJsonPath('data.online_payments_shop', true);
        $order = $this->postJson('/api/v1/shop/account/orders', $place)->assertCreated()
            ->assertJsonPath('data.online_payment.status', 'pending')->assertJsonPath('data.payment_error', null)->json('data');

        // The order page asks for the status: the gateway says it is paid.
        $this->travel(10)->seconds();
        $this->getJson("/api/v1/shop/account/orders/{$order['number']}/payment")->assertOk()
            ->assertJsonPath('data.payment.status', 'completed')->assertJsonPath('data.paid_online', 6000)->assertJsonPath('data.to_pay', 0)->assertJsonPath('data.can_pay', false);
        $this->postJson("/api/v1/shop/account/orders/{$order['number']}/cancel")->assertUnprocessable();

        // At the shop the money paid online counts; nothing more to collect.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->staff('super-admin'));
        $this->getJson("/api/v1/admin/orders/{$order['id']}")->assertOk()->assertJsonPath('data.paid_online', '6000.00')->assertJsonCount(1, 'data.online_payments');
        $this->postJson("/api/v1/admin/orders/{$order['id']}/complete", ['payments' => []])->assertOk()->assertJsonPath('data.status', 'completed');

        $sale = Order::find($order['id'])->sale;
        $this->assertSame('paid', $sale->payment_status);
        $this->assertDatabaseHas('payments', ['sale_id' => $sale->id, 'method' => 'online', 'amount' => 6000]);
        $this->assertSame(7.0, $this->onHand($product));
        $this->assertLedgerBalanced();
    }

    public function test_cancelling_an_order_paid_online_warns_staff_to_refund(): void
    {
        $this->switchOn();
        $this->fakeSnippe();
        $product = $this->productWithStock(10, ['selling_price' => 1500, 'show_online' => true]);
        $user = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        $order = $this->postJson('/api/v1/shop/account/orders', ['branch_id' => $this->mainBranch()->id, 'items' => [['product_id' => $product->id, 'quantity' => 2]], 'delivery_method' => 'pickup', 'pay_online' => true, 'payment_phone' => '0688111222'])->json('data');
        $this->webhook($this->completed($order['online_payment']['reference'] ?? OnlinePayment::first()->reference, 3000))->assertOk();
        $this->assertSame('3000.00', Order::find($order['id'])->paid_online);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin = $this->staff('super-admin'));
        $this->postJson("/api/v1/admin/orders/{$order['id']}/status", ['status' => 'cancelled', 'note' => 'Out of stock'])->assertOk();
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order['id'], 'to_status' => 'cancelled', 'note' => 'Paid online 3,000: refund the customer.']);

        // Settings page: set up, and the key works.
        $this->getJson('/api/v1/admin/online-payments/status?test=1')->assertOk()
            ->assertJsonPath('data.configured', true)->assertJsonPath('data.test.ok', true)->assertJsonPath('data.balance.available', 6943)
            ->assertJsonPath('data.webhook_url', 'https://api.shop.test/api/v1/webhooks/snippe');
    }
}

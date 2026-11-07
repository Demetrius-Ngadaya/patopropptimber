<?php

namespace Tests\Feature;

use App\Models\Core\AppNotification;
use App\Models\Core\PersonalAccessToken;
use App\Models\Crm\Customer;
use App\Models\Mobile\MobileDevice;
use App\Models\Mobile\SyncMutation;
use App\Models\Sales\Sale;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileAppTest extends TestCase
{
    private const EXPO = 'https://exp.host/--/api/v2/push/send';

    private function register(array $overrides = []): array
    {
        $body = array_replace_recursive([
            'installation_id' => (string) Str::uuid(),
            'platform' => 'android',
            'push_token' => 'ExponentPushToken[abc'.Str::random(8).']',
            'device_name' => 'Pixel 8',
            'app_version' => '1.0.0',
            'locale' => 'en',
            'preferences' => [
                'categories' => ['orders' => ['enabled' => true, 'voice' => false, 'sound' => true, 'vibrate' => true]],
                'quiet_hours' => ['enabled' => false],
            ],
        ], $overrides);
        $this->postJson('/api/v1/admin/mobile/devices', $body)->assertOk()->assertJsonPath('data.has_push_token', true);

        return $body;
    }

    private function newOrderNotification(): AppNotification
    {
        return AppNotification::create([
            'type' => 'new_order', 'level' => 'success', 'title' => 'New online order :number', 'body' => ':customer ordered.',
            'params' => ['number' => 'ORD-7', 'customer' => 'Asha'], 'url' => '/admin/orders/7', 'permission' => 'orders.view',
        ]);
    }

    public function test_new_order_is_pushed_to_phones_with_each_phones_alert_settings(): void
    {
        Http::fake([self::EXPO => Http::response(['data' => [['status' => 'ok', 'id' => 'x'], ['status' => 'ok', 'id' => 'y']]])]);

        // Admin: sound and vibration, no voice.
        $this->actingAsStaff('admin');
        $admin = $this->register();
        // Cashier on an iPhone with voice on.
        $this->actingAsStaff('cashier');
        $manager = $this->register(['platform' => 'ios', 'preferences' => ['categories' => ['orders' => ['voice' => true, 'sound' => false]]]]);
        // A storekeeper cannot see orders: nothing is sent to them.
        $this->actingAsStaff('storekeeper');
        $this->register();

        $this->newOrderNotification();

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) use ($admin, $manager) {
            $messages = collect($request->data())->keyBy('to');
            $a = $messages[$admin['push_token']] ?? null;
            $m = $messages[$manager['push_token']] ?? null;

            return $messages->count() === 2
                && $a['title'] === 'New online order ORD-7' && $a['channelId'] === 'alert_sound_vibrate' && $a['sound'] === 'default'
                && $a['data']['url'] === '/admin/orders/7' && $a['data']['category'] === 'orders' && $a['data']['speak'] === false
                && $m['channelId'] === 'alert_vibrate' && ! isset($m['sound']) && $m['data']['speak'] === true && $m['_contentAvailable'] === true;
        });
    }

    public function test_android_voice_sends_data_only_message_and_quiet_hours_silence_it(): void
    {
        Http::fake([self::EXPO => Http::response(['data' => [['status' => 'ok']]])]);
        $this->actingAsStaff('admin');
        $device = $this->register(['preferences' => ['categories' => ['orders' => ['voice' => true]]]]);

        $this->newOrderNotification();
        Http::assertSent(fn (HttpRequest $r) => ! isset($r->data()[0]['title'])
            && $r->data()[0]['data']['display'] === true && $r->data()[0]['data']['speak'] === true
            && $r->data()[0]['data']['channel_id'] === 'alert_sound_vibrate' && $r->data()[0]['data']['title'] === 'New online order ORD-7');

        // Quiet hours all day: still delivered, but silent and not spoken.
        MobileDevice::query()->where('installation_id', $device['installation_id'])
            ->update(['preferences' => json_encode(['categories' => ['orders' => ['voice' => true]], 'quiet_hours' => ['enabled' => true, 'from' => '00:00', 'to' => '23:59']])]);
        Http::fake([self::EXPO => Http::response(['data' => [['status' => 'ok']]])]);
        $this->newOrderNotification();
        Http::assertSent(fn (HttpRequest $r) => $r->data()[0]['channelId'] === 'alert_silent' && $r->data()[0]['data']['speak'] === false);
    }

    public function test_disabled_category_is_not_pushed_and_uninstalled_phones_are_forgotten(): void
    {
        $this->actingAsStaff('admin');
        Http::fake([self::EXPO => Http::response(['data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);
        $device = $this->register(['preferences' => ['categories' => ['stock' => ['enabled' => false]]]]);

        AppNotification::create(['type' => 'low_stock', 'level' => 'warning', 'title' => 'Low', 'permission' => 'stock.alerts']);
        Http::assertNothingSent();

        $this->newOrderNotification();
        Http::assertSentCount(1);
        $this->assertNull(MobileDevice::query()->where('installation_id', $device['installation_id'])->value('push_token'));
    }

    public function test_mobile_login_lasts_for_days_and_logout_forgets_the_phone(): void
    {
        $user = $this->staff('cashier', null, ['username' => 'mobi', 'password' => bcrypt('secret-pass-1')]);
        $res = $this->postJson('/api/v1/auth/login', ['login' => 'mobi', 'password' => 'secret-pass-1', 'client' => 'mobile', 'device_name' => 'Tecno Spark'])->assertOk();
        $token = PersonalAccessToken::query()->where('tokenable_id', $user->id)->sole();
        $this->assertTrue($token->isMobile());
        $this->assertTrue($token->expires_at->gt(now()->addDays(29)));

        // Unused for two hours: the web would have timed out (30 min), the app stays signed in.
        $this->travel(2)->hours();
        $headers = ['Authorization' => 'Bearer '.$res->json('data.token')];
        $this->withHeaders($headers)->getJson('/api/v1/admin/mobile/bootstrap')->assertOk()
            ->assertJsonPath('data.user.username', 'mobi')->assertJsonPath('data.notification_categories.orders.voice', true);

        $id = (string) Str::uuid();
        $this->withHeaders($headers)->postJson('/api/v1/admin/mobile/devices', ['installation_id' => $id, 'platform' => 'android', 'push_token' => 'ExponentPushToken[zz]'])->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertFalse(MobileDevice::query()->where('installation_id', $id)->exists());
    }

    public function test_pull_pages_through_changes_and_sends_deletions(): void
    {
        config(['mauzo.mobile.pull_page_size' => 50]);
        $this->actingAsStaff('storekeeper');
        $products = collect(range(1, 60))->map(fn ($i) => $this->productWithStock(5, ['selling_price' => 1000 + $i]));
        Customer::factory()->count(3)->create();

        $first = $this->getJson('/api/v1/admin/mobile/sync/pull')->assertOk()->json('data.entities');
        $this->assertCount(50, $first['products']['rows']);
        $this->assertTrue($first['products']['has_more']);
        $this->assertArrayNotHasKey('cost_price', $first['products']['rows'][0]);
        $this->assertArrayNotHasKey('orders', $first, 'A storekeeper without orders.view gets no orders.');

        $cursors = collect($first)->map(fn ($e) => $e['cursor'])->all();
        $second = $this->getJson('/api/v1/admin/mobile/sync/pull?'.http_build_query(['cursors' => $cursors]))->assertOk()->json('data.entities');
        $this->assertCount(10, $second['products']['rows']);
        $this->assertFalse($second['products']['has_more']);
        $this->assertCount(60, [...$first['stock']['rows'], ...$second['stock']['rows']]);
        $this->assertEquals(5, $second['stock']['rows'][0]['quantity']);

        // A product deleted later comes back as a tombstone.
        $this->travel(2)->minutes();
        $products[0]->delete();
        // Rows changed within the overlap window are sent again; the phone upserts them.
        $cursors = collect($second)->map(fn ($e) => $e['cursor'])->all();
        $rows = [];
        do {
            $page = $this->getJson('/api/v1/admin/mobile/sync/pull?'.http_build_query(['cursors' => $cursors, 'only' => ['products']]))->assertOk()->json('data.entities');
            $this->assertSame(['products'], array_keys($page));
            $rows = [...$rows, ...$page['products']['rows']];
            $cursors['products'] = $page['products']['cursor'];
        } while ($page['products']['has_more']);
        $this->assertContains(['id' => $products[0]->id, 'deleted' => true], $rows);
    }

    public function test_offline_customer_and_sale_are_applied_once_in_order(): void
    {
        $cashier = $this->actingAsStaff('cashier');
        $product = $this->productWithStock(10, ['selling_price' => 2500]);
        $customerChange = (string) Str::uuid();
        $saleChange = (string) Str::uuid();
        $soldAt = now()->subHours(3)->startOfMinute();

        $push = fn () => $this->postJson('/api/v1/admin/mobile/sync/push', ['mutations' => [
            ['id' => $customerChange, 'type' => 'customer.create', 'payload' => ['name' => 'Neema Offline', 'phone' => '0712 000 111'], 'created_at' => $soldAt->toIso8601String()],
            ['id' => $saleChange, 'type' => 'sale.create', 'created_at' => $soldAt->toIso8601String(), 'payload' => [
                'customer_id' => '@ref:'.$customerChange,
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'payments' => [['method' => 'cash', 'amount' => 5000]],
            ]],
        ]])->assertOk()->json('data.results');

        $results = $push();
        $this->assertSame(['applied', 'applied'], array_column($results, 'status'), json_encode($results));
        $this->assertSame(['applied', 'applied'], array_column($results, 'status'));
        $sale = Sale::query()->sole();
        $customer = Customer::query()->where('name', 'Neema Offline')->sole();
        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertSame($cashier->id, $sale->cashier_id);
        $this->assertSame($soldAt->toDateTimeString(), $sale->sold_at->toDateTimeString());
        $this->assertSame($sale->number, $results[1]['data']['number']);
        $this->assertEqualsWithDelta(8, $this->onHand($product), 0.001);

        // The response was lost and the phone sends the same changes again: nothing is doubled.
        $again = $push();
        $this->assertSame(['duplicate', 'duplicate'], array_column($again, 'status'));
        $this->assertSame($sale->id, $again[1]['result_id']);
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(1, Customer::query()->where('name', 'Neema Offline')->count());
        $this->assertEqualsWithDelta(8, $this->onHand($product), 0.001);
        $this->assertLedgerBalanced();

        // The sale comes back down with how it was paid (the phone's "today" card splits cash out).
        $pulled = collect($this->getJson('/api/v1/admin/mobile/sync/pull?'.http_build_query(['only' => ['sales']]))->assertOk()->json('data.entities.sales.rows'))->firstWhere('id', $sale->id);
        $this->assertSame([['method' => 'cash', 'amount' => 5000]], array_map(fn ($p) => ['method' => $p['method'], 'amount' => (int) $p['amount']], $pulled['payments']));
    }

    public function test_rejected_and_conflicting_changes_are_reported_and_not_recorded(): void
    {
        $this->actingAsStaff('admin');
        $product = $this->productWithStock(1, ['selling_price' => 1000]);
        $customer = Customer::factory()->create(['name' => 'Juma']);
        $seenAt = $customer->updated_at->toIso8601String();

        // Someone edits Juma on the web after the phone last synced.
        $this->travel(5)->minutes();
        $customer->update(['name' => 'Juma Ally']);

        $results = $this->postJson('/api/v1/admin/mobile/sync/push', ['mutations' => [
            ['id' => (string) Str::uuid(), 'type' => 'sale.create', 'payload' => ['items' => [['product_id' => $product->id, 'quantity' => 3]], 'payments' => [['method' => 'cash', 'amount' => 3000]]]],
            ['id' => (string) Str::uuid(), 'type' => 'customer.update', 'target_id' => $customer->id, 'base_updated_at' => $seenAt, 'payload' => ['name' => 'Juma Phone']],
            ['id' => (string) Str::uuid(), 'type' => 'sale.create', 'payload' => ['customer_id' => '@ref:'.Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000]]]],
        ]])->assertOk()->json('data.results');

        $this->assertSame('rejected', $results[0]['status']);
        $this->assertSame(422, $results[0]['http_status']);
        $this->assertSame('conflict', $results[1]['status']);
        $this->assertSame('rejected', $results[2]['status']);
        $this->assertSame('DEPENDENCY', $results[2]['code']);
        $this->assertSame('Juma Ally', $customer->fresh()->name);
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, SyncMutation::query()->count());
    }

    public function test_changes_still_need_the_users_own_permissions(): void
    {
        $this->actingAsStaff('storekeeper');
        $results = $this->postJson('/api/v1/admin/mobile/sync/push', ['mutations' => [
            ['id' => (string) Str::uuid(), 'type' => 'order.status', 'target_id' => 999, 'payload' => ['status' => 'confirmed']],
        ]])->assertOk()->json('data.results');
        $this->assertSame('rejected', $results[0]['status']);
        $this->assertSame(403, $results[0]['http_status']);

        $this->postJson('/api/v1/admin/mobile/sync/push', ['mutations' => [['id' => (string) Str::uuid(), 'type' => 'product.delete', 'payload' => []]]])
            ->assertStatus(422);
    }
}

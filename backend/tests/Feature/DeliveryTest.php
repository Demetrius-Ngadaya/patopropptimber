<?php

namespace Tests\Feature;

use App\Models\Core\SmsLog;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Delivery\Delivery;
use App\Models\Sales\Order;
use App\Services\Core\SettingsService;
use App\Services\Delivery\DeliveryFees;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    // Dar es Salaam city centre and a point about 5 km away.
    private const SHOP = [-6.8161, 39.2803];

    private const HOME = [-6.7717, 39.2580];

    protected function setUp(): void
    {
        parent::setUp();
        config(['mauzo.sms.enabled' => true, 'mauzo.sms.token' => 't']);
        Http::fake(['*' => Http::response(['status' => 'success'])]);
        app(SettingsService::class)->update([
            'business_latitude' => self::SHOP[0], 'business_longitude' => self::SHOP[1],
            'delivery_base_fee' => 1000, 'delivery_fee_per_km' => 500, 'delivery_min_fee' => 2000, 'delivery_road_factor' => 1.3, 'delivery_round_to' => 100,
            'messaging_channels' => ['sms'], 'message_locale' => 'en',
        ]);
    }

    private function onlineOrder(): array
    {
        $product = $this->productWithStock(10, ['selling_price' => 20000, 'show_online' => true]);
        $customer = Customer::factory()->create(['phone' => '0712345678']);
        $user = User::factory()->customer()->create();
        $customer->forceFill(['user_id' => $user->id])->save();
        Sanctum::actingAs($user->fresh());

        $order = $this->postJson('/api/v1/shop/account/orders', [
            'branch_id' => $this->mainBranch()->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery_method' => 'delivery', 'delivery_address' => 'Mikocheni, near the market',
            'delivery_latitude' => self::HOME[0], 'delivery_longitude' => self::HOME[1],
        ])->assertCreated()->json('data');

        return [$order, $customer, $user];
    }

    public function test_fee_is_worked_out_from_the_distance(): void
    {
        $fees = app(DeliveryFees::class);
        $quote = $fees->quote($this->mainBranch(), self::HOME[0], self::HOME[1]);
        $this->assertTrue($quote['by_distance']);
        $this->assertEqualsWithDelta(7.2, $quote['distance_km'], 0.4);     // ~5.5 km straight × 1.3
        $this->assertSame(0.0, fmod($quote['fee'], 100));                    // rounded to 100
        $this->assertEqualsWithDelta(1000 + 500 * $quote['distance_km'], $quote['fee'], 100);

        $this->postJson('/api/v1/shop/delivery-quote', ['branch_id' => $this->mainBranch()->id, 'latitude' => self::HOME[0], 'longitude' => self::HOME[1]])
            ->assertOk()->assertJsonPath('data.distance_km', $quote['distance_km']);
        $this->assertEquals($quote['fee'], $this->postJson('/api/v1/shop/delivery-quote', ['branch_id' => $this->mainBranch()->id, 'latitude' => self::HOME[0], 'longitude' => self::HOME[1]])->json('data.fee'));

        app(SettingsService::class)->update(['delivery_max_km' => 3]);
        $this->postJson('/api/v1/shop/delivery-quote', ['branch_id' => $this->mainBranch()->id, 'latitude' => self::HOME[0], 'longitude' => self::HOME[1]])
            ->assertStatus(422)->assertJsonPath('code', 'TOO_FAR');
    }

    public function test_order_is_delivered_tracked_discounted_chatted_and_received(): void
    {
        [$order, $customer] = $this->onlineOrder();
        $fee = (float) $order['delivery_fee'];
        $this->assertGreaterThan(2000, $fee);
        $this->assertNotNull(Order::find($order['id'])->delivery_distance_km);

        $courier = $this->staff('delivery', null, ['phone' => '0755111222']);
        Sanctum::actingAs($this->staff('cashier'));
        $this->getJson('/api/v1/admin/deliveries/couriers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $courier->id);
        $delivery = $this->postJson('/api/v1/admin/deliveries', ['order_id' => $order['id'], 'courier_id' => $courier->id])->assertCreated()->json('data');
        $this->assertEqualsWithDelta(self::HOME[0], $delivery['latitude'], 0.0001);
        $token = Delivery::find($delivery['id'])->public_token;
        $this->assertStringContainsString('/t/'.$token, SmsLog::where('purpose', 'delivery')->latest('id')->first()->message);

        // The courier sees it in the bell and in their list; another courier does not.
        Sanctum::actingAs($courier);
        $this->getJson('/api/v1/admin/notifications/summary')->assertJsonPath('data.unread', 1);
        $this->getJson('/api/v1/admin/deliveries')->assertOk()->assertJsonCount(1, 'data');
        $other = $this->staff('delivery');
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/admin/deliveries')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/admin/deliveries/{$delivery['id']}/start")->assertNotFound();

        // On the way: the order follows, the location is shared.
        Sanctum::actingAs($courier);
        $this->postJson("/api/v1/admin/deliveries/{$delivery['id']}/start")->assertOk()->assertJsonPath('data.status', 'on_the_way');
        $this->assertSame('out_for_delivery', Order::find($order['id'])->status);
        $this->postJson("/api/v1/admin/deliveries/{$delivery['id']}/location", ['latitude' => -6.79, 'longitude' => 39.27, 'accuracy' => 12])->assertOk();

        // The customer follows and chats, without logging in.
        $track = $this->getJson("/api/v1/public/deliveries/{$token}")->assertOk()->json('data');
        $this->assertSame([-6.79, 39.27], $track['courier_position']);
        $this->assertSame('0755111222', $track['courier']['phone']);
        $this->postJson("/api/v1/public/deliveries/{$token}/messages", ['body' => 'Gate is blue, call when near'])->assertCreated();
        $msgs = $this->getJson("/api/v1/admin/deliveries/{$delivery['id']}/messages")->assertOk()->json('data');
        $this->assertSame('customer', $msgs[0]['sender']);
        $this->postJson("/api/v1/admin/deliveries/{$delivery['id']}/messages", ['body' => 'Five minutes away'])->assertCreated();
        $this->assertSame('Five minutes away', $this->getJson("/api/v1/public/deliveries/{$token}/messages")->json('data.1.body'));

        // The courier gives a discount: the order is charged less.
        $this->postJson("/api/v1/admin/deliveries/{$delivery['id']}/discount", ['amount' => 1000, 'reason' => 'Regular customer'])->assertOk();
        $this->assertEquals($fee - 1000, (float) Order::find($order['id'])->delivery_fee);
        $this->postJson("/api/v1/admin/deliveries/{$delivery['id']}/discount", ['amount' => $fee + 1])->assertStatus(422);

        $this->postJson("/api/v1/admin/deliveries/{$delivery['id']}/delivered")->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->postJson("/api/v1/public/deliveries/{$token}/received")->assertOk();
        $this->assertSame('received', Delivery::find($delivery['id'])->status);
        $this->getJson("/api/v1/public/deliveries/{$token}")->assertJsonPath('data.courier_position', null); // tracking stops
    }

    public function test_customer_order_page_links_to_tracking_and_staff_need_permission(): void
    {
        [$order, $customer, $user] = $this->onlineOrder();
        $courier = $this->staff('delivery');
        Sanctum::actingAs($this->staff('super-admin'));
        $this->postJson('/api/v1/admin/deliveries', ['order_id' => $order['id'], 'courier_id' => $courier->id])->assertCreated();

        Sanctum::actingAs($user->fresh());
        $this->getJson("/api/v1/shop/account/orders/{$order['number']}")->assertOk()->assertJsonPath('data.delivery.status', 'assigned');

        Sanctum::actingAs($this->staff('storekeeper'));
        $this->getJson('/api/v1/admin/deliveries')->assertForbidden();
        Sanctum::actingAs($this->staff('super-admin'));
        $this->postJson('/api/v1/admin/deliveries', ['order_id' => $order['id'], 'courier_id' => $this->staff('cashier')->id])->assertStatus(422);
    }
}

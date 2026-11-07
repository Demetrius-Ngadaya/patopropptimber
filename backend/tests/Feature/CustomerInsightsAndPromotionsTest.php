<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Crm\Customer;
use App\Models\Sales\Sale;
use App\Services\Core\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerInsightsAndPromotionsTest extends TestCase
{
    /** One completed sale for a customer, dated $daysAgo. */
    private function buy(Customer $customer, float $amount, int $daysAgo): void
    {
        $product = $this->productWithStock(1, ['selling_price' => $amount]);
        $id = $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $customer->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => $amount]],
        ])->assertCreated()->json('data.id');
        DB::table('sales')->where('id', $id)->update(['sold_at' => now()->subDays($daysAgo)]);
    }

    public function test_customers_are_grouped_by_how_they_buy(): void
    {
        Sanctum::actingAs($this->staff('super-admin'));
        app(SettingsService::class)->update(['insights_vip_top_percent' => 25, 'insights_frequent_visits' => 4, 'insights_lost_days' => 60]);
        [$vip, $regular, $fading, $gone, $newbie] = Customer::factory()->count(5)->create()->all();

        foreach ([5, 10, 15, 20, 25] as $d) {
            $this->buy($vip, 100000, $d);
        }          // big spender, frequent
        foreach ([3, 8, 13, 18] as $d) {
            $this->buy($regular, 2000, $d);
        }             // frequent
        foreach ([100, 110, 120, 130] as $d) {
            $this->buy($fading, 3000, $d);
        }        // came often, now once
        $this->buy($fading, 3000, 30);
        $this->buy($gone, 5000, 200);                                                    // stopped coming
        $this->buy($newbie, 1000, 2);                                                    // new

        $res = $this->getJson('/api/v1/admin/customer-insights?per_page=50')->assertOk();
        $by = collect($res->json('data'))->keyBy('customer_id');
        $this->assertContains('vip', $by[$vip->id]['segments']);
        $this->assertContains('frequent', $by[$vip->id]['segments']);
        $this->assertContains('frequent', $by[$regular->id]['segments']);
        $this->assertContains('declining', $by[$fading->id]['segments']);
        $this->assertContains('lost', $by[$gone->id]['segments']);
        $this->assertContains('new', $by[$newbie->id]['segments']);
        $this->assertSame($vip->id, $res->json('best.0.id'));
        $this->assertSame(1, $res->json('counts.lost'));

        $this->getJson('/api/v1/admin/customer-insights?segment=lost')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_promotion_for_a_segment_is_applied_at_the_till_once_per_customer(): void
    {
        Sanctum::actingAs($this->staff('super-admin'));
        [$gone, $other] = Customer::factory()->count(2)->create(['sms_opt_in' => true])->all();
        $this->buy($gone, 5000, 200);
        $this->buy($other, 5000, 3);

        $promo = $this->postJson('/api/v1/admin/promotions', [
            'name' => 'We miss you', 'type' => 'percent', 'value' => 20, 'applies_to' => 'all', 'min_purchase' => 1000,
            'audience' => 'segments', 'segments' => ['lost'], 'starts_on' => today()->toDateString(), 'ends_on' => today()->addWeek()->toDateString(),
            'uses_per_customer' => 1, 'is_active' => true,
        ])->assertCreated()->json('data');

        $this->getJson("/api/v1/admin/promotions/eligible?customer_id={$gone->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.headline', '20% off');
        $this->getJson("/api/v1/admin/promotions/eligible?customer_id={$other->id}")->assertOk()->assertJsonCount(0, 'data');

        $product = $this->productWithStock(5, ['selling_price' => 10000]);
        $quote = $this->postJson('/api/v1/admin/pos/quote', ['customer_id' => $gone->id, 'promotion_id' => $promo['id'], 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertOk();
        $this->assertEquals(8000, $quote->json('data.total'));

        $sale = $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $gone->id, 'promotion_id' => $promo['id'],
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 8000]],
        ])->assertCreated()->json('data');
        $this->assertSame('8000.00', $sale['total']);
        $this->assertSame('2000.00', Sale::find($sale['id'])->promotion_discount);
        $this->assertDatabaseHas('promotion_redemptions', ['promotion_id' => $promo['id'], 'customer_id' => $gone->id, 'amount' => 2000]);
        $this->assertLedgerBalanced();

        // Used once: no longer offered; another customer cannot use it.
        $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $gone->id, 'promotion_id' => $promo['id'],
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 10000]],
        ])->assertStatus(422)->assertJsonPath('code', 'PROMOTION_NOT_AVAILABLE');
    }

    public function test_customers_are_told_about_a_promotion_on_the_chosen_channels(): void
    {
        config(['mauzo.sms.enabled' => true, 'mauzo.sms.token' => 't', 'mail.from.address' => 'shop@example.com']);
        Http::fake(['*' => Http::response(['status' => 'success'])]);
        Mail::fake();
        app(SettingsService::class)->update(['messaging_channels' => ['sms', 'email'], 'message_locale' => 'en']);
        Sanctum::actingAs($this->staff('super-admin'));
        Customer::factory()->create(['phone' => '0711000001', 'email' => 'a@example.com', 'sms_opt_in' => true]);
        Customer::factory()->create(['phone' => '0711000002', 'email' => 'b@example.com', 'sms_opt_in' => false]);
        $promo = $this->postJson('/api/v1/admin/promotions', [
            'name' => 'Weekend deal', 'type' => 'amount', 'value' => 3000, 'applies_to' => 'all', 'audience' => 'all',
            'starts_on' => today()->toDateString(), 'ends_on' => today()->addDays(2)->toDateString(),
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/admin/promotions/{$promo['id']}/preview")->assertOk()->assertJsonPath('data.audience', 1);
        $res = $this->postJson("/api/v1/admin/promotions/{$promo['id']}/notify", ['channels' => ['sms', 'email']])->assertOk();
        $this->assertSame(1, $res->json('data.result.sms.recipients'));
        Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo('a@example.com') && $m->pdf !== null);
        Mail::assertNotSent(NotificationMail::class, fn ($m) => $m->hasTo('b@example.com'));
        $this->assertDatabaseHas('sms_logs', ['channel' => 'sms', 'purpose' => 'promotion', 'recipient' => '255711000001']);
    }
}

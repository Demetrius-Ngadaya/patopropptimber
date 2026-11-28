<?php

namespace Tests\Feature\Platform;

use App\Models\Core\User;
use App\Platform\Models\Business;
use App\Platform\Models\Payment;
use App\Platform\Models\Setting;
use App\Platform\Support\BusinessManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/** Paying for a package: AzamPay (default) or Snippe, callbacks checked with the gateway, reminders. */
class PlatformBillingTest extends PlatformTestCase
{
    private array $headers = ['X-Business' => 'duka-a'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mauzo.sms.enabled' => true,
            'mauzo.sms.token' => 'test-token',
            'patopro.platform.billing.gateways' => ['azampay', 'snippe'],
            'patopro.platform.billing.default' => 'azampay',
            'patopro.platform.billing.customer_choice' => false,
            'patopro.platform.billing.azampay.initiation_url' => 'https://pay.test/initiate',
            'patopro.platform.billing.azampay.status_url' => 'https://pay.test/status',
            'patopro.platform.billing.azampay.merchant_email' => 'billing@shezoni.test',
            'patopro.platform.billing.azampay.user_id' => '77',
            'patopro.platform.billing.azampay.user_status' => 'SHEZON_HOSTING',
            'patopro.platform.billing.azampay.callback_secret' => 'cb-secret',
            'mauzo.snippe.api_key' => 'snp-key',
            'mauzo.snippe.webhook_secret' => 'snp-secret',
            'mauzo.snippe.base_url' => 'https://api.snippe.test',
        ]);
    }

    /** A business on a trial ending in $days days, with its administrator signed in. */
    private function signedIn(string $slug = 'duka-a', int $days = 3): Business
    {
        $business = $this->business($slug, 'Duka '.$slug, 'starter', ['trial_ends_at' => now()->addDays($days), 'phone' => '255712345678']);
        $admin = app(BusinessManager::class)->run($business, fn () => User::query()->where('username', 'admin')->firstOrFail());
        app(BusinessManager::class)->forget();
        Sanctum::actingAs($admin);
        $this->headers = ['X-Business' => $slug];

        return $business;
    }

    private function fakeAzamPay(string $status = 'success'): void
    {
        Http::fake([
            'pay.test/initiate*' => Http::response(['items_azam_pay' => [['externalId' => 'EXT-1']]]),
            'pay.test/status*' => Http::response(['items_azam_pay' => [['externalId' => 'EXT-1', 'status' => $status]]]),
            '*' => Http::response(['status' => 'success']), // SMS
        ]);
    }

    private function pay(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/platform/billing/pay', array_merge([
            'plan' => 'biashara', 'billing_cycle' => 'monthly', 'phone' => '0684 123 456',
        ], $overrides), $this->headers);
    }

    public function test_the_billing_page_shows_packages_and_how_to_pay(): void
    {
        $this->signedIn();

        $data = $this->getJson('/api/v1/platform/billing', $this->headers)->assertOk()->json('data');

        $this->assertCount(5, $data['plans']);
        $this->assertSame('trial', $data['business']['state']);
        $this->assertSame(['azampay'], array_column($data['gateways'], 'key')); // no choice: only the default
        $this->assertContains('Airtel', array_column($data['gateways'][0]['methods'], 'code'));
    }

    public function test_paying_by_azampay_extends_the_package_after_the_gateway_confirms(): void
    {
        $business = $this->signedIn(days: 3);
        $this->fakeAzamPay();

        $started = $this->pay()->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertEquals(35000, $started->json('data.amount'));
        $number = $started->json('data.number');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pay.test/initiate')
            && $r['payment_data'][0]['amount'] === 35000
            && $r['payment_data'][0]['billing_phone'] === '255684123456'
            && $r['payment_data'][0]['payment_method'] === 'Airtel'
            && $r['payment_data'][0]['user_id'] === 77
            && $r['payment_data'][0]['user_status'] === 'SHEZON_HOSTING'
            && $r['payment_data'][0]['mechant_email'] === 'billing@shezoni.test');

        $this->postJson('/api/v1/platform/webhooks/azampay/cb-secret', ['payment_data' => [['externalId' => 'EXT-1']]])->assertOk();

        $payment = Payment::query()->where('number', $number)->firstOrFail();
        $this->assertSame('completed', $payment->status);
        $business->refresh();
        // Paid during the trial: the month starts when the trial ends, so no trial day is lost.
        $this->assertTrue($business->paid_until->isSameDay($business->trial_ends_at->copy()->addMonthNoOverflow()));
        $this->assertSame('biashara', $business->plan->code);
        $this->assertTrue(collect(Http::recorded())->contains(fn ($pair) => str_contains($pair[0]['message'] ?? '', $number)));

        // A second callback changes nothing.
        $this->postJson('/api/v1/platform/webhooks/azampay/cb-secret', ['payment_data' => [['externalId' => 'EXT-1']]])->assertOk();
        $this->assertTrue($business->fresh()->paid_until->equalTo($business->paid_until));
    }

    public function test_a_callback_is_not_trusted_without_the_gateway(): void
    {
        $this->signedIn();
        $this->fakeAzamPay('pending');
        $number = $this->pay()->assertCreated()->json('data.number');

        $this->postJson('/api/v1/platform/webhooks/azampay/cb-secret', ['payment_data' => [['externalId' => 'EXT-1', 'status' => 'success']]])->assertOk();

        $this->assertSame('pending', Payment::query()->where('number', $number)->value('status'));
    }

    public function test_a_read_only_business_can_pay_and_sell_again(): void
    {
        $business = $this->signedIn('duka-c', days: -30);
        $this->fakeAzamPay();
        $this->getJson('/api/v1/business', $this->headers)->assertJsonPath('data.state', 'read_only');

        $number = $this->pay()->assertCreated()->json('data.number');
        $this->getJson("/api/v1/platform/billing/payments/$number", $this->headers)->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.business.state', 'active')
            ->assertJsonPath('data.business.can_write', true);

        // Ended long ago: the new month starts today.
        $this->assertTrue($business->fresh()->paid_until->isSameDay(now()->addMonthNoOverflow()));
    }

    public function test_yearly_costs_ten_months_and_gives_twelve(): void
    {
        $business = $this->signedIn(days: -30);

        Artisan::call('platform:pay', ['business' => 'duka-a', '--plan' => 'professional', '--cycle' => 'yearly', '--note' => 'CRDB 123']);

        $payment = Payment::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('manual', $payment->gateway);
        $this->assertEquals(550000, (float) $payment->amount);
        $this->assertSame(12, $payment->months);
        $this->assertTrue($business->fresh()->paid_until->isSameDay(now()->addYearNoOverflow()));
    }

    public function test_the_software_engineer_chooses_the_gateways(): void
    {
        $this->signedIn();
        $this->fakeAzamPay();

        // Not offered to businesses while they cannot choose.
        $this->pay(['gateway' => 'snippe'])->assertStatus(422)->assertJsonPath('code', 'GATEWAY_NOT_OFFERED');

        Artisan::call('platform:billing', ['--choice' => 'yes']);
        $gateways = $this->getJson('/api/v1/platform/billing', $this->headers)->json('data.gateways');
        $this->assertSame(['azampay', 'snippe'], array_column($gateways, 'key'));

        Artisan::call('platform:billing', ['--disable' => ['azampay'], '--default' => 'snippe']);
        $this->assertSame(['snippe'], array_column($this->getJson('/api/v1/platform/billing', $this->headers)->json('data.gateways'), 'key'));
        $this->assertSame(['snippe'], Setting::get('billing.gateways'));
    }

    public function test_paying_by_snippe_with_a_signed_callback(): void
    {
        $this->signedIn();
        Artisan::call('platform:billing', ['--default' => 'snippe']);
        Http::fake([
            'api.snippe.test/v1/payments/SN-1' => Http::response(['data' => ['reference' => 'SN-1', 'status' => 'completed']]),
            'api.snippe.test/v1/payments' => Http::response(['data' => ['reference' => 'SN-1', 'status' => 'pending']]),
            '*' => Http::response(['status' => 'success']),
        ]);

        $number = $this->pay(['method' => null])->assertCreated()->json('data.number');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/payments') && str_ends_with((string) $r['webhook_url'], '/api/v1/platform/webhooks/snippe'));

        $raw = json_encode(['type' => 'payment.completed', 'data' => ['reference' => 'SN-1', 'metadata' => ['platform_payment' => $number]]]);
        $ts = (string) time();
        $this->call('POST', '/api/v1/platform/webhooks/snippe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', "$ts.$raw", 'snp-secret'),
        ], $raw)->assertOk();

        $this->assertSame('completed', Payment::query()->where('number', $number)->value('status'));

        // A callback with a wrong signature is refused.
        $this->call('POST', '/api/v1/platform/webhooks/snippe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_X_WEBHOOK_SIGNATURE' => 'bad'], $raw)->assertStatus(401);
    }

    public function test_the_network_comes_from_the_number(): void
    {
        $this->assertSame('Airtel', \App\Platform\Billing\AzamPayGateway::detect('255684123456'));
        $this->assertSame('Tigo', \App\Platform\Billing\AzamPayGateway::detect('255714123456'));
        $this->assertSame('Halopesa', \App\Platform\Billing\AzamPayGateway::detect('255624123456'));
        $this->assertNull(\App\Platform\Billing\AzamPayGateway::detect('255754123456')); // M-Pesa

        $this->signedIn();
        $this->fakeAzamPay();
        $this->pay(['phone' => '0754 123 456'])->assertStatus(422)->assertJsonPath('code', 'METHOD_NOT_SUPPORTED');
    }

    public function test_a_callback_without_the_secret_is_ignored(): void
    {
        $this->signedIn();
        $this->fakeAzamPay();
        $number = $this->pay()->assertCreated()->json('data.number');

        $this->postJson('/api/v1/platform/webhooks/azampay/wrong', ['payment_data' => [['externalId' => 'EXT-1']]])->assertNotFound();

        $this->assertSame('pending', Payment::query()->where('number', $number)->value('status'));
    }

    public function test_reminders_go_out_once(): void
    {
        $this->signedIn(days: 7);
        Http::fake(['*' => Http::response(['status' => 'success'])]);
        // "in 7 days" counts whole days from today to the end date.
        Business::query()->update(['trial_ends_at' => now()->addDays(7)->setTime(23, 0)]);

        Artisan::call('platform:reminders');
        Artisan::call('platform:reminders');

        $sms = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]['message'] ?? '', 'PatoPro'));
        $this->assertCount(1, $sms);
        $this->assertStringContainsString('7', $sms->first()[0]['message']);
    }
}

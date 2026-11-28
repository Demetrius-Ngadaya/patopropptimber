<?php

namespace Tests\Feature\Platform;

use App\Platform\Models\Business;
use App\Platform\Models\Signup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Registering a new business online: packages, SMS code, setup, first sign-in. */
class PlatformSignupTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['mauzo.sms.enabled' => true, 'mauzo.sms.token' => 'test-token']);
        Http::fake(['*' => Http::response(['status' => 'success'])]);
    }

    private function details(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Duka la Juma',
            'slug' => 'duka-la-juma',
            'owner_name' => 'Juma Mussa',
            'phone' => '0712 345 678',
            'email' => 'juma@example.com',
            'password' => 'Siri12345',
            'password_confirmation' => 'Siri12345',
            'plan' => 'biashara',
            'accept_terms' => true,
        ], $overrides);
    }

    /** The code from the last SMS sent. */
    private function smsCode(): string
    {
        $last = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_contains($r['message'] ?? '', 'PatoPro'))->last();
        preg_match('/\b(\d{6})\b/', (string) $last['message'], $m);

        return $m[1];
    }

    public function test_the_packages_for_the_pricing_page(): void
    {
        $data = $this->getJson('/api/v1/platform/plans')->assertOk()->json('data');

        $this->assertSame(['starter', 'msingi', 'biashara', 'professional', 'premium'], array_column($data['plans'], 'code'));
        $this->assertSame([10000.0, 15000.0, 35000.0, 55000.0, 75000.0], array_map('floatval', array_column($data['plans'], 'price_monthly')));
        $recommended = array_values(array_filter($data['plans'], fn ($p) => $p['recommended']));
        $this->assertCount(1, $recommended);
        $this->assertSame('biashara', $recommended[0]['code']);
        $this->assertSame(14, $data['plans'][0]['trial_days']);
        $this->assertContains('voice', $recommended[0]['features']);
    }

    public function test_checking_an_address(): void
    {
        $this->postJson('/api/v1/platform/signup/check-address', ['slug' => 'Duka-La-Juma'])->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.slug', 'duka-la-juma');

        $taken = $this->postJson('/api/v1/platform/signup/check-address', ['slug' => 'admin'])->assertOk();
        $this->assertFalse($taken->json('data.available'));
        $this->assertNotEmpty($taken->json('data.suggestions'));
    }

    public function test_a_business_registers_confirms_by_sms_and_signs_in(): void
    {
        $start = $this->postJson('/api/v1/platform/signup', $this->details())->assertCreated()
            ->assertJsonPath('data.status', 'waiting_for_code')
            ->assertJsonMissingPath('data.debug_code');
        $id = $start->json('data.id');
        $this->assertStringContainsString('678', $start->json('data.phone'));

        $this->postJson("/api/v1/platform/signup/$id/verify", ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'WRONG_CODE');

        $this->postJson("/api/v1/platform/signup/$id/verify", ['code' => $this->smsCode()])->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.business.slug', 'duka-la-juma')
            ->assertJsonPath('data.business.url', 'https://patopro.test/duka-la-juma');

        $business = Business::query()->where('slug', 'duka-la-juma')->firstOrFail();
        $this->assertSame('255712345678', $business->phone);
        $this->assertSame('biashara', $business->plan->code);
        $this->assertSame('trial', $business->state()->value);
        $this->assertTrue($business->trial_ends_at->between(now()->addDays(13), now()->addDays(15)));

        // The new business answers with its own name and lets the owner in with the phone as typed.
        $headers = ['X-Business' => 'duka-la-juma'];
        $this->getJson('/api/v1/public/settings', $headers)->assertOk()->assertJsonPath('data.business_name', 'Duka la Juma');
        $this->postJson('/api/v1/auth/login', ['login' => '0712345678', 'password' => 'Siri12345'], $headers)->assertOk()
            ->assertJsonPath('data.user.name', 'Juma Mussa');

        // A welcome SMS with the address followed the code.
        $this->assertTrue(collect(Http::recorded())->contains(fn ($pair) => str_contains($pair[0]['message'] ?? '', 'https://patopro.test/duka-la-juma')));

        // Asking again (double tap) gives the same business, not a second one.
        $this->postJson("/api/v1/platform/signup/$id/verify", ['code' => '123456'])->assertOk()->assertJsonPath('data.status', 'ready');
        $this->assertSame(1, Business::query()->count());
    }

    public function test_without_sms_the_code_is_shown_outside_production(): void
    {
        config(['mauzo.sms.enabled' => false]);

        $code = $this->postJson('/api/v1/platform/signup', $this->details())->assertCreated()->json('data.debug_code');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function test_a_new_code_needs_a_short_wait(): void
    {
        $id = $this->postJson('/api/v1/platform/signup', $this->details())->assertCreated()->json('data.id');

        $this->postJson("/api/v1/platform/signup/$id/resend")->assertStatus(429)->assertJsonPath('code', 'WAIT');

        Signup::query()->whereKey($id)->update(['code_sent_at' => now()->subMinutes(2)]);
        $this->postJson("/api/v1/platform/signup/$id/resend")->assertOk()->assertJsonPath('data.codes_left', 1);
    }

    public function test_too_many_wrong_codes_end_the_registration(): void
    {
        $id = $this->postJson('/api/v1/platform/signup', $this->details())->assertCreated()->json('data.id');
        foreach (range(1, 5) as $i) {
            $this->postJson("/api/v1/platform/signup/$id/verify", ['code' => '000000']);
        }

        $this->postJson("/api/v1/platform/signup/$id/verify", ['code' => $this->smsCode()])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
        $this->assertSame(0, Business::query()->count());
    }

    public function test_bad_details_are_refused(): void
    {
        $this->business('duka-la-juma', 'Mwingine', 'starter', ['trial_ends_at' => now()->addDays(3)]);

        $this->postJson('/api/v1/platform/signup', $this->details())->assertStatus(422)->assertJsonPath('code', 'SLUG_UNAVAILABLE');
        $this->postJson('/api/v1/platform/signup', $this->details(['slug' => 'duka-jipya', 'phone' => '12345']))->assertStatus(422)->assertJsonPath('code', 'BAD_PHONE');
        $this->postJson('/api/v1/platform/signup', $this->details(['slug' => 'duka-jipya', 'plan' => 'hakuna']))->assertStatus(422)->assertJsonPath('code', 'PLAN_NOT_FOUND');
        $this->postJson('/api/v1/platform/signup', $this->details(['slug' => 'duka-jipya', 'password_confirmation' => 'tofauti']))->assertStatus(422);
    }
}

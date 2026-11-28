<?php

namespace Tests\Feature\Platform;

use App\Exceptions\BusinessException;
use App\Models\Core\Branch;
use App\Platform\Models\Business;
use App\Platform\Support\BusinessManager;
use App\Support\Modules;

/**
 * Many businesses in one app: each request reaches only its own database,
 * and packages and subscription states are enforced. Uses SQLite files, one per business.
 */
class PlatformModeTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->business('duka-a', 'Duka A', 'msingi', ['trial_ends_at' => now()->addDays(14)]);
        $this->business('duka-b', 'Duka B', 'premium', ['paid_until' => now()->addMonth()]);
        $this->business('duka-c', 'Duka C', 'msingi', ['trial_ends_at' => now()->subDays(30)]);
        $this->business('duka-d', 'Duka D', 'premium', ['paid_until' => now()->addMonth(), 'suspended_at' => now()]);
        app(BusinessManager::class)->forget();
    }

    public function test_a_request_needs_a_business_except_for_central_routes(): void
    {
        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/business')->assertStatus(400)->assertJsonPath('code', 'BUSINESS_REQUIRED');
        $this->getJson('/api/v1/business', ['X-Business' => 'hakuna'])->assertStatus(404)->assertJsonPath('code', 'BUSINESS_NOT_FOUND');
    }

    public function test_each_business_reads_only_its_own_database(): void
    {
        $this->getJson('/api/v1/business', ['X-Business' => 'duka-a'])->assertOk()
            ->assertJsonPath('data.mode', 'platform')
            ->assertJsonPath('data.slug', 'duka-a')
            ->assertJsonPath('data.state', 'trial')
            ->assertJsonPath('data.plan.code', 'msingi');

        $this->getJson('/api/v1/public/settings', ['X-Business' => 'duka-a'])->assertOk()->assertJsonPath('data.business_name', 'Duka A');
        $this->getJson('/api/v1/public/settings', ['X-Business' => 'duka-b'])->assertOk()->assertJsonPath('data.business_name', 'Duka B');
        $this->getJson('/api/v1/public/settings?business=duka-a')->assertOk()->assertJsonPath('data.business_name', 'Duka A');
    }

    public function test_the_package_switches_modules_and_limits(): void
    {
        $manager = app(BusinessManager::class);

        $manager->run(Business::query()->where('slug', 'duka-a')->first(), function () {
            $this->assertFalse(Modules::enabled('accounting'));
            $this->assertTrue(Modules::enabled('sales'));
            $this->assertFalse(plan()->allows('voice'));
            $this->assertSame(1, plan()->limit('branches'));

            $this->expectException(BusinessException::class);
            Branch::factory()->create(); // the main branch already uses the only one
        });
    }

    public function test_a_premium_business_has_everything(): void
    {
        app(BusinessManager::class)->run(Business::query()->where('slug', 'duka-b')->first(), function () {
            $this->assertTrue(Modules::enabled('accounting'));
            $this->assertTrue(plan()->allows('voice'));
            Branch::factory()->count(2)->create();
            $this->assertSame(3, Branch::query()->count());
        });
    }

    public function test_an_ended_subscription_is_read_only(): void
    {
        $this->getJson('/api/v1/business', ['X-Business' => 'duka-c'])->assertOk()
            ->assertJsonPath('data.state', 'read_only')
            ->assertJsonPath('data.can_write', false);

        $this->postJson('/api/v1/admin/expenses', ['title' => 'Kodi'], ['X-Business' => 'duka-c'])
            ->assertStatus(402)->assertJsonPath('code', 'SUBSCRIPTION_READ_ONLY');

        // Signing in still works (and is answered by the business itself).
        $this->postJson('/api/v1/auth/login', ['login' => 'admin', 'password' => 'wrong'], ['X-Business' => 'duka-c'])
            ->assertJsonMissing(['code' => 'SUBSCRIPTION_READ_ONLY']);
    }

    public function test_grace_days_keep_full_use(): void
    {
        Business::query()->where('slug', 'duka-c')->update(['trial_ends_at' => now()->subDays(2)]);

        $this->getJson('/api/v1/business', ['X-Business' => 'duka-c'])->assertOk()
            ->assertJsonPath('data.state', 'grace')
            ->assertJsonPath('data.can_write', true);
    }

    public function test_a_suspended_business_is_closed(): void
    {
        $this->getJson('/api/v1/business', ['X-Business' => 'duka-d'])->assertStatus(403)->assertJsonPath('code', 'BUSINESS_SUSPENDED');
    }
}

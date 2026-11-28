<?php

namespace Tests\Feature\Platform;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Product;
use App\Models\Core\Branch;
use App\Support\Modules;
use Tests\TestCase;

/** Existing single-business installs must behave exactly as before. */
class SingleModeTest extends TestCase
{
    public function test_the_one_business_has_everything_and_can_write(): void
    {
        $data = $this->getJson('/api/v1/business')->assertOk()->json('data');

        $this->assertSame('single', $data['mode']);
        $this->assertSame('active', $data['state']);
        $this->assertTrue($data['can_write']);
        $this->assertContains('accounting', $data['plan']['features']);
        $this->assertContains('voice', $data['plan']['features']);
        $this->assertNull($data['plan']['limits']['users']);
        $this->assertTrue(plan()->allows('payroll'));
        $this->assertTrue(Modules::enabled('accounting'));
    }

    public function test_no_limits_by_default(): void
    {
        Branch::factory()->count(3)->create();
        Product::factory()->count(3)->create();

        $this->assertSame(4, Branch::query()->count());
    }

    public function test_a_licence_limit_is_respected(): void
    {
        config(['patopro.single.limits.branches' => 1]); // the seeded main branch is the one
        app(\App\Platform\Support\BusinessManager::class)->refresh();

        $this->expectException(BusinessException::class);
        Branch::factory()->create();
    }

    public function test_a_licence_without_a_module_switches_it_off(): void
    {
        config(['patopro.single.features' => ['pos', 'sales', 'products', 'stock']]);
        app(\App\Platform\Support\BusinessManager::class)->refresh();

        $this->assertFalse(Modules::enabled('accounting'));
        $this->assertTrue(Modules::enabled('sales'));
        $this->assertFalse(plan()->allows('voice'));
    }
}

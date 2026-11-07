<?php

namespace Tests\Feature;

use App\Models\Site\SiteVisitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisitorCounterTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (Linux; Android 14; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

    public function test_visits_are_counted_with_location_device_and_sessions(): void
    {
        $headers = ['User-Agent' => self::PHONE, 'X-Visitor-Id' => 'b7a1c2d3-e4f5-4a6b-8c9d-0e1f2a3b4c5d',
            'X-Vercel-IP-Country' => 'TZ', 'X-Vercel-IP-City' => 'Dar%20es%20Salaam', 'X-Vercel-IP-Country-Region' => '02'];
        $this->withHeaders($headers)->postJson('/api/v1/public/visits', ['path' => '/products/sukari?ref=1', 'referrer' => 'https://www.google.com/search'])->assertStatus(202);
        $this->withHeaders($headers)->postJson('/api/v1/public/visits', ['path' => '/cart'])->assertStatus(202);
        $this->withHeaders($headers)->postJson('/api/v1/public/visits', ['path' => '/cart', 'type' => 'heartbeat'])->assertStatus(202);

        $v = SiteVisitor::query()->sole();
        $this->assertSame(['TZ', 'Dar es Salaam', 'mobile', 'Chrome', 'Android', 'www.google.com', 2, 1, '/cart', 'guest'],
            [$v->country, $v->city, $v->device, $v->browser, $v->os, $v->referrer, $v->page_views, $v->visits, $v->current_path, $v->kind]);

        // Back after an hour: a second visit by the same visitor.
        $this->travel(61)->minutes();
        $this->withHeaders($headers)->postJson('/api/v1/public/visits', ['path' => '/'])->assertStatus(202);
        $this->assertSame(2, $v->fresh()->visits);

        // Search engines are not visitors.
        $this->withHeaders(['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'])->postJson('/api/v1/public/visits', ['path' => '/'])->assertStatus(202);
        $this->assertSame(1, SiteVisitor::query()->count());
    }

    public function test_staff_see_online_visitors_and_period_counts(): void
    {
        $this->withHeaders(['User-Agent' => self::PHONE, 'X-Visitor-Id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'])->postJson('/api/v1/public/visits', ['path' => '/']);
        $customer = $this->customerUser();
        Sanctum::actingAs($customer);
        $this->withHeaders(['User-Agent' => self::PHONE, 'X-Visitor-Id' => 'cccccccc-bbbb-4ccc-8ddd-eeeeeeeeeeee'])->postJson('/api/v1/public/visits', ['path' => '/account']);

        $this->actingAsStaff('admin');
        $summary = $this->getJson('/api/v1/admin/visitors/summary')->assertOk()->json('data');
        $this->assertSame(2, $summary['online_now']);
        $this->assertSame(2, $summary['today']);
        $this->assertSame(2, $summary['this_year']);
        $this->assertSame(2, $summary['all_time']);
        $this->assertCount(30, $summary['daily']);
        $this->getJson('/api/v1/admin/visitors?period=online&kind=customer')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.user.name', $customer->name)->assertJsonMissingPath('data.0.visitor_key');

        $this->actingAsStaff('cashier');
        $this->getJson('/api/v1/admin/visitors/summary')->assertForbidden();
    }

    private function customerUser()
    {
        return \App\Models\Core\User::factory()->create(['type' => \App\Models\Core\User::TYPE_CUSTOMER, 'branch_id' => null]);
    }
}

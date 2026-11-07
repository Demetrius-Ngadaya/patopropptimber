<?php

namespace Tests\Feature;

use App\Models\Agency\AgencyDay;
use App\Models\Core\AppNotification;
use App\Services\Core\SettingsService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgencyTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enable(): void
    {
        app(SettingsService::class)->update(['optional_modules' => ['agency']]);
    }

    /** @return array{0: int, 1: int} M-Pesa and CRDB account ids */
    private function accounts(): array
    {
        $mpesa = $this->postJson('/api/v1/admin/agency/accounts', ['name' => 'M-Pesa', 'provider' => 'mpesa', 'agent_number' => '123456'])->assertCreated()->json('data.id');
        $crdb = $this->postJson('/api/v1/admin/agency/accounts', ['name' => 'CRDB Wakala', 'provider' => 'crdb'])->assertCreated()->json('data.id');

        return [$mpesa, $crdb];
    }

    public function test_module_is_off_until_the_business_switches_it_on(): void
    {
        $admin = $this->actingAsStaff();
        $this->getJson('/api/v1/admin/agency/today')->assertForbidden();

        $this->putJson('/api/v1/admin/settings', ['optional_modules' => ['agency']])->assertOk();
        Sanctum::actingAs($admin->fresh()); // the next request reads the permissions again
        $this->getJson('/api/v1/admin/agency/today')->assertOk()->assertJsonPath('data.day', null);
        $this->putJson('/api/v1/admin/settings', ['optional_modules' => ['nonsense']])->assertUnprocessable();
    }

    public function test_day_opens_closes_balances_and_carries_forward(): void
    {
        $this->enable();
        $this->actingAsStaff();
        [$mpesa, $crdb] = $this->accounts();
        Carbon::setTestNow('2026-06-10 07:30:00');

        $day = $this->postJson('/api/v1/admin/agency/days', [
            'cash' => 500000, 'balances' => [['account_id' => $mpesa, 'amount' => 1000000], ['account_id' => $crdb, 'amount' => 2000000]],
        ])->assertCreated()->assertJsonPath('data.opening_total', 3500000)->json('data');
        $this->postJson('/api/v1/admin/agency/days', ['cash' => 1, 'balances' => []])->assertUnprocessable()->assertJsonPath('code', 'ALREADY_OPEN');

        // Every line opened in the morning needs its evening figure.
        $this->postJson("/api/v1/admin/agency/days/{$day['id']}/close", ['cash' => 600000, 'balances' => [['account_id' => $mpesa, 'amount' => 900000]]])
            ->assertUnprocessable()->assertJsonPath('code', 'FLOAT_MISSING');

        // Customers withdrew 100k cash from M-Pesa: cash +100k, float -100k. 50k capital taken out at noon.
        Carbon::setTestNow('2026-06-10 21:00:00');
        $closed = $this->postJson("/api/v1/admin/agency/days/{$day['id']}/close", [
            'cash' => 550000, 'balances' => [['account_id' => $mpesa, 'amount' => 900000], ['account_id' => $crdb, 'amount' => 2000000]], 'capital_withdrawn' => 50000,
        ])->assertOk()->json('data');
        $this->assertEquals(3450000, $closed['closing_total']);
        $this->assertEquals(0, $closed['difference']);
        $this->assertSame(0, AppNotification::query()->where('type', 'agency_difference')->count());

        // Next morning starts from last night's figures.
        Carbon::setTestNow('2026-06-11 07:00:00');
        $today = $this->getJson('/api/v1/admin/agency/today')->assertOk()->json('data');
        $this->assertNull($today['day']);
        $this->assertEquals(550000, $today['suggestion']['cash']);
        $this->assertEquals(900000, $today['suggestion']['floats'][$mpesa]);

        // 20k missing at night: a warning for the managers.
        $next = $this->postJson('/api/v1/admin/agency/days', ['cash' => 550000, 'balances' => [['account_id' => $mpesa, 'amount' => 900000], ['account_id' => $crdb, 'amount' => 2000000]]])->json('data');
        $this->postJson("/api/v1/admin/agency/days/{$next['id']}/close", ['cash' => 530000, 'balances' => [['account_id' => $mpesa, 'amount' => 900000], ['account_id' => $crdb, 'amount' => 2000000]]])
            ->assertOk()->assertJsonPath('data.difference', -20000);
        $this->assertSame(1, AppNotification::query()->where('type', 'agency_difference')->count());

        $summary = $this->getJson('/api/v1/admin/agency/summary?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data');
        $this->assertSame(2, $summary['days']);
        $this->assertEquals(-20000, $summary['shortage']);
        $this->assertEquals(3430000, $summary['latest_total']);
    }

    public function test_a_day_left_open_must_be_closed_first_and_managers_correct_days(): void
    {
        $this->enable();
        $this->actingAsStaff();
        [$mpesa] = $this->accounts();
        Carbon::setTestNow('2026-06-10 08:00:00');
        $day = $this->postJson('/api/v1/admin/agency/days', ['cash' => 100000, 'balances' => [['account_id' => $mpesa, 'amount' => 50000]]])->json('data');

        Carbon::setTestNow('2026-06-11 08:00:00');
        $this->getJson('/api/v1/admin/agency/today')->assertJsonPath('data.unclosed.id', $day['id']);
        $this->postJson('/api/v1/admin/agency/days', ['cash' => 100000, 'balances' => []])->assertUnprocessable()->assertJsonPath('code', 'PREVIOUS_OPEN');

        $this->putJson("/api/v1/admin/agency/days/{$day['id']}", ['reason' => 'Counted again', 'opening_cash' => 120000])
            ->assertOk()->assertJsonPath('data.opening_total', 170000);
        $this->assertSame('120000.00', AgencyDay::find($day['id'])->opening_cash);
    }

    public function test_commissions_reports_and_permissions(): void
    {
        $this->enable();
        $this->actingAsStaff();
        [$mpesa, $crdb] = $this->accounts();
        $this->postJson('/api/v1/admin/agency/commissions', ['agency_account_id' => $mpesa, 'period' => now()->subMonth()->format('Y-m'), 'amount' => 85000, 'received_on' => today()->toDateString()])->assertCreated();
        $this->postJson('/api/v1/admin/agency/commissions', ['agency_account_id' => $crdb, 'period' => now()->subMonth()->format('Y-m'), 'amount' => 40000, 'received_on' => today()->toDateString()])->assertCreated();
        $this->postJson('/api/v1/admin/agency/commissions', ['agency_account_id' => $crdb, 'period' => now()->addMonths(2)->format('Y-m'), 'amount' => 1, 'received_on' => today()->toDateString()])->assertUnprocessable();
        $this->getJson('/api/v1/admin/agency/commissions')->assertOk()->assertJsonPath('total', 125000);

        $this->postJson('/api/v1/admin/agency/days', ['cash' => 1000, 'balances' => [['account_id' => $mpesa, 'amount' => 2000]]])->assertCreated();
        $report = $this->getJson('/api/v1/admin/reports/agency_commissions')->assertOk()->json();
        $this->assertEquals(125000, $report['report']['summary']['total']);
        $this->assertEquals(85000, $report['report']['summary']['M-Pesa']);
        $days = $this->getJson('/api/v1/admin/reports/agency_days')->assertOk()->json();
        $this->assertCount(1, $days['data']);
        $this->assertEquals(3000, $days['data'][0]['opening_total']);

        // A cashier opens and closes the day but does not set up lines.
        $this->actingAsStaff('cashier');
        $this->getJson('/api/v1/admin/agency/today')->assertOk();
        $this->postJson('/api/v1/admin/agency/accounts', ['name' => 'X', 'provider' => 'other'])->assertForbidden();
        $this->getJson('/api/v1/admin/reports/agency_days')->assertForbidden();
    }
}

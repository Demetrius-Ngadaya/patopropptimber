<?php

namespace Tests\Feature;

use App\Models\Core\AppNotification;
use App\Models\Shifts\Shift;
use App\Services\Core\SettingsService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShiftsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->update(['optional_modules' => ['shifts']]);
        Carbon::setTestNow('2026-06-15 06:00:00'); // a Monday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manager_plans_shifts_including_night_shifts_without_double_booking(): void
    {
        $asha = $this->staff('cashier');
        $juma = $this->staff('cashier');
        $this->actingAsStaff();

        $night = $this->postJson('/api/v1/admin/shifts/types', ['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00'])->assertCreated()->json('data');
        $this->postJson('/api/v1/admin/shifts', ['user_ids' => [$asha->id, $juma->id], 'shift_type_id' => $night['id'], 'date_from' => '2026-06-15', 'date_to' => '2026-06-16'])
            ->assertCreated()->assertJsonPath('data.created', 4);
        $first = Shift::query()->where('user_id', $asha->id)->orderBy('starts_at')->first();
        $this->assertSame('2026-06-15 22:00', $first->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-06-16 06:00', $first->ends_at->format('Y-m-d H:i'));

        // Same people, same nights: nothing new, each one reported.
        $again = $this->postJson('/api/v1/admin/shifts', ['user_ids' => [$asha->id], 'start_time' => '23:00', 'end_time' => '02:00', 'date_from' => '2026-06-15', 'weekdays' => [1]])->assertCreated()->json('data');
        $this->assertSame(0, $again['created']);
        $this->assertCount(1, $again['skipped']);

        $this->getJson('/api/v1/admin/shifts?from=2026-06-15&to=2026-06-21')->assertOk()->assertJsonCount(4, 'data.shifts');
        Sanctum::actingAs($asha);
        $this->getJson('/api/v1/admin/shifts?from=2026-06-15&to=2026-06-21')->assertOk()->assertJsonCount(2, 'data.shifts');
        $this->postJson('/api/v1/admin/shifts', ['user_ids' => [$asha->id], 'start_time' => '08:00', 'end_time' => '16:00', 'date_from' => '2026-06-20'])->assertForbidden();
    }

    public function test_emergency_cover_and_handover_with_cash_check(): void
    {
        $asha = $this->staff('cashier');
        $juma = $this->staff('cashier');
        $neema = $this->staff('cashier');
        $this->actingAsStaff();
        $this->postJson('/api/v1/admin/shifts', ['user_ids' => [$asha->id], 'start_time' => '08:00', 'end_time' => '16:00', 'date_from' => '2026-06-15'])->assertCreated();
        $this->postJson('/api/v1/admin/shifts', ['user_ids' => [$juma->id], 'start_time' => '16:00', 'end_time' => '23:00', 'date_from' => '2026-06-15'])->assertCreated();
        $morning = Shift::query()->where('user_id', $asha->id)->first();
        $evening = Shift::query()->where('user_id', $juma->id)->first();

        // Juma has an emergency and asks Neema to cover his evening.
        Sanctum::actingAs($juma);
        $request = $this->postJson("/api/v1/admin/shifts/{$evening->id}/delegate", ['to_user_id' => $neema->id, 'reason' => 'My child is sick', 'emergency' => true])->assertCreated()->json('data');
        $this->assertTrue(AppNotification::query()->where('type', 'shift')->where('permission', 'shifts.manage')->exists());
        Sanctum::actingAs($neema);
        $this->getJson('/api/v1/admin/shifts/mine')->assertOk()->assertJsonCount(1, 'data.cover_requests');
        $this->postJson("/api/v1/admin/shifts/delegations/{$request['id']}/respond", ['accept' => true, 'note' => 'Sawa, nitakuja'])->assertOk();
        $this->assertSame($neema->id, $evening->fresh()->user_id);

        // Asha cannot start more than an hour early, then works and hands over to Neema.
        Sanctum::actingAs($asha);
        $this->postJson("/api/v1/admin/shifts/{$morning->id}/start")->assertUnprocessable()->assertJsonPath('code', 'TOO_EARLY');
        Carbon::setTestNow('2026-06-15 07:50:00');
        $this->postJson("/api/v1/admin/shifts/{$morning->id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $mine = $this->getJson('/api/v1/admin/shifts/mine')->json('data');
        $this->assertSame($neema->id, $mine['next_shift']['user_id']);

        Carbon::setTestNow('2026-06-15 16:05:00');
        $this->postJson("/api/v1/admin/shifts/{$morning->id}/end", ['notes' => 'Fridge 2 is leaking. Supplier comes at 18:00.', 'cash_counted' => 250000])
            ->assertOk()->assertJsonPath('data.status', 'completed');

        Sanctum::actingAs($neema);
        $handover = $this->getJson('/api/v1/admin/shifts/mine')->assertOk()->json('data.handovers.0');
        $this->assertSame('Fridge 2 is leaking. Supplier comes at 18:00.', $handover['notes']);
        $this->postJson("/api/v1/admin/shifts/handovers/{$handover['id']}/accept", ['comment' => 'Cash is 240,000 only', 'cash_confirmed' => 240000])->assertOk();
        $this->assertTrue(AppNotification::query()->where('type', 'shift')->where('title', 'Cash differs at shift handover')->exists());
        Sanctum::actingAs($juma);
        $this->postJson("/api/v1/admin/shifts/handovers/{$handover['id']}/accept")->assertForbidden();
    }

    public function test_handover_is_required_when_someone_works_next_and_unstarted_shifts_are_missed(): void
    {
        $asha = $this->staff('cashier');
        $juma = $this->staff('cashier');
        $this->actingAsStaff();
        $this->postJson('/api/v1/admin/shifts', ['user_ids' => [$asha->id], 'start_time' => '06:00', 'end_time' => '14:00', 'date_from' => '2026-06-15'])->assertCreated();
        $this->postJson('/api/v1/admin/shifts', ['user_ids' => [$juma->id], 'start_time' => '14:00', 'end_time' => '22:00', 'date_from' => '2026-06-15'])->assertCreated();
        $morning = Shift::query()->where('user_id', $asha->id)->first();

        Sanctum::actingAs($asha);
        $this->postJson("/api/v1/admin/shifts/{$morning->id}/start")->assertOk();
        // Ending hands over to the person who works next by default.
        Carbon::setTestNow('2026-06-15 14:00:00');
        $this->postJson("/api/v1/admin/shifts/{$morning->id}/end", ['to_user_id' => $asha->id])->assertUnprocessable();
        $this->postJson("/api/v1/admin/shifts/{$morning->id}/end", [])->assertOk()->assertJsonPath('data.handover.to_user_id', $juma->id);

        // Juma never starts his shift.
        Carbon::setTestNow('2026-06-15 23:00:00');
        $this->artisan('mauzo:personal-reminders')->assertSuccessful();
        $this->assertSame('missed', Shift::query()->where('user_id', $juma->id)->first()->status);
    }
}

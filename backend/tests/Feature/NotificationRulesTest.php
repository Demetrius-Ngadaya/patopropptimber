<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Core\NotificationRule;
use App\Models\Core\Role;
use App\Models\Core\SmsLog;
use App\Services\Core\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['mauzo.sms.enabled' => true, 'mauzo.sms.token' => 't', 'mail.from.address' => 'shop@example.com']);
        Http::fake(['*' => Http::response(['status' => 'success'])]);
        Mail::fake();
        app(SettingsService::class)->update([
            'messaging_channels' => ['sms', 'whatsapp', 'email'], 'message_locale' => 'en',
            'business_name' => 'Duka Bora', 'business_phone' => '0754000111', 'business_email' => 'owner@dukabora.co.tz',
        ]);
    }

    private function rule(array $extra = []): array
    {
        return $this->postJson('/api/v1/admin/notification-rules', $extra + [
            'name' => 'Evening sales', 'source' => 'sales_summary', 'channels' => ['sms', 'email'],
            'role_ids' => [Role::where('slug', 'accountant')->value('id')], 'send_to_role_users' => true, 'include_company_contacts' => true,
            'extra_phones' => '0713000000', 'frequency' => 'daily', 'time' => '20:00', 'period' => 'today', 'skip_when_empty' => true, 'is_active' => true,
        ])->assertCreated()->json('data');
    }

    public function test_admin_designs_a_notification_and_sends_it(): void
    {
        $accountant = $this->staff('accountant', null, ['phone' => '0788000222', 'email' => 'acc@dukabora.co.tz']);
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(5, ['selling_price' => 15000]);
        $this->postJson('/api/v1/admin/pos/checkout', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 30000]]])->assertCreated();

        Sanctum::actingAs($this->staff('super-admin'));
        $rule = $this->rule(['sms_template' => '{business}: leo mauzo {currency} {total_sales} ({receipts}).']);
        $this->assertNotNull($rule['next_run_at']);

        $preview = $this->postJson("/api/v1/admin/notification-rules/{$rule['id']}/preview")->assertOk()->json('data');
        $this->assertSame('Duka Bora: leo mauzo TZS 30,000 (1).', $preview['sms']);
        $this->assertStringContainsString('30,000', $preview['whatsapp']); // default WhatsApp wording
        $this->assertEqualsCanonicalizing(['255713000000', '255754000111', '255788000222'], $preview['recipients']['sms']);
        $this->assertEqualsCanonicalizing(['owner@dukabora.co.tz', 'acc@dukabora.co.tz'], $preview['recipients']['emails']);

        $this->postJson("/api/v1/admin/notification-rules/{$rule['id']}/run")->assertOk();
        $this->assertSame(3, SmsLog::where('channel', 'sms')->where('purpose', 'custom:'.$rule['id'])->count());
        Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo('acc@dukabora.co.tz') && $m->pdf !== null && $m->document['title'] === 'Evening sales');

        // In the bell only for the chosen role.
        Sanctum::actingAs($accountant);
        $this->getJson('/api/v1/admin/notifications/summary')->assertJsonPath('data.latest.0.title', 'Evening sales');
        Sanctum::actingAs($this->staff('storekeeper'));
        $this->assertNotContains('Evening sales', collect($this->getJson('/api/v1/admin/notifications/summary')->json('data.latest'))->pluck('title')->all());
    }

    public function test_due_rules_run_on_schedule_and_skip_when_nothing_to_report(): void
    {
        Sanctum::actingAs($this->staff('super-admin'));
        $rule = $this->rule(['source' => 'stock_alerts', 'name' => 'Stock check']);
        NotificationRule::whereKey($rule['id'])->update(['next_run_at' => now()->subMinute()]);

        $this->artisan('mauzo:notification-rules')->assertSuccessful();
        $fresh = NotificationRule::find($rule['id']);
        $this->assertTrue($fresh->last_result['skipped']);           // no stock problems: nothing sent
        $this->assertTrue($fresh->next_run_at->isFuture());
        $this->assertSame(0, SmsLog::count());

        $this->productWithStock(1, ['reorder_level' => 5]);
        NotificationRule::whereKey($rule['id'])->update(['next_run_at' => now()->subMinute()]);
        $this->artisan('mauzo:notification-rules')->assertSuccessful();
        $this->assertFalse(NotificationRule::find($rule['id'])->last_result['skipped']);
        $this->assertStringContainsString('1 low', SmsLog::where('channel', 'sms')->first()->message);
    }

    public function test_schedule_calculation(): void
    {
        Carbon::setTestNow('2026-10-21 10:00:00'); // a Wednesday
        $rule = new NotificationRule(['frequency' => 'daily', 'time' => '08:00']);
        $this->assertSame('2026-10-22 08:00', $rule->nextRun()->format('Y-m-d H:i'));
        $rule->fill(['frequency' => 'weekly', 'weekday' => 1, 'time' => '07:30']);
        $this->assertSame('2026-10-26 07:30', $rule->nextRun()->format('Y-m-d H:i'));
        $rule->fill(['frequency' => 'monthly', 'month_day' => 0, 'time' => '18:00']);
        $this->assertSame('2026-10-31 18:00', $rule->nextRun()->format('Y-m-d H:i'));
        $rule->fill(['frequency' => 'hourly', 'time' => '00:15']);
        $this->assertSame('2026-10-21 10:15', $rule->nextRun()->format('Y-m-d H:i'));
        Carbon::setTestNow();
    }

    public function test_only_allowed_users_manage_rules(): void
    {
        Sanctum::actingAs($this->staff('cashier'));
        $this->getJson('/api/v1/admin/notification-rules')->assertForbidden();
        Sanctum::actingAs($this->staff('super-admin'));
        $this->getJson('/api/v1/admin/notification-rules/sources')->assertOk()->assertJsonFragment(['key' => 'overdue_debts']);
        $this->postJson('/api/v1/admin/notification-rules', ['name' => 'x', 'source' => 'nope', 'channels' => [], 'frequency' => 'daily', 'time' => '25:00', 'period' => 'today'])
            ->assertUnprocessable()->assertJsonValidationErrors(['source', 'time']);
    }
}

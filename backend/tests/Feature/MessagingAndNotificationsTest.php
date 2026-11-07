<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Core\AppNotification;
use App\Models\Core\Branch;
use App\Models\Core\SmsLog;
use App\Models\Crm\Customer;
use App\Models\Sales\Sale;
use App\Services\Core\SettingsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingAndNotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mauzo.sms.enabled' => true, 'mauzo.sms.token' => 'sms-token',
            'mauzo.whatsapp.enabled' => true, 'mauzo.whatsapp.token' => 'wa-token', 'mauzo.whatsapp.phone_number_id' => '12345',
            'mauzo.whatsapp.template' => null,
            'mail.from.address' => 'shop@example.com',
        ]);
        Http::fake([
            'sms.webline.africa/*' => Http::response(['status' => 'success']),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]]),
        ]);
        Mail::fake();
        $this->settings()->update(['messaging_channels' => ['sms', 'whatsapp', 'email'], 'message_locale' => 'en']);
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    // ------------------------------------------------------------ customer messages

    public function test_receipt_goes_out_on_every_ticked_channel_and_email_has_a_pdf(): void
    {
        $this->actingAsStaff('cashier');
        $customer = Customer::factory()->create(['phone' => '0712345678', 'email' => 'asha@example.com']);
        $product = $this->productWithStock(10, ['selling_price' => 2000]);

        $sale = $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 4000]],
            'receipt_channels' => ['sms', 'whatsapp', 'email'],
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('sms_logs', ['channel' => 'sms', 'recipient' => '255712345678', 'purpose' => 'receipt', 'status' => 'sent']);
        $this->assertDatabaseHas('sms_logs', ['channel' => 'whatsapp', 'recipient' => '255712345678', 'status' => 'sent']);
        $this->assertDatabaseHas('sms_logs', ['channel' => 'email', 'recipient' => 'asha@example.com', 'status' => 'sent', 'has_attachment' => true]);
        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($sale) {
            return $mail->hasTo('asha@example.com')
                && str_contains($mail->mailSubject, $sale['number'])
                && $mail->pdf !== null && str_starts_with($mail->pdf, '%PDF')
                && count($mail->document['sections'][0]['rows']) === 1; // the item line
        });

        // SMS short, WhatsApp fuller.
        $sms = SmsLog::where('channel', 'sms')->first()->message;
        $wa = SmsLog::where('channel', 'whatsapp')->first()->message;
        $this->assertLessThanOrEqual(160, mb_strlen($sms));
        $this->assertGreaterThan(mb_strlen($sms), mb_strlen($wa));
    }

    public function test_channels_the_admin_switched_off_are_never_used(): void
    {
        $this->settings()->update(['messaging_channels' => ['sms']]);
        $this->actingAsStaff('cashier');
        $customer = Customer::factory()->create(['phone' => '0712345678', 'email' => 'asha@example.com']);
        $product = $this->productWithStock(10);

        $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 100000]],
            'receipt_channels' => ['whatsapp', 'email'],
        ])->assertCreated();

        $this->assertSame(0, SmsLog::count());
        Mail::assertNothingSent();
    }

    public function test_whatsapp_template_gets_one_single_line_variable(): void
    {
        config(['mauzo.whatsapp.template' => 'mauzo_notice', 'mauzo.whatsapp.template_language' => 'sw']);
        Sanctum::actingAs($this->staff('super-admin'));

        $this->postJson('/api/v1/admin/sms/send', [
            'audience' => 'numbers', 'numbers' => '0712345678', 'channels' => ['whatsapp'],
            'message' => "Line one\n\nLine two",
        ])->assertOk();

        Http::assertSent(function (HttpRequest $request) {
            $param = $request['template']['components'][0]['parameters'][0]['text'] ?? '';

            return str_contains($request->url(), '/12345/messages')
                && $request['type'] === 'template' && $request['template']['name'] === 'mauzo_notice'
                && $request['template']['language']['code'] === 'sw'
                && ! str_contains($param, "\n") && str_contains($param, 'Line one') && str_contains($param, 'Line two');
        });
    }

    public function test_manual_message_to_debtors_emails_each_their_own_statement(): void
    {
        $owing = Customer::factory()->create(['email' => 'owes@example.com', 'phone' => '0711111111', 'allow_credit' => true, 'credit_limit' => 0]);
        Customer::factory()->create(['email' => 'clear@example.com', 'phone' => '0722222222']);
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(10, ['selling_price' => 5000]);
        $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $owing->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [], 'due_date' => now()->addDays(3)->toDateString(),
        ])->assertCreated();
        Mail::fake();

        Sanctum::actingAs($this->staff('super-admin'));
        $res = $this->postJson('/api/v1/admin/sms/send', [
            'audience' => 'debtors', 'channels' => ['sms', 'email'], 'subject' => 'Please pay', 'message' => 'Kindly clear your balance.',
        ])->assertOk();

        $this->assertStringContainsString('SMS: sent to 1', $res->json('message'));
        Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo('owes@example.com') && $m->document['sections'][0]['heading'] === 'Unpaid invoices');
        Mail::assertNotSent(NotificationMail::class, fn ($m) => $m->hasTo('clear@example.com'));
    }

    public function test_recipient_lists_are_validated(): void
    {
        Sanctum::actingAs($this->staff('super-admin'));

        $this->putJson('/api/v1/admin/settings', ['notify_emails' => 'boss@example.com, not-an-email'])
            ->assertUnprocessable()->assertJsonValidationErrors('notify_emails');
        $this->putJson('/api/v1/admin/settings', ['notification_channels' => ['low_stock' => ['fax']]])
            ->assertUnprocessable();
        $this->putJson('/api/v1/admin/settings', [
            'notify_emails' => 'boss@example.com, owner@example.com',
            'notify_whatsapp_numbers' => '0712000000',
            'notification_channels' => ['low_stock' => ['whatsapp', 'email']],
        ])->assertOk();

        $this->getJson('/api/v1/admin/settings')->assertOk()
            ->assertJsonPath('data.messaging.configured.whatsapp', true)
            ->assertJsonFragment(['key' => 'low_stock', 'audience' => 'staff']);
    }

    // ------------------------------------------------------------ staff notifications

    public function test_low_stock_reaches_the_bell_by_permission_and_branch_and_the_chosen_channels(): void
    {
        $this->settings()->update([
            'notify_sms_numbers' => '0755000000', 'notify_emails' => 'owner@example.com',
            'notification_channels' => ['low_stock' => ['sms', 'email'], 'out_of_stock' => []],
        ]);
        $product = $this->productWithStock(10, ['reorder_level' => 5, 'name' => 'Sugar 1kg']);
        $this->actingAsStaff('cashier');
        $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 6]],
            'payments' => [['method' => 'cash', 'amount' => 100000]],
        ])->assertCreated();

        $notification = AppNotification::where('type', 'low_stock')->sole();
        $this->assertSame($this->mainBranch()->id, $notification->branch_id);
        $this->assertDatabaseHas('sms_logs', ['channel' => 'sms', 'recipient' => '255755000000', 'purpose' => 'low_stock']);
        Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo('owner@example.com') && $m->pdf !== null);

        // A second sale below the line does not repeat the alert.
        $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 100000]],
        ])->assertCreated();
        $this->assertSame(1, AppNotification::where('type', 'low_stock')->count());

        // Who sees it: stock.alerts in this branch, yes; no permission or another branch, no.
        Sanctum::actingAs($this->staff('storekeeper'));
        $this->getJson('/api/v1/admin/notifications/summary')->assertOk()
            ->assertJsonPath('data.unread', 1)
            ->assertJsonPath('data.latest.0.title', 'Sugar 1kg is running low in '.$this->mainBranch()->name);

        Sanctum::actingAs($this->staff('seller'));
        $this->getJson('/api/v1/admin/notifications/summary')->assertJsonPath('data.unread', 0);

        Sanctum::actingAs($this->staff('cashier', Branch::factory()->create()));
        $this->getJson('/api/v1/admin/notifications/summary')->assertJsonPath('data.unread', 0);
    }

    public function test_reading_notifications_is_per_user(): void
    {
        $product = $this->productWithStock(3, ['reorder_level' => 1]);
        $this->actingAsStaff('cashier');
        $this->postJson('/api/v1/admin/pos/checkout', ['items' => [['product_id' => $product->id, 'quantity' => 3]], 'payments' => [['method' => 'cash', 'amount' => 100000]]])->assertCreated();
        $id = AppNotification::where('type', 'out_of_stock')->sole()->id;

        $alice = $this->staff('storekeeper');
        $bob = $this->staff('storekeeper');
        Sanctum::actingAs($alice);
        $this->postJson("/api/v1/admin/notifications/{$id}/read")->assertOk();
        $this->getJson('/api/v1/admin/notifications/summary')->assertJsonPath('data.unread', 0)->assertJsonPath('data.latest.0.read', true);

        Sanctum::actingAs($bob);
        $this->getJson('/api/v1/admin/notifications?unread=1')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/admin/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 1);
        $this->getJson('/api/v1/admin/notifications/summary')->assertJsonPath('data.unread', 0);
    }

    public function test_notifications_are_shown_in_the_readers_language(): void
    {
        $product = $this->productWithStock(2, ['name' => 'Sukari']);
        $this->actingAsStaff('cashier');
        $this->postJson('/api/v1/admin/pos/checkout', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 100000]]])->assertCreated();

        $title = $this->withHeader('X-Locale', 'sw')->getJson('/api/v1/admin/notifications/summary')->json('data.latest.0.title');
        $this->assertStringStartsWith('Sukari', $title);
        $this->assertStringNotContainsString('out of stock', $title);
    }

    public function test_scheduled_report_goes_to_the_bell_and_the_chosen_channels(): void
    {
        $this->settings()->update([
            'notify_sms_numbers' => '0755000000', 'notify_whatsapp_numbers' => '0766000000', 'notify_emails' => 'owner@example.com',
            'notification_channels' => ['sales_report' => ['sms', 'whatsapp', 'email']],
        ]);
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(10, ['selling_price' => 1000]);
        $this->postJson('/api/v1/admin/pos/checkout', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 2000]]])->assertCreated();

        $this->artisan('mauzo:send-report', ['period' => 'daily'])->assertSuccessful();

        $this->assertDatabaseHas('app_notifications', ['type' => 'sales_report', 'permission' => 'reports.sales']);
        $sms = SmsLog::where('channel', 'sms')->where('purpose', 'report')->sole();
        $this->assertStringContainsString('2,000', $sms->message);
        $this->assertDatabaseHas('sms_logs', ['channel' => 'whatsapp', 'recipient' => '255766000000', 'purpose' => 'report']);
        Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo('owner@example.com') && $m->pdf !== null && str_ends_with($m->pdfName, '.pdf'));

        // Running it again the same day does not send twice, unless forced.
        $this->artisan('mauzo:send-report', ['period' => 'daily'])->assertSuccessful();
        $this->assertSame(1, SmsLog::where('channel', 'sms')->where('purpose', 'report')->count());
        $this->artisan('mauzo:send-report', ['period' => 'daily', '--force' => true])->assertSuccessful();
        $this->assertSame(2, SmsLog::where('channel', 'sms')->where('purpose', 'report')->count());
    }

    public function test_morning_alerts_summarise_stock_and_overdue_debts_per_branch(): void
    {
        $this->settings()->update(['notify_emails' => 'owner@example.com', 'notification_channels' => ['stock_digest' => ['email'], 'debt_overdue' => ['email'], 'out_of_stock' => [], 'low_stock' => []]]);
        $this->productWithStock(2, ['reorder_level' => 5]);
        $customer = Customer::factory()->create(['allow_credit' => true]);
        $product = $this->productWithStock(10, ['selling_price' => 3000]);
        $this->actingAsStaff('cashier');
        $saleId = $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $customer->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [], 'due_date' => now()->addDay()->toDateString(),
        ])->assertCreated()->json('data.id');
        Sale::whereKey($saleId)->update(['due_date' => now()->subDays(5)->toDateString()]);

        $this->artisan('mauzo:daily-alerts')->assertSuccessful();

        $this->assertDatabaseHas('app_notifications', ['type' => 'stock_digest', 'branch_id' => $this->mainBranch()->id]);
        $this->assertDatabaseHas('app_notifications', ['type' => 'debt_overdue', 'branch_id' => $this->mainBranch()->id, 'permission' => 'credit.view']);
        Mail::assertSent(NotificationMail::class, fn ($m) => $m->document['title'] === 'Stock check');
        Mail::assertSent(NotificationMail::class, fn ($m) => $m->document['title'] === 'Overdue debts' && $m->document['sections'][0]['rows'][0][0] === $customer->name);
    }

    public function test_message_log_can_be_filtered_by_channel(): void
    {
        Sanctum::actingAs($this->staff('super-admin'));
        $this->postJson('/api/v1/admin/sms/send', [
            'audience' => 'numbers', 'numbers' => '0712345678', 'emails' => 'a@example.com', 'channels' => ['sms', 'email'], 'message' => 'Hello',
        ])->assertOk()->assertJsonPath('data.result.email.recipients', 1);

        $this->getJson('/api/v1/admin/audit/sms?channel=email')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.recipient', 'a@example.com');
    }
}

<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Catalog\Product;
use App\Models\Core\SmsLog;
use App\Models\Crm\Customer;
use App\Models\Sales\Sale;
use App\Models\Sales\SalesDocument;
use App\Services\Core\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesDocumentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['mauzo.sms.enabled' => true, 'mauzo.sms.token' => 't', 'mail.from.address' => 'shop@example.com']);
        Http::fake(['*' => Http::response(['status' => 'success'])]);
        Mail::fake();
        app(SettingsService::class)->update(['messaging_channels' => ['sms', 'whatsapp', 'email'], 'message_locale' => 'en', 'business_tin' => '100-200-300', 'business_vrn' => '40-012345-A']);
    }

    private function draft(string $type, Customer $customer, array $extra = []): array
    {
        $product = $this->productWithStock(20, ['selling_price' => 10000, 'name' => 'Cement 50kg']);

        return $this->postJson('/api/v1/admin/documents', $extra + [
            'type' => $type,
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'discount' => 1000]],
            'discount' => 500,
        ])->assertCreated()->json('data') + ['product_id' => $product->id];
    }

    public function test_quotation_is_sent_viewed_accepted_and_becomes_an_invoice_that_is_paid(): void
    {
        $customer = Customer::factory()->create(['email' => 'buyer@example.com', 'phone' => '0712345678', 'allow_credit' => true, 'credit_limit' => 0, 'tin' => '999-888-777']);
        Sanctum::actingAs($this->staff('super-admin'));

        $q = $this->draft('quotation', $customer);
        $this->assertSame('draft', $q['stage']);
        $this->assertSame('28500.00', $q['total']); // 3 × 10,000 − 1,000 − 500
        $this->assertStringStartsWith('QT-', $q['number']);

        // Send by SMS + email: the email carries the real PDF, the SMS only says where it went.
        $res = $this->postJson("/api/v1/admin/documents/{$q['id']}/send", ['channels' => ['sms', 'email']])->assertOk();
        $this->assertSame('sent', $res->json('data.document.stage'));
        Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo('buyer@example.com') && str_starts_with((string) $m->pdf, '%PDF') && $m->pdfName === $q['number'].'.pdf');
        $this->assertStringContainsString('has been sent to your email', SmsLog::where('channel', 'sms')->latest('id')->first()->message);

        // The customer opens the link and accepts.
        $token = SalesDocument::find($q['id'])->public_token;
        $public = $this->getJson("/api/v1/public/documents/{$token}")->assertOk()->assertJsonMissingPath('data.brand.logo_path');
        $public->assertJsonPath('data.can_respond', true)->assertJsonPath('data.brand.tin', '100-200-300')->assertJsonPath('data.customer.tin', '999-888-777');
        $this->assertNotNull(SalesDocument::find($q['id'])->viewed_at);
        $this->postJson("/api/v1/public/documents/{$token}/respond", ['accept' => true])->assertOk();
        $this->assertSame('accepted', SalesDocument::find($q['id'])->stage);

        // Convert, issue: a credit sale is created, stock goes out, the customer owes.
        $inv = $this->postJson("/api/v1/admin/documents/{$q['id']}/convert", ['to' => 'invoice'])->assertCreated()->json('data');
        $this->assertSame('converted', SalesDocument::find($q['id'])->status);
        // Both sides of the conversion open, each linking to the other.
        $this->getJson("/api/v1/admin/documents/{$inv['id']}")->assertOk()->assertJsonPath('data.converted_from.id', $q['id']);
        $this->getJson("/api/v1/admin/documents/{$q['id']}")->assertOk()->assertJsonPath('data.converted_to.id', $inv['id']);
        $this->postJson("/api/v1/admin/documents/{$inv['id']}/issue")->assertOk()->assertJsonPath('data.stage', 'owing');
        $doc = SalesDocument::find($inv['id']);
        $this->assertSame(17.0, $this->onHand(Product::find($q['product_id'])));
        $this->assertSame('28500.00', $customer->fresh()->balance);
        $this->assertSame('invoice', $doc->sale->channel);

        // Part payment, then the rest.
        $this->postJson("/api/v1/admin/documents/{$inv['id']}/payments", ['amount' => 10000, 'method' => 'cash'])->assertOk()->assertJsonPath('data.stage', 'owing')->assertJsonPath('data.balance', 18500);
        $this->postJson("/api/v1/admin/documents/{$inv['id']}/payments", ['amount' => 18500, 'method' => 'mobile_money', 'reference' => 'MP123'])->assertOk()->assertJsonPath('data.stage', 'paid');
        $this->postJson("/api/v1/admin/documents/{$inv['id']}/close")->assertOk()->assertJsonPath('data.stage', 'closed');
        $this->assertLedgerBalanced();

        $this->get("/api/v1/admin/documents/{$inv['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_invoice_becomes_overdue_and_paying_at_the_till_updates_it(): void
    {
        $customer = Customer::factory()->create(['allow_credit' => true]);
        Sanctum::actingAs($this->staff('super-admin'));
        $inv = $this->draft('invoice', $customer, ['issue_date' => now()->subDays(20)->toDateString(), 'due_date' => now()->subDays(5)->toDateString()]);
        $this->postJson("/api/v1/admin/documents/{$inv['id']}/issue")->assertOk()->assertJsonPath('data.stage', 'overdue');

        $this->getJson('/api/v1/admin/documents?type=invoice&stage=overdue')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('counts.overdue', 1);

        // A debt payment taken elsewhere (customer page / till) clears the invoice too.
        $this->postJson("/api/v1/admin/customers/{$customer->id}/payments", ['amount' => 28500, 'method' => 'cash'])->assertCreated();
        $this->assertSame('paid', SalesDocument::find($inv['id'])->stage);
        $this->getJson('/api/v1/admin/documents?type=invoice&stage=paid')->assertJsonCount(1, 'data');
    }

    public function test_proforma_is_paid_in_full_and_creates_a_paid_sale(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($this->staff('super-admin'));
        $pf = $this->draft('proforma', $customer);
        $this->postJson("/api/v1/admin/documents/{$pf['id']}/send", ['channels' => ['sms']])->assertOk()->assertJsonPath('data.document.stage', 'awaiting_payment');

        $this->postJson("/api/v1/admin/documents/{$pf['id']}/payments", ['amount' => 10000, 'method' => 'cash'])->assertStatus(422)->assertJsonPath('code', 'PART_PAYMENT');
        $this->postJson("/api/v1/admin/documents/{$pf['id']}/payments", ['amount' => 28500, 'method' => 'bank', 'reference' => 'TRX9'])->assertOk()->assertJsonPath('data.stage', 'paid');

        $sale = SalesDocument::find($pf['id'])->sale;
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('28500.00', $sale->amount_paid);
        $this->postJson("/api/v1/admin/documents/{$pf['id']}/close")->assertOk()->assertJsonPath('data.stage', 'closed');
    }

    public function test_cancelling_an_issued_invoice_cancels_its_sale(): void
    {
        $customer = Customer::factory()->create(['allow_credit' => true]);
        Sanctum::actingAs($this->staff('super-admin'));
        $inv = $this->draft('invoice', $customer);
        $this->postJson("/api/v1/admin/documents/{$inv['id']}/issue")->assertOk();

        $this->postJson("/api/v1/admin/documents/{$inv['id']}/cancel", ['reason' => 'Customer changed order'])->assertOk()->assertJsonPath('data.stage', 'cancelled');
        $this->assertSame(Sale::STATUS_CANCELLED, SalesDocument::find($inv['id'])->sale->status);
        $this->assertSame(20.0, $this->onHand(Product::find($inv['product_id'])));
        $this->assertSame('0.00', $customer->fresh()->balance);
    }

    public function test_drafts_only_editable_and_cashier_cannot_issue(): void
    {
        $customer = Customer::factory()->create(['allow_credit' => true]);
        Sanctum::actingAs($this->staff('cashier'));
        $inv = $this->draft('invoice', $customer);
        $this->postJson("/api/v1/admin/documents/{$inv['id']}/issue")->assertForbidden();
        $this->postJson("/api/v1/admin/documents/{$inv['id']}/send", ['channels' => ['sms']])->assertForbidden();

        Sanctum::actingAs($this->staff('super-admin'));
        $this->postJson("/api/v1/admin/documents/{$inv['id']}/issue")->assertOk();
        $this->putJson("/api/v1/admin/documents/{$inv['id']}", ['type' => 'invoice', 'customer_id' => $customer->id, 'items' => [['product_id' => $inv['product_id'], 'quantity' => 1]]])
            ->assertStatus(422);
        $this->deleteJson("/api/v1/admin/documents/{$inv['id']}")->assertStatus(422);
    }

    public function test_quotation_for_a_walk_in_and_document_settings(): void
    {
        app(SettingsService::class)->update([
            'quotation_columns' => ['sku'],
            'quotation_blocks' => ['amount_in_words'],
            'document_company_fields' => ['quotation' => ['phone']],
        ]);
        Sanctum::actingAs($this->staff('super-admin'));
        $product = $this->productWithStock(5, ['selling_price' => 2500]);
        $q = $this->postJson('/api/v1/admin/documents', [
            'type' => 'quotation', 'customer' => ['name' => 'Juma Builders', 'tin' => '555'],
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'description' => 'Special order']],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/admin/documents/{$q['id']}/send", ['channels' => ['sms'], 'phone' => '0712000000'])->assertOk();

        $data = $this->getJson('/api/v1/public/documents/'.SalesDocument::find($q['id'])->public_token)->json('data');
        $this->assertSame(['sku'], $data['columns']);
        $this->assertSame('Special order', $data['items'][0]['description']);
        $this->assertNull($data['customer']['tin']);          // customer_tin block is off
        $this->assertSame('', $data['brand']['tin']);         // company TIN not chosen for quotations
        $this->assertStringContainsString('Five thousand', $data['amount_in_words']);
    }

    public function test_reports_cover_quotations_proformas_and_invoices(): void
    {
        $customer = Customer::factory()->create(['allow_credit' => true]);
        Sanctum::actingAs($this->staff('super-admin'));
        $q = $this->draft('quotation', $customer);
        $this->postJson("/api/v1/admin/documents/{$q['id']}/send", ['channels' => ['sms']])->assertOk();
        $this->postJson("/api/v1/admin/documents/{$q['id']}/respond", ['accept' => false, 'reason' => 'Too expensive'])->assertOk();
        $inv = $this->draft('invoice', $customer, ['due_date' => now()->addDay()->toDateString()]);
        $this->postJson("/api/v1/admin/documents/{$inv['id']}/issue")->assertOk();
        SalesDocument::whereKey($inv['id'])->update(['due_date' => now()->subDays(40)->toDateString()]);

        $this->getJson('/api/v1/admin/reports/quotations')->assertOk()->assertJsonPath('report.summary.declined', 1);
        $this->getJson('/api/v1/admin/reports/invoices')->assertOk()->assertJsonPath('report.summary.overdue', 1)->assertJsonPath('report.summary.outstanding', 28500);
        $this->getJson('/api/v1/admin/reports/invoice_aging')->assertOk()->assertJsonPath('report.totals.d31_60', 28500);
    }
}

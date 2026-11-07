<?php

namespace Tests\Feature;

use App\Models\Catalog\Product;
use App\Services\Core\SettingsService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCodesTest extends TestCase
{
    public function test_every_product_gets_a_qr_token(): void
    {
        $product = Product::factory()->create();

        $this->assertMatchesRegularExpression('/^[a-z0-9]{16}$/', $product->qr_token);
        $this->assertStringEndsWith('/q/'.$product->qr_token, $product->qrUrl());
    }

    public function test_generate_barcodes_for_ticked_or_all_matching_products(): void
    {
        Sanctum::actingAs($this->staff('storekeeper'));
        $has = Product::factory()->create(['barcode' => '6001234567890', 'name' => 'Rice 5kg']);
        $a = Product::factory()->create(['barcode' => null, 'name' => 'Rice 1kg']);
        $b = Product::factory()->create(['barcode' => null, 'name' => 'Beans']);

        $this->postJson('/api/v1/admin/products/barcodes', ['ids' => [$a->id, $has->id]])->assertOk()->assertJsonPath('data.generated', 1);
        $this->assertMatchesRegularExpression('/^20\d{11}$/', $a->fresh()->barcode);
        $this->assertSame('6001234567890', $has->fresh()->barcode); // kept
        $this->assertNull($b->fresh()->barcode);

        // "Select all" uses the list filters.
        $this->postJson('/api/v1/admin/products/barcodes', ['all' => true, 'search' => 'Beans'])->assertOk()->assertJsonPath('data.generated', 1);
        $this->assertNotNull($b->fresh()->barcode);

        app(SettingsService::class)->update(['barcode_enabled' => false]);
        $this->postJson('/api/v1/admin/products/barcodes', ['ids' => [$a->id]])->assertStatus(422)->assertJsonPath('code', 'BARCODES_OFF');
    }

    public function test_labels_carry_the_qr_link_or_the_chosen_details_as_text(): void
    {
        Sanctum::actingAs($this->staff('storekeeper'));
        $p = Product::factory()->create(['name' => 'Cooking oil 1L', 'selling_price' => 6500, 'description' => 'Pure sunflower oil']);

        $link = $this->postJson('/api/v1/admin/products/labels', ['ids' => [$p->id]])->assertOk()->json('data.labels.0.qr');
        $this->assertStringEndsWith('/q/'.$p->qr_token, $link);

        app(SettingsService::class)->update(['qr_mode' => 'text', 'qr_public_fields' => ['name', 'price']]);
        $text = $this->postJson('/api/v1/admin/products/labels', ['ids' => [$p->id]])->json('data.labels.0.qr');
        $this->assertStringContainsString('Cooking oil 1L', $text);
        $this->assertStringContainsString('6,500', $text);
        $this->assertStringNotContainsString('sunflower', $text); // description was not chosen

        app(SettingsService::class)->update(['qr_enabled' => false]);
        $this->postJson('/api/v1/admin/products/labels', ['ids' => [$p->id]])->assertJsonPath('data.labels.0.qr', null);
    }

    public function test_customers_who_scan_see_only_the_chosen_details(): void
    {
        $p = Product::factory()->create(['name' => 'Soap', 'selling_price' => 1200, 'cost_price' => 700, 'sku' => 'SOAP-1']);
        app(SettingsService::class)->update(['qr_public_fields' => ['name', 'price']]);

        $res = $this->getJson("/api/v1/public/products/scan/{$p->qr_token}")->assertOk();
        $this->assertSame(['name', 'price'], array_keys($res->json('data.product')));
        $this->assertStringNotContainsString('700', json_encode($res->json()));
        $this->assertNull($res->json('data.business'));

        app(SettingsService::class)->update(['qr_enabled' => false]);
        $this->getJson("/api/v1/public/products/scan/{$p->qr_token}")->assertNotFound();
    }

    public function test_staff_who_scan_see_what_their_role_allows(): void
    {
        $p = $this->productWithStock(8, ['selling_price' => 1000, 'cost_price' => 600]);

        // Cashier: product and stock, but not cost or sales figures.
        Sanctum::actingAs($this->staff('cashier'));
        $cashier = $this->getJson("/api/v1/admin/products/scan/{$p->qr_token}")->assertOk()->json('data');
        $this->assertArrayHasKey('stock', $cashier['sections']);
        $this->assertArrayNotHasKey('cost', $cashier['sections']);
        $this->assertArrayNotHasKey('sales', $cashier['sections']);
        $this->assertContains('sell', array_column($cashier['actions'], 'key'));
        $this->assertEquals(8, $cashier['sections']['stock']['total']);

        // Accountant: sales figures, no stock-adjust action.
        Sanctum::actingAs($this->staff('accountant'));
        $acc = $this->getJson("/api/v1/admin/products/scan/{$p->qr_token}")->assertOk()->json('data');
        $this->assertArrayHasKey('sales', $acc['sections']);
        $this->assertNotContains('adjust', array_column($acc['actions'], 'key'));

        // Super admin: everything, including margin.
        Sanctum::actingAs($this->staff('super-admin'));
        $all = $this->getJson("/api/v1/admin/products/scan/{$p->qr_token}")->assertOk()->json('data');
        $this->assertEquals(40.0, $all['sections']['cost']['margin_percent']);
    }

    public function test_the_till_finds_a_product_by_its_qr_code(): void
    {
        Sanctum::actingAs($this->staff('cashier'));
        $p = $this->productWithStock(3);

        $this->getJson('/api/v1/admin/products/lookup?code='.urlencode($p->qrUrl()))->assertOk()->assertJsonPath('data.id', $p->id);
        $this->getJson('/api/v1/admin/products/lookup?code='.$p->qr_token)->assertOk()->assertJsonPath('data.id', $p->id);
    }
}

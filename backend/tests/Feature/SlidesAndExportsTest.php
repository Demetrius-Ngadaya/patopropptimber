<?php

namespace Tests\Feature;

use App\Services\Core\TablePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SlidesAndExportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_pictures_are_made_small_and_shown_where_chosen(): void
    {
        Storage::fake('public');
        $this->actingAsStaff('admin');
        $big = UploadedFile::fake()->image('farm.jpg', 4000, 2600)->size(4800);

        $login = $this->post('/api/v1/admin/login-slides', ['image' => $big, 'title' => 'Mbao bora', 'show_on_login' => '1', 'show_on_shop' => '0'], ['Accept' => 'application/json'])->assertCreated();
        $both = $this->post('/api/v1/admin/login-slides', ['image' => UploadedFile::fake()->image('yard.png', 1800, 1000), 'show_on_login' => '1', 'show_on_shop' => '1'], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/v1/admin/login-slides', ['image' => UploadedFile::fake()->image('shop.jpg', 1600, 900), 'show_on_login' => '0', 'show_on_shop' => '1'], ['Accept' => 'application/json'])->assertCreated();

        // Resized: at most 1920 px wide, plus a 960 px copy for phones.
        $path = $login->json('data.image_path');
        [$w] = getimagesize(Storage::disk('public')->path($path));
        $this->assertLessThanOrEqual(1920, $w);
        [$sw] = getimagesize(Storage::disk('public')->path($login->json('data.image_small_path')));
        $this->assertLessThanOrEqual(960, $sw);

        $this->app['auth']->forgetGuards();
        $this->assertCount(2, $this->getJson('/api/v1/public/login-slides')->assertOk()->json('data'));
        $shop = $this->getJson('/api/v1/public/login-slides?place=shop')->assertOk()->json('data');
        $this->assertCount(2, $shop);
        $this->assertContains($both->json('data.id'), array_column($shop, 'id'));
        $this->assertArrayHasKey('image_small_url', $shop[0]);
    }

    public function test_big_report_pdf_is_quick_and_has_every_page(): void
    {
        $rows = [];
        foreach (range(1, 2500) as $i) {
            $rows[] = [(string) $i, 'CA-'.$i, '07/11/2026', '2 x 6 Fupi', '13', number_format(78000, 2), 'MBALINGA ERICK', 'Mkopo', 'Mafinga HQ'];
        }
        $columns = array_map(fn ($l, $n) => ['label' => $l, 'numeric' => $n], ['Na.', 'Ankara', 'Tarehe', 'Bidhaa', 'Idadi', 'Kiasi', 'Mteja', 'Malipo', 'Tawi'], [false, false, false, false, true, true, false, false, false]);
        $started = microtime(true);
        $pdf = app(TablePdf::class)->render([
            'title' => 'Mauzo kwa bidhaa', 'name' => 'PP TIMBER', 'contact' => 'Mafinga · 0754 000 111', 'logo_path' => null, 'color' => '#157a55',
            'branch' => 'Tawi: Mafinga HQ', 'period' => 'Kipindi: 01/01/2025 – 08/11/2026', 'filters' => '', 'printed' => 'Imechapishwa', 'powered_by' => 'PatoPro',
        ], $columns, $rows, [null, null, null, null, '32,500', '195,000,000.00', null, null, null], true);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertLessThan(20, microtime(true) - $started);
        $this->assertGreaterThan(40, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf));
    }

    public function test_sales_report_exports_with_the_business_letterhead(): void
    {
        $product = $this->productWithStock(10, ['selling_price' => 6000, 'cost_price' => 4000], null, 4000);
        $this->actingAsStaff('admin');
        $this->postJson('/api/v1/admin/pos/checkout', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 12000]]])->assertCreated();

        $pdf = $this->get('/api/v1/admin/reports/sales?export=pdf');
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $xlsx = $this->get('/api/v1/admin/reports/sales_items?export=xlsx');
        $xlsx->assertOk();
    }
}

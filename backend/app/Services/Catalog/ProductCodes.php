<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Product;
use App\Models\Core\User;
use App\Models\Inventory\ProductStock;
use App\Models\Inventory\StockBatch;
use App\Models\Sales\SaleItem;
use App\Services\Core\SettingsService;
use App\Support\SettingDefinitions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Barcodes and QR codes for products: generating barcodes in bulk, what a
 * QR code holds, and what someone sees when they scan one.
 */
class ProductCodes
{
    public function __construct(
        private readonly ProductService $products,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Give every product in the list a barcode (and a QR token). Existing
     * barcodes are kept unless $overwrite.
     *
     * @param  Collection<int, Product>  $products
     * @return int how many barcodes were created
     */
    public function generateBarcodes(Collection $products, bool $overwrite = false): int
    {
        $made = 0;
        DB::transaction(function () use ($products, $overwrite, &$made) {
            foreach ($products as $product) {
                $fill = [];
                if ($overwrite || blank($product->barcode)) {
                    $fill['barcode'] = $this->products->generateBarcode($product->id);
                    $made++;
                }
                if (blank($product->qr_token)) {
                    $fill['qr_token'] = Product::newQrToken();
                }
                if ($fill) {
                    $product->forceFill($fill)->saveQuietly();
                }
            }
        });

        return $made;
    }

    /** What the QR code itself contains: the product link, or the chosen details as text. */
    public function qrPayload(Product $product): string
    {
        if ($this->settings->get('qr_mode') !== 'text') {
            return $product->qrUrl();
        }
        $view = $this->publicView($product);
        $lines = [];
        foreach ($view['fields'] as $field) {
            $value = $view['product'][$field] ?? null;
            if ($value === null || $value === '' || in_array($field, ['image'], true)) {
                continue;
            }
            $lines[] = $field === 'name' ? (string) $value : __(ucfirst(str_replace('_', ' ', $field))).': '.$value;
        }
        if ($view['business']) {
            $lines[] = trim($view['business']['name'].' '.$view['business']['phone']);
        }

        return mb_substr(implode("\n", $lines), 0, 600); // short enough to stay easy to scan
    }

    /** What a customer (not logged in) sees: only the details the admin chose. */
    public function publicView(Product $product): array
    {
        $fields = array_values(array_intersect(SettingDefinitions::QR_FIELDS, (array) $this->settings->get('qr_public_fields', [])));
        $currency = (string) $this->settings->get('currency');
        $product->loadMissing(['category:id,name', 'unit:id,name,short_name']);

        $all = [
            'name' => $product->name,
            'price' => $currency.' '.number_format((float) $product->selling_price),
            'wholesale_price' => (float) $product->wholesale_price > 0
                ? $currency.' '.number_format((float) $product->wholesale_price).((float) $product->wholesale_min_qty > 0 ? ' ('.__('from :qty', ['qty' => 0 + (float) $product->wholesale_min_qty]).')' : '')
                : null,
            'description' => app()->getLocale() === 'sw' && $product->description_sw ? $product->description_sw : $product->description,
            'category' => $product->category?->name,
            'brand' => $product->brand,
            'unit' => $product->unit?->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'image' => $product->image_url,
            'availability' => $product->is_service ? null : ((float) ProductStock::query()->where('product_id', $product->id)->sum('quantity') > 0 ? __('In stock') : __('Out of stock')),
            'business' => null,
        ];
        $logo = $this->settings->get('business_logo');

        return [
            'fields' => $fields,
            'product' => array_intersect_key($all, array_flip($fields)),
            'business' => in_array('business', $fields, true) ? [
                'name' => (string) $this->settings->get('business_name'),
                'phone' => (string) $this->settings->get('business_phone'),
                'address' => (string) $this->settings->get('business_address'),
                'logo_url' => $logo ? Storage::disk('public')->url($logo) : null,
            ] : null,
            'shop_url' => $product->show_online && $this->settings->bool('shop_enabled') && $product->slug ? '/products/'.$product->slug : null,
        ];
    }

    /**
     * What a signed-in staff member sees. Each block appears only with the
     * permission behind it, so a cashier, a storekeeper and the owner see
     * different things for the same code.
     *
     * @param  array<int>  $branchIds  branches the user may see
     */
    public function staffView(Product $product, User $user, array $branchIds): array
    {
        $can = fn (string ...$keys) => collect($keys)->contains(fn ($k) => $user->hasPermission($k));
        $product->loadMissing(['category:id,name', 'unit:id,name,short_name']);
        $unit = $product->unit?->short_name ?? '';

        $view = [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'category' => $product->category?->name,
                'unit' => $unit,
                'image_url' => $product->image_url,
                'selling_price' => (float) $product->selling_price,
                'wholesale_price' => (float) $product->wholesale_price ?: null,
                'wholesale_min_qty' => (float) $product->wholesale_min_qty ?: null,
                'is_active' => $product->is_active,
            ],
            'sections' => [],
            'actions' => array_values(array_filter([
                $can('products.view') ? ['key' => 'open', 'url' => "/admin/products/{$product->id}"] : null,
                $can('pos.access') && $product->is_active ? ['key' => 'sell', 'url' => '/admin/pos?code='.urlencode($product->barcode ?: ($product->sku ?? ''))] : null,
                $can('stock.adjust') ? ['key' => 'adjust', 'url' => "/admin/products/{$product->id}?adjust=1"] : null,
                $can('products.print_labels') ? ['key' => 'labels', 'url' => "/admin/products/labels?ids={$product->id}"] : null,
            ])),
        ];

        if ($can('products.view_cost')) {
            $cost = (float) $product->cost_price;
            $price = (float) $product->selling_price;
            $view['sections']['cost'] = [
                'cost_price' => $cost,
                'margin_percent' => $price > 0 ? round(($price - $cost) / $price * 100, 1) : null,
            ];
        }

        if ($can('stock.view', 'stock.alerts', 'products.view') && ! $product->is_service) {
            $stocks = ProductStock::query()->where('product_id', $product->id)->whereIn('branch_id', $branchIds)->with('branch:id,name')->get();
            $nextExpiry = StockBatch::query()->where('product_id', $product->id)->whereIn('branch_id', $branchIds)
                ->where('quantity_remaining', '>', 0)->whereNotNull('expiry_date')->orderBy('expiry_date')->first();
            $view['sections']['stock'] = [
                'unit' => $unit,
                'reorder_level' => (float) $product->reorder_level,
                'total' => round((float) $stocks->sum('quantity'), 3),
                'branches' => $stocks->map(fn ($s) => ['branch' => $s->branch?->name, 'quantity' => (float) $s->quantity])->values(),
                'next_expiry' => $nextExpiry ? ['date' => $nextExpiry->expiry_date->toDateString(), 'quantity' => (float) $nextExpiry->quantity_remaining] : null,
            ];
        }

        if ($can('reports.sales', 'sales.view_all_users', 'dashboard.view_financials')) {
            $base = fn ($from) => SaleItem::query()->where('product_id', $product->id)
                ->whereHas('sale', fn ($q) => $q->completed()->whereIn('branch_id', $branchIds)->where('sold_at', '>=', $from));
            $period = fn ($from) => ['quantity' => round((float) $base($from)->sum('quantity'), 3), 'amount' => round((float) $base($from)->sum('line_total'), 2)];
            $last = SaleItem::query()->where('product_id', $product->id)
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sales.status', 'completed')->whereIn('sales.branch_id', $branchIds)
                ->max('sales.sold_at');
            $view['sections']['sales'] = [
                'today' => $period(now()->startOfDay()),
                'days_7' => $period(now()->subDays(7)),
                'days_30' => $period(now()->subDays(30)),
                'last_sold_at' => $last ? Carbon::parse($last)->toIso8601String() : null,
            ];
        }

        return $view;
    }
}

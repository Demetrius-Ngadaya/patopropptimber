<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Services\Catalog\ProductCodes;
use App\Services\Core\AuditLogger;
use App\Services\Core\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Barcode generation, label data and QR scanning. */
class ProductCodeController extends Controller
{
    /** Most labels one print job can hold. */
    private const MAX_LABELS = 3000;

    public function __construct(
        private readonly ProductCodes $codes,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** Generate barcodes for the ticked products, or for every product matching the filters. */
    public function generate(Request $request): JsonResponse
    {
        if (! $this->settings->bool('barcode_enabled')) {
            throw new BusinessException(__('Barcodes are switched off in Settings.'), 'BARCODES_OFF');
        }
        $data = $request->validate($this->selectionRules() + ['overwrite' => ['boolean']]);
        $products = $this->selection($request, $data)->get(['id', 'barcode', 'qr_token']);
        $made = $this->codes->generateBarcodes($products, (bool) ($data['overwrite'] ?? false));

        $this->audit->event('products.barcodes_generated', __(':count barcodes generated', ['count' => $made]), null, [
            'products' => $products->count(), 'overwrite' => (bool) ($data['overwrite'] ?? false),
        ]);

        return $this->ok(['generated' => $made, 'products' => $products->count()], $made
            ? __(':count barcodes generated.', ['count' => $made])
            : __('All selected products already have a barcode.'));
    }

    /** Everything the label sheet needs, for the ticked products or all matching. */
    public function labels(Request $request): JsonResponse
    {
        $data = $request->validate($this->selectionRules());
        $products = $this->selection($request, $data)
            ->with('unit:id,short_name')
            ->orderBy('name')
            ->limit(self::MAX_LABELS + 1)
            ->get(['id', 'name', 'sku', 'barcode', 'qr_token', 'selling_price', 'wholesale_price', 'unit_id', 'slug', 'description', 'description_sw', 'brand', 'category_id', 'is_service', 'show_online', 'image_path', 'wholesale_min_qty']);
        if ($products->count() > self::MAX_LABELS) {
            throw new BusinessException(__('At most :max products can be printed at once. Narrow the selection.', ['max' => self::MAX_LABELS]));
        }
        $qr = $this->settings->bool('qr_enabled');
        // Products created before QR codes existed get their token now.
        $products->filter(fn ($p) => blank($p->qr_token))->each(fn ($p) => $p->forceFill(['qr_token' => Product::newQrToken()])->saveQuietly());

        return $this->ok([
            'barcode_enabled' => $this->settings->bool('barcode_enabled'),
            'qr_enabled' => $qr,
            'business' => (string) $this->settings->get('business_name'),
            'currency' => (string) $this->settings->get('currency'),
            'labels' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'price' => (float) $p->selling_price,
                'wholesale_price' => (float) $p->wholesale_price ?: null,
                'unit' => $p->unit?->short_name,
                'qr' => $qr ? $this->codes->qrPayload($p) : null,
            ])->values(),
        ]);
    }

    /** A signed-in staff member scanned a QR code: what they see depends on their permissions. */
    public function scan(Request $request, string $token): JsonResponse
    {
        if (! $this->settings->bool('qr_enabled')) {
            throw new BusinessException(__('QR codes are switched off in Settings.'), 'QR_OFF', 404);
        }
        $product = Product::query()->where('qr_token', strtolower($token))->firstOrFail();

        return $this->ok($this->codes->staffView($product, $request->user(), $this->branch()->accessibleIds()));
    }

    /** Anyone (a customer) scanned a QR code: only the details the admin chose. */
    public function publicScan(string $token): JsonResponse
    {
        if (! $this->settings->bool('qr_enabled')) {
            abort(404);
        }
        $product = Product::query()->active()->where('qr_token', strtolower($token))->firstOrFail();

        return $this->ok($this->codes->publicView($product));
    }

    private function selectionRules(): array
    {
        return [
            'ids' => ['required_without:all', 'nullable', 'array', 'max:'.self::MAX_LABELS],
            'ids.*' => ['integer'],
            'all' => ['boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /** The ticked ids, or every product matching the list filters ("select all"). */
    private function selection(Request $request, array $data): Builder
    {
        $query = Product::query();
        if (! empty($data['all'])) {
            return $query->search($data['search'] ?? null)
                ->when($data['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
                ->when(array_key_exists('active', $data) && $data['active'] !== null, fn ($q) => $q->where('is_active', (bool) $data['active']));
        }

        return $query->whereIn('id', $data['ids'] ?? []);
    }
}

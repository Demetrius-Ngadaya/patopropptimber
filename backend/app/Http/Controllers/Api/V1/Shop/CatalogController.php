<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Core\Branch;
use App\Services\Core\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public storefront: only products marked "show online". Never exposes cost
 * prices, and shows availability instead of exact stock unless the admin allows it.
 */
class CatalogController extends Controller
{
    private const PUBLIC_FIELDS = ['id', 'name', 'slug', 'category_id', 'unit_id', 'brand', 'description', 'description_sw', 'image_path', 'selling_price', 'allow_decimal', 'is_service'];

    public function __construct(private readonly SettingsService $settings) {}

    public function branches(): JsonResponse
    {
        return $this->ok(Branch::query()->where('is_active', true)->where('accepts_online_orders', true)
            ->orderByDesc('is_main')->orderBy('name')->get(['id', 'name', 'location', 'address', 'phone']));
    }

    public function categories(): JsonResponse
    {
        return $this->ok(Category::query()->where('is_active', true)->where('show_online', true)
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)->where('show_online', true)])
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent_id', 'image_path']));
    }

    public function products(Request $request): JsonResponse
    {
        $branchId = $request->integer('branch_id') ?: null;

        $query = $this->baseQuery($branchId)
            ->search($request->query('search'))
            ->when($request->query('category'), fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when($request->query('min_price'), fn ($q, $v) => $q->where('selling_price', '>=', (float) $v))
            ->when($request->query('max_price'), fn ($q, $v) => $q->where('selling_price', '<=', (float) $v));

        match ($request->query('sort')) {
            'price_asc' => $query->orderBy('selling_price'),
            'price_desc' => $query->orderByDesc('selling_price'),
            'newest' => $query->latest(),
            default => $query->orderBy('name'),
        };

        $page = $query->paginate(min(max($request->integer('per_page', 24), 6), 60));
        $page->getCollection()->transform(fn (Product $p) => $this->present($p));

        return $this->paginated($page);
    }

    public function product(Request $request, string $slug): JsonResponse
    {
        $product = $this->baseQuery($request->integer('branch_id') ?: null)->where('slug', $slug)->firstOrFail();

        $related = $this->baseQuery(null)
            ->where('category_id', $product->category_id)->whereKeyNot($product->id)
            ->inRandomOrder()->limit(4)->get()
            ->map(fn (Product $p) => $this->present($p));

        return $this->ok(['product' => $this->present($product), 'related' => $related]);
    }

    private function baseQuery(?int $branchId): Builder
    {
        $online = Branch::query()->where('is_active', true)->where('accepts_online_orders', true)->pluck('id')->all();

        return Product::query()
            ->select(self::PUBLIC_FIELDS)
            ->where('is_active', true)
            ->where('show_online', true)
            ->with(['category:id,name,slug', 'unit:id,name,short_name'])
            ->withStock($branchId && in_array($branchId, $online, true) ? $branchId : null, $online);
    }

    private function present(Product $product): array
    {
        $qty = (float) ($product->stock_quantity ?? 0);
        $data = $product->only(['id', 'name', 'slug', 'brand', 'description', 'description_sw', 'selling_price', 'allow_decimal', 'image_url']);
        $data['category'] = $product->category?->only(['id', 'name', 'slug']);
        $data['unit'] = $product->unit?->short_name;
        $data['in_stock'] = $product->is_service || $qty > 0;
        if ($this->settings->bool('shop_show_stock')) {
            $data['available_quantity'] = $product->is_service ? null : $qty;
        }

        return $data;
    }
}

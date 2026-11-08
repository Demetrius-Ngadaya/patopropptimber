<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CheckoutRequest;
use App\Models\Catalog\Product;
use App\Models\Sales\HeldSale;
use App\Services\Catalog\BranchPrices;
use App\Services\Mobile\SyncPusher;
use App\Services\Sales\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    /** Product search for the till: active products with stock in this branch. */
    public function products(Request $request): JsonResponse
    {
        $branchId = $this->branch()->requireId();
        $search = trim((string) $request->query('search', ''));
        // Without a search, the products this branch sold most in the last 60 days come first,
        // so the till never loads the whole catalogue; anything else is found by searching.
        $popular = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.branch_id', $branchId)
            ->where('sales.status', 'completed')
            ->where('sales.sold_at', '>=', now()->subDays(60))
            ->groupBy('sale_items.product_id')
            ->select('sale_items.product_id', DB::raw('COUNT(*) as times_sold'));
        // select() before withStock(): keeps cost prices out of the cashier's screen.
        $products = Product::query()->active()
            ->select(['products.id', 'name', 'sku', 'barcode', 'category_id', 'unit_id', 'selling_price', 'wholesale_price', 'wholesale_min_qty', 'min_selling_price', 'tax_rate', 'allow_decimal', 'is_service', 'track_expiry', 'image_path'])
            ->with(['unit:id,short_name', 'category:id,name', 'units' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('factor')->select(['id', 'product_id', 'name', 'factor', 'barcode', 'retail_price', 'wholesale_price'])])
            ->withStock($branchId)
            ->search($search ?: null)
            ->when($request->query('category_id'), fn ($q, $v) => $q->where('category_id', $v))
            ->when($search === '', fn ($q) => $q->leftJoinSub($popular, 'popular', 'popular.product_id', '=', 'products.id')
                // The quick grid shows what this branch has or sells, not every product of every branch.
                ->when(! $request->query('category_id'), fn ($q) => $q->where(fn ($w) => $w->whereNotNull('popular.times_sold')
                    ->orWhereHas('stocks', fn ($s) => $s->where('branch_id', $branchId)->where('quantity', '>', 0))))
                ->orderByRaw('COALESCE(popular.times_sold, 0) DESC'))
            ->orderBy('name')
            ->limit(min((int) $request->query('limit', 40), 100))
            ->get();
        // This branch's own prices, where it has them.
        app(BranchPrices::class)->apply($products, $branchId);

        return $this->ok($products);
    }

    /** Live price check for the till (no sale is created). */
    public function quote(Request $request, SaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'price_level' => ['nullable', 'in:retail,wholesale'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'promotion_id' => ['nullable', 'integer', 'exists:promotions,id'],
            'items' => ['required', 'array', 'max:300'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_unit_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);
        if (($data['price_level'] ?? null) === 'wholesale' && ! $request->user()->hasPermission('pos.wholesale')) {
            $data['price_level'] = null; // the customer type decides
        }

        return $this->ok($sales->quote($data, $this->branch()->id()));
    }

    public function checkout(CheckoutRequest $request, SaleService $sales): JsonResponse
    {
        $data = $request->validated();
        // Replayed by the mobile sync: when the sale really happened on the phone.
        if ($offlineAt = $request->attributes->get(SyncPusher::OFFLINE_SOLD_AT)) {
            $data['offline_sold_at'] = $offlineAt;
            unset($data['sold_at']);
        }
        $sale = $sales->create($data, $request->user(), $this->branch()->requireId());

        if (! $request->user()->hasPermission('sales.view_profit')) {
            $sale->makeHidden('cost_total');
            $sale->items->each->makeHidden('unit_cost');
        }

        $message = (float) $sale->change_given > 0
            ? __('Sale :number saved. Change: :change', ['number' => $sale->number, 'change' => number_format((float) $sale->change_given, 2)])
            : __('Sale :number saved.', ['number' => $sale->number]);

        return $this->created($sale, $message);
    }

    public function held(Request $request): JsonResponse
    {
        $held = HeldSale::query()
            ->where('branch_id', $this->branch()->requireId())
            ->when(! $request->user()->hasPermission('sales.view_all_users'), fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['user', 'customer:id,name,phone'])
            ->latest()
            ->get();

        return $this->ok($held);
    }

    public function hold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'cart' => ['required', 'array', 'min:1', 'max:300'],
            'cart.*.product_id' => ['required', 'integer'],
            'cart.*.quantity' => ['required', 'numeric', 'gt:0'],
            'cart.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'cart.*.discount' => ['nullable', 'numeric', 'min:0'],
            'cart.*.name' => ['nullable', 'string', 'max:255'],
            'cart.*.unit' => ['nullable', 'string', 'max:20'],
            'cart.*.allow_decimal' => ['nullable', 'boolean'],
            'cart.*.is_service' => ['nullable', 'boolean'],
            'cart.*.list_price' => ['nullable', 'numeric', 'min:0'],
            'cart.*.available' => ['nullable', 'numeric'],
            'total' => ['nullable', 'numeric', 'min:0'],
        ]);

        $held = HeldSale::create($data + [
            'branch_id' => $this->branch()->requireId(),
            'user_id' => $request->user()->id,
            'total' => $data['total'] ?? 0,
        ]);

        return $this->created($held, __('Sale put on hold. Resume it from the held sales list.'));
    }

    public function discardHeld(Request $request, int $id): JsonResponse
    {
        $held = HeldSale::query()->where('branch_id', $this->branch()->requireId())->findOrFail($id);
        abort_if((int) $held->user_id !== $request->user()->id && ! $request->user()->hasPermission('sales.view_all_users'), 403);
        $held->delete();

        return $this->ok(null, __('Held sale discarded.'));
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductUnit;
use App\Models\Inventory\ProductStock;
use App\Models\Inventory\StockBatch;
use App\Models\Inventory\StockMovement;
use App\Services\Catalog\BranchPrices;
use App\Services\Catalog\ProductCodes;
use App\Services\Catalog\ProductService;
use App\Services\Core\AuditLogger;
use App\Services\Core\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $this->branch()->id();

        $query = $this->applyTrashed(Product::query(), $request)
            ->with(['category:id,name', 'unit:id,name,short_name'])
            ->withStock($branchId, $branchId ? null : $this->branch()->accessibleIds())
            ->search($request->query('search'))
            ->when($request->query('category_id'), fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->has('online'), fn ($q) => $q->where('show_online', $request->boolean('online')))
            ->when($request->query('stock') === 'low', fn ($q) => $q->whereHas('stocks', fn ($s) => $this->branch()->scope($s)->where('quantity', '>', 0)->whereColumn('product_stocks.quantity', '<=', 'products.reorder_level')))
            ->when($request->query('stock') === 'out', fn ($q) => $q->where('is_service', false)->whereDoesntHave('stocks', fn ($s) => $this->branch()->scope($s)->where('quantity', '>', 0)));

        // Stock value at buying and at selling price (each branch's own prices where it has them).
        $branchIds = $branchId ? [$branchId] : $this->branch()->accessibleIds();
        $value = fn (string $field) => ProductStock::query()
            ->selectRaw('COALESCE(SUM(product_stocks.quantity * '.BranchPrices::sql($field).'), 0)')
            ->whereColumn('product_stocks.product_id', 'products.id')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('product_stocks.branch_id', $branchIds ?: [0]));
        $canCost = $user->hasPermission('products.view_cost');
        $query->addSelect(['stock_retail_value' => $value('selling_price')]);
        if ($canCost) {
            $query->addSelect(['stock_cost_value' => $value('cost_price')]);
        }

        $this->sortable($query, $request, ['name', 'selling_price', 'created_at', 'updated_at', 'stock_quantity', 'stock_retail_value'], 'name', 'asc');

        // Totals of everything that matches the filters (not just this page).
        $sums = DB::query()->fromSub((clone $query)->toBase()->reorder(), 'p')
            ->selectRaw('COALESCE(SUM(stock_quantity), 0) as quantity, COALESCE(SUM(stock_retail_value), 0) as retail_value'.($canCost ? ', COALESCE(SUM(stock_cost_value), 0) as cost_value' : ''))
            ->first();
        $summary = ['quantity' => round((float) $sums->quantity, 3), 'retail_value' => round((float) $sums->retail_value, 2)];
        if ($canCost) {
            $summary['cost_value'] = round((float) $sums->cost_value, 2);
            $summary['expected_profit'] = round($summary['retail_value'] - $summary['cost_value'], 2);
        }

        $page = $query->paginate($this->perPage($request));
        // Prices shown are the selected branch's own prices where it has them.
        app(BranchPrices::class)->apply($page->getCollection(), $branchId);

        if (! $canCost) {
            $page->getCollection()->each->makeHidden(['cost_price', 'min_selling_price']);
        }

        return $this->paginated($page, ['summary' => $summary]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $product = Product::withTrashed()->with(['category', 'unit', 'units' => fn ($q) => $q->withTrashed()->orderBy('sort_order')->orderBy('factor'), 'creator', 'updater'])->findOrFail($id);

        $stocks = $this->branch()->scope(ProductStock::query()->where('product_id', $id))
            ->with('branch:id,name')->get(['id', 'branch_id', 'quantity']);
        $batches = $this->branch()->scope(StockBatch::query()->where('product_id', $id))
            ->where('quantity_remaining', '>', 0)->with('branch:id,name')
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date')->limit(100)->get();
        $movements = $this->branch()->scope(StockMovement::query()->where('product_id', $id))
            ->with(['user', 'branch:id,name'])->latest('id')->limit(25)->get();

        $canCost = $request->user()->hasPermission('products.view_cost');
        if (! $canCost) {
            $product->makeHidden(['cost_price', 'min_selling_price']);
            $batches->each->makeHidden('unit_cost');
            $movements->each->makeHidden('unit_cost');
        }

        if (blank($product->qr_token)) {
            $product->forceFill(['qr_token' => Product::newQrToken()])->saveQuietly();
        }

        $branchPrices = app(BranchPrices::class)->forProduct($product, $this->branch()->id() ? [$this->branch()->id()] : $this->branch()->accessibleIds());
        if (! $canCost) {
            $branchPrices = $branchPrices->map(function ($b) {
                foreach (['own', 'effective'] as $k) {
                    unset($b[$k]['cost_price'], $b[$k]['min_selling_price']);
                }

                return $b;
            });
        }

        return $this->ok([
            'product' => $product,
            'branch_prices' => $branchPrices,
            'qr' => app(SettingsService::class)->bool('qr_enabled') ? app(ProductCodes::class)->qrPayload($product) : null,
            'stocks' => $stocks,
            'total_stock' => (float) $stocks->sum('quantity'),
            'batches' => $batches,
            'movements' => $movements,
        ]);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (! empty($data['opening_quantity']) && ! $request->user()->hasPermission('stock.opening')) {
            throw new BusinessException(__('You are not allowed to enter opening stock.'), 'FORBIDDEN', 403);
        }

        $product = $this->products->create($data, $this->branch()->id(), $request->file('image'));

        return $this->created($product, __('Product :product created successfully.', ['product' => $product->name]));
    }

    public function update(ProductRequest $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $data = $request->validated();

        $priceFields = ['cost_price', 'selling_price', 'wholesale_price', 'min_selling_price'];
        $priceChanged = collect($priceFields)->contains(fn ($f) => array_key_exists($f, $data) && (float) $data[$f] !== (float) $product->{$f});
        if ($priceChanged && ! $request->user()->hasPermission('products.update_price')) {
            throw new BusinessException(__('You are not allowed to change prices.'), 'FORBIDDEN', 403);
        }
        if (! $request->user()->hasPermission('products.view_cost')) {
            unset($data['cost_price'], $data['min_selling_price']);
        }

        $product = $this->products->update($product, $data, $data['change_reason'], $request->file('image'), (bool) ($data['remove_image'] ?? false));

        return $this->ok($product, __('Product updated successfully.'));
    }

    /** Saves one branch's own prices for a product (empty = use the product's price). */
    public function branchPrices(Request $request, int $id, BranchPrices $prices): JsonResponse
    {
        $product = Product::findOrFail($id);
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'selling_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'min_selling_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);
        if (! $request->user()->hasPermission('products.update_price')) {
            throw new BusinessException(__('You are not allowed to change prices.'), 'FORBIDDEN', 403);
        }
        $accessible = $this->branch()->accessibleIds();
        if ($accessible !== null && ! in_array((int) $data['branch_id'], $accessible, true)) {
            throw new BusinessException(__('You do not have access to that branch.'), 'FORBIDDEN', 403);
        }
        if (! $request->user()->hasPermission('products.view_cost')) {
            unset($data['cost_price'], $data['min_selling_price']);
        }
        $prices->set($product, (int) $data['branch_id'], $data);
        app(AuditLogger::class)->event('product.branch_prices', "Branch prices changed for {$product->name}", $product, $data);

        return $this->ok($prices->forProduct($product, $accessible), __('Branch prices saved.'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $onHand = (float) ProductStock::query()->where('product_id', $id)->sum('quantity');
        if ($onHand > 0) {
            throw new BusinessException(__(':product still has :quantity in stock. Adjust the stock to zero before deleting.', ['product' => $product->name, 'quantity' => 0 + $onHand]));
        }

        $product->auditReason = $request->input('reason');
        $product->delete();

        return $this->ok(null, __(':product deleted. It can be restored from the deleted items list.', ['product' => $product->name]));
    }

    public function restore(int $id): JsonResponse
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $product->restore();

        return $this->ok($product, __(':product restored successfully.', ['product' => $product->name]));
    }

    /** Find one product by barcode / SKU (scanner) — used by the POS and purchase screens. */
    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code'));
        abort_if($code === '', 404);

        // A scanned code can be the product itself or one of its packs (e.g. a carton barcode).
        $pack = ProductUnit::query()->where('barcode', $code)->where('is_active', true)->first();

        $product = Product::query()->active()
            ->with(['unit:id,short_name', 'units' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('factor')])
            ->withStock($this->branch()->id(), $this->branch()->id() ? null : $this->branch()->accessibleIds())
            ->when($pack, fn ($q) => $q->whereKey($pack->product_id), fn ($q) => $q->where(fn ($w) => $w->where('barcode', $code)->orWhere('sku', $code)
                // A product QR code (its link or token) also finds the product.
                ->when(Product::tokenFromScan($code), fn ($t, $token) => $t->orWhere('qr_token', $token))))
            ->first();

        if (! $product) {
            throw new BusinessException(__('No product found with barcode / SKU ":code".', ['code' => $code]), 'NOT_FOUND', 404);
        }
        app(BranchPrices::class)->apply($product, $this->branch()->id());
        if (! $request->user()->hasPermission('products.view_cost')) {
            $product->makeHidden(['cost_price']);
        }

        $product->setAttribute('matched_unit_id', $pack?->id);

        return $this->ok($product);
    }

    /** Online-shop texts only (used after an AI draft is reviewed). */
    public function descriptions(Request $request, int $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $product->update($request->validate([
            'description' => ['nullable', 'string', 'max:5000'],
            'description_sw' => ['nullable', 'string', 'max:5000'],
        ]));

        return $this->ok($product->only(['id', 'description', 'description_sw']), __('Descriptions saved to the product.'));
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120']]);

        $result = $this->products->import($request->file('file'), $this->branch()->id());
        $message = "Import finished: {$result['created']} created, {$result['updated']} updated".($result['failed'] ? ', '.count($result['failed']).' row(s) failed.' : '.');

        return $this->ok($result, $message);
    }

    public function importTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([ProductService::IMPORT_COLUMNS], null, 'A1');
        $sheet->fromArray([['Paracetamol 500mg', 'PARA500', '', 'Medicines', 'pc', 'Panadol', 50, 100, 90, 20, 'yes', 200, '2027-12-31', 'B123', 'yes']], null, 'A2');
        $sheet->fromArray([['Beef steak', 'BEEF-STK', '', 'Meat', 'kg', '', 9000, 14000, 13000, 5, 'no', 25.5, '', '', 'yes']], null, 'A3');
        $sheet->getStyle('A1:O1')->getFont()->setBold(true);
        foreach (range('A', 'O') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return new StreamedResponse(fn () => (new Xlsx($spreadsheet))->save('php://output'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="product-import-template.xlsx"',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Inventory\ProductStock;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockBatch;
use App\Models\Inventory\StockMovement;
use App\Services\Core\NumberGenerator;
use App\Services\Core\SettingsService;
use App\Services\Finance\PostingService;
use App\Services\Inventory\StockService;
use App\Services\Reports\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    public function __construct(
        private readonly StockService $stock,
        private readonly NumberGenerator $numbers,
        private readonly SettingsService $settings,
        private readonly PostingService $posting,
    ) {}

    /** Stock alert lists: low, out, expiring, expired. */
    public function alerts(Request $request, DashboardService $dashboard): JsonResponse
    {
        $type = $request->query('type', 'low');
        $days = (int) $request->query('days', $this->settings->int('expiry_alert_days'));

        if (in_array($type, ['low', 'out'], true)) {
            $query = ProductStock::query()
                ->join('products', 'products.id', '=', 'product_stocks.product_id')
                ->join('branches', 'branches.id', '=', 'product_stocks.branch_id')
                ->leftJoin('units', 'units.id', '=', 'products.unit_id')
                ->whereNull('products.deleted_at')->where('products.is_active', true)->where('products.is_service', false)
                ->when($type === 'low', fn ($q) => $q->where('product_stocks.quantity', '>', 0)->whereColumn('product_stocks.quantity', '<=', 'products.reorder_level'))
                ->when($type === 'out', fn ($q) => $q->where('product_stocks.quantity', '<=', 0))
                ->select(['products.id as product_id', 'products.name', 'products.sku', 'products.reorder_level', 'branches.name as branch', 'product_stocks.quantity', 'units.short_name as unit'])
                ->orderBy('product_stocks.quantity');
            $this->branch()->scope($query, 'product_stocks.branch_id');
        } else {
            $query = StockBatch::query()
                ->join('products', 'products.id', '=', 'stock_batches.product_id')
                ->join('branches', 'branches.id', '=', 'stock_batches.branch_id')
                ->whereNull('products.deleted_at')
                ->where('stock_batches.quantity_remaining', '>', 0)
                ->whereNotNull('stock_batches.expiry_date')
                ->when($type === 'expired', fn ($q) => $q->where('stock_batches.expiry_date', '<', now()->startOfDay()))
                ->when($type === 'expiring', fn ($q) => $q->whereBetween('stock_batches.expiry_date', [now()->startOfDay(), now()->addDays($days)->endOfDay()]))
                ->select(['stock_batches.id as batch_id', 'products.id as product_id', 'products.name', 'branches.name as branch', 'stock_batches.batch_no', 'stock_batches.expiry_date', 'stock_batches.quantity_remaining as quantity'])
                ->orderBy('stock_batches.expiry_date');
            $this->branch()->scope($query, 'stock_batches.branch_id');
        }

        return $this->paginated($query->paginate($this->perPage($request)), ['counts' => $dashboard->alerts()]);
    }

    public function movements(Request $request): JsonResponse
    {
        $query = $this->branch()->scope(StockMovement::query())
            ->with(['product:id,name', 'branch:id,name', 'user', 'batch:id,batch_no,expiry_date'])
            ->when($request->query('product_id'), fn ($q, $v) => $q->where('product_id', $v))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('date_from'), fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($request->query('date_to'), fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->latest('id');

        $page = $query->paginate($this->perPage($request, 50));
        if (! $request->user()->hasPermission('products.view_cost')) {
            $page->getCollection()->each->makeHidden('unit_cost');
        }

        return $this->paginated($page);
    }

    public function adjustments(Request $request): JsonResponse
    {
        $query = $this->applyTrashed($this->branch()->scope(StockAdjustment::query()), $request)
            ->with(['product:id,name', 'branch:id,name', 'creator'])
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('product_id'), fn ($q, $v) => $q->where('product_id', $v))
            ->latest('id');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    /**
     * Correct stock: physical count, damage, expiry write-off or manual increase / decrease.
     * The adjustment record and the stock ledger keep the reason and who did it.
     */
    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::in(['increase', 'decrease', 'damage', 'expired', 'count'])],
            'quantity' => ['required', 'numeric', 'min:0'],
            'stock_batch_id' => ['nullable', 'integer', 'exists:stock_batches,id'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'expiry_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $branchId = $this->branch()->requireId();
        $product = Product::query()->findOrFail($data['product_id']);
        if ($product->is_service) {
            throw new BusinessException(__('Services have no stock.'));
        }

        $adjustment = DB::transaction(function () use ($data, $branchId, $product) {
            $value = null;
            $before = $this->stock->onHand($product->id, $branchId);
            $qty = round((float) $data['quantity'], 3);

            // A physical count sets the quantity; convert it into an increase or decrease.
            $direction = match ($data['type']) {
                'increase' => 'in',
                'count' => $qty >= $before ? 'in' : 'out',
                default => 'out',
            };
            $change = $data['type'] === 'count' ? round(abs($qty - $before), 3) : $qty;
            if ($change <= 0) {
                throw new BusinessException($data['type'] === 'count' ? 'The counted quantity equals the system quantity. Nothing to adjust.' : 'Quantity must be greater than zero.');
            }
            $this->stock->assertQuantity($product, $change);

            $adjustment = StockAdjustment::create([
                'number' => $this->numbers->next('adjustment'),
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'stock_batch_id' => $data['stock_batch_id'] ?? null,
                'type' => $data['type'],
                'quantity' => $direction === 'in' ? $change : -$change,
                'quantity_before' => $before,
                'unit_cost' => $data['unit_cost'] ?? $product->cost_price,
                'expiry_date' => $data['expiry_date'] ?? null,
                'reason' => $data['reason'],
            ]);

            $movementType = match ($data['type']) {
                'damage' => 'damage',
                'expired' => 'expired',
                default => $direction === 'in' ? 'adjustment_in' : 'adjustment_out',
            };

            if ($direction === 'in') {
                $value = $change * (float) ($data['unit_cost'] ?? $product->cost_price);
                $this->stock->increase($product, $branchId, $change, (float) ($data['unit_cost'] ?? $product->cost_price), $movementType, $adjustment, [
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'batch_id' => $data['stock_batch_id'] ?? null,
                    'note' => "{$adjustment->number}: {$data['reason']}",
                ]);
            } else {
                $consumed = $this->stock->decrease($product, $branchId, $change, $movementType, $adjustment, [
                    'batch_id' => $data['stock_batch_id'] ?? null,
                    'allow_expired' => true,
                    'allow_negative' => false,
                    'note' => "{$adjustment->number}: {$data['reason']}",
                ]);
            }

            $value ??= array_sum(array_map(fn ($p) => $p['quantity'] * $p['unit_cost'], $consumed));
            $adjustment->forceFill(['quantity_after' => $this->stock->onHand($product->id, $branchId)])->saveQuietly();
            $this->posting->stockAdjustment($adjustment, round($value, 2));

            return $adjustment;
        });

        return $this->created($adjustment->load(['product:id,name', 'branch:id,name']), __('Stock for :product adjusted.', ['product' => $product->name]));
    }
}

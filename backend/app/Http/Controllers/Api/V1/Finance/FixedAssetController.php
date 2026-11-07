<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Finance\FixedAsset;
use App\Services\Finance\FixedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FixedAssetController extends Controller
{
    public function __construct(private readonly FixedAssetService $assets) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->applyTrashed($this->branch()->scope(FixedAsset::query()), $request)
            ->with(['assetAccount:id,code,name', 'branch:id,name'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('number', 'like', "%{$t}%")->orWhere('serial_number', 'like', "%{$t}%")));
        $this->sortable($query, $request, ['acquired_on', 'cost', 'name'], 'acquired_on');

        $totals = (clone $query)->reorder()->where('status', '!=', FixedAsset::DISPOSED)
            ->selectRaw('COALESCE(SUM(cost),0) cost, COALESCE(SUM(accumulated_depreciation),0) acc')->first();

        return $this->paginated($query->paginate($this->perPage($request)), ['summary' => [
            'cost' => round((float) $totals->cost, 2),
            'accumulated_depreciation' => round((float) $totals->acc, 2),
            'book_value' => round((float) $totals->cost - (float) $totals->acc, 2),
        ]]);
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok($this->branch()->scope(FixedAsset::withTrashed())
            ->with(['assetAccount:id,code,name', 'depreciationAccount:id,code,name', 'accumulatedAccount:id,code,name', 'branch:id,name', 'depreciations' => fn ($q) => $q->orderBy('period')])
            ->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:60'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'asset_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'depreciation_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('type', 'expense')->whereNull('deleted_at')],
            'accumulated_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('type', 'asset')->whereNull('deleted_at')],
            'acquired_on' => ['required', 'date', 'before_or_equal:today'],
            'cost' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:600'],
            'funding' => ['required', Rule::in(FixedAssetService::FUNDING)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $funding = $data['funding'];
        unset($data['funding']);
        $data['salvage_value'] = $data['salvage_value'] ?? 0;

        $asset = $this->assets->register($data, $funding, $this->branch()->id());

        return $this->created($asset, __('Asset :asset registered successfully.', ['asset' => $asset->name]));
    }

    /** Descriptive fields only: money fields are fixed once posted. */
    public function update(Request $request, int $id): JsonResponse
    {
        $asset = $this->branch()->scope(FixedAsset::query())->findOrFail($id);
        $asset->update($request->validate([
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:60'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]));

        return $this->ok($asset, __('Asset updated successfully.'));
    }

    public function dispose(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'disposed_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'method' => ['required_with:amount', 'nullable', Rule::in(['cash', 'bank', 'mobile_money'])],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $asset = $this->assets->dispose($this->branch()->scope(FixedAsset::query())->findOrFail($id), $data);
        $gain = (float) $asset->gain_or_loss;

        return $this->ok($asset, $gain >= 0
            ? __('Asset disposed with a gain of :amount.', ['amount' => number_format($gain, 2)])
            : __('Asset disposed with a loss of :amount.', ['amount' => number_format(-$gain, 2)]));
    }

    public function depreciate(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);
        $result = $this->assets->depreciate($data['period']);
        if ($result['months'] === 0) {
            throw new BusinessException(__('Nothing to depreciate up to :period.', ['period' => $data['period']]), 'NOTHING_TO_DO');
        }

        return $this->ok($result, __('Depreciation of :amount posted for :assets asset(s).', ['amount' => number_format($result['amount'], 2), 'assets' => $result['assets']]));
    }
}

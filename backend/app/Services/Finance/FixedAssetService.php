<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessException;
use App\Models\Finance\Account;
use App\Models\Finance\AssetDepreciation;
use App\Models\Finance\FixedAsset;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fixed asset register: acquisition, monthly straight-line depreciation
 * (catching up any missed months) and disposal with gain / loss.
 */
class FixedAssetService
{
    /** How an acquisition was paid for. */
    public const FUNDING = ['cash', 'bank', 'mobile_money', 'payable', 'opening'];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly PostingService $posting,
        private readonly NumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function register(array $data, string $funding, ?int $branchId): FixedAsset
    {
        $assetAccount = Account::query()->findOrFail($data['asset_account_id']);
        if ($assetAccount->sub_type !== 'fixed_asset' || $assetAccount->is_header) {
            throw new BusinessException(__('Choose a fixed asset account (e.g. Furniture and equipment).'));
        }
        if ((float) $data['salvage_value'] > (float) $data['cost']) {
            throw new BusinessException(__('The salvage value cannot be more than the cost.'));
        }
        $data['depreciation_account_id'] ??= Account::query()->where('code', '6850')->value('id');
        $data['accumulated_account_id'] ??= Account::query()->where('code', '1790')->value('id');
        if (! $data['depreciation_account_id'] || ! $data['accumulated_account_id']) {
            throw new BusinessException(__('Set the depreciation and accumulated depreciation accounts.'));
        }

        return DB::transaction(function () use ($data, $funding, $branchId, $assetAccount) {
            $asset = new FixedAsset($data + ['branch_id' => $branchId]);
            $asset->number = $this->numbers->next('fixed_asset');
            $asset->save();

            $credit = match ($funding) {
                'payable' => $this->ledger->account('payable'),
                'opening' => $this->ledger->account('opening_equity'),
                default => $this->posting->moneyAccount($funding),
            };
            $this->ledger->post([
                'date' => $asset->acquired_on,
                'description' => __('Asset acquired: :asset', ['asset' => $asset->name]),
                'event' => 'asset_acquired',
                'source' => $asset,
                'branch_id' => $branchId,
                'reference' => $asset->number,
            ], [
                ['account' => $assetAccount, 'debit' => (float) $asset->cost],
                ['account' => $credit, 'credit' => (float) $asset->cost],
            ]);

            return $asset;
        });
    }

    /**
     * Depreciate every active asset up to and including the month of $period (YYYY-MM).
     *
     * @return array{assets: int, months: int, amount: float}
     */
    public function depreciate(string $period): array
    {
        $end = Carbon::createFromFormat('Y-m-d', $period.'-01')->endOfMonth()->startOfDay();
        if ($end->copy()->startOfMonth()->isFuture()) {
            throw new BusinessException(__('Depreciation cannot be run for a future month.'));
        }

        $summary = ['assets' => 0, 'months' => 0, 'amount' => 0.0];
        FixedAsset::query()->where('status', FixedAsset::ACTIVE)->whereDate('acquired_on', '<=', $end->toDateString())
            ->orderBy('id')->each(function (FixedAsset $asset) use ($end, &$summary) {
                $months = $this->depreciateAsset($asset, $end);
                if ($months['months'] > 0) {
                    $summary['assets']++;
                    $summary['months'] += $months['months'];
                    $summary['amount'] = round($summary['amount'] + $months['amount'], 2);
                }
            });

        return $summary;
    }

    /** @return array{months: int, amount: float} */
    private function depreciateAsset(FixedAsset $asset, Carbon $until): array
    {
        return DB::transaction(function () use ($asset, $until) {
            $asset = FixedAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            // Depreciation starts the month after acquisition.
            $month = $asset->depreciated_to
                ? $asset->depreciated_to->copy()->addDay()->startOfMonth()
                : $asset->acquired_on->copy()->addMonthNoOverflow()->startOfMonth();
            $depreciable = round((float) $asset->cost - (float) $asset->salvage_value, 2);
            $done = ['months' => 0, 'amount' => 0.0];

            while ($month->lte($until) && (float) $asset->accumulated_depreciation < $depreciable - 0.004) {
                $monthEnd = $month->copy()->endOfMonth()->startOfDay();
                $amount = round(min($asset->monthly_depreciation, $depreciable - (float) $asset->accumulated_depreciation), 2);
                if (! $this->ledger->isOpen($monthEnd)) {
                    // Closed years are never touched; just move the marker forward.
                    $asset->depreciated_to = $monthEnd;
                    $month->addMonthNoOverflow();

                    continue;
                }
                $entry = $this->ledger->post([
                    'date' => $monthEnd,
                    'description' => __('Depreciation :month: :asset', ['month' => $month->format('m/Y'), 'asset' => $asset->name]),
                    'event' => 'depreciation',
                    'source' => $asset,
                    'branch_id' => $asset->branch_id,
                    'reference' => $asset->number,
                ], [
                    ['account' => (int) $asset->depreciation_account_id, 'debit' => $amount],
                    ['account' => (int) $asset->accumulated_account_id, 'credit' => $amount],
                ]);
                AssetDepreciation::create(['fixed_asset_id' => $asset->id, 'period' => $month->format('Y-m'), 'amount' => $amount, 'journal_entry_id' => $entry?->id]);

                $asset->accumulated_depreciation = round((float) $asset->accumulated_depreciation + $amount, 2);
                $asset->depreciated_to = $monthEnd;
                $done['months']++;
                $done['amount'] = round($done['amount'] + $amount, 2);
                $month->addMonthNoOverflow();
            }
            if ((float) $asset->accumulated_depreciation >= $depreciable - 0.004) {
                $asset->status = FixedAsset::FULLY_DEPRECIATED;
            }
            $asset->saveQuietly();

            return $done;
        });
    }

    public function dispose(FixedAsset $asset, array $data): FixedAsset
    {
        if ($asset->status === FixedAsset::DISPOSED) {
            throw new BusinessException(__('This asset has already been disposed of.'));
        }
        $date = Carbon::parse($data['disposed_on']);
        if ($date->lt($asset->acquired_on)) {
            throw new BusinessException(__('The disposal date is before the acquisition date.'));
        }

        return DB::transaction(function () use ($asset, $data, $date) {
            // Bring depreciation up to the month before disposal.
            $this->depreciateAsset($asset, $date->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay());
            $asset->refresh();

            $proceeds = round((float) ($data['amount'] ?? 0), 2);
            $cost = (float) $asset->cost;
            $accumulated = (float) $asset->accumulated_depreciation;
            $gain = round($proceeds + $accumulated - $cost, 2);

            $lines = [
                ['account' => (int) $asset->accumulated_account_id, 'debit' => $accumulated],
                ['account' => (int) $asset->asset_account_id, 'credit' => $cost],
            ];
            if ($proceeds > 0) {
                $lines[] = ['account' => $this->posting->moneyAccount($data['method'] ?? 'cash'), 'debit' => $proceeds];
            }
            if ($gain > 0) {
                $lines[] = ['account' => 'other_income', 'credit' => $gain];
            } elseif ($gain < 0) {
                $lines[] = ['account' => 'general_expense', 'debit' => -$gain, 'memo' => __('Loss on disposal')];
            }
            $this->ledger->post([
                'date' => $date,
                'description' => __('Asset disposed: :asset', ['asset' => $asset->name]),
                'event' => 'asset_disposed',
                'source' => $asset,
                'branch_id' => $asset->branch_id,
                'reference' => $asset->number,
            ], $lines);

            $asset->forceFill([
                'status' => FixedAsset::DISPOSED,
                'disposed_on' => $date->toDateString(),
                'disposal_amount' => $proceeds,
                'notes' => trim(($asset->notes ? $asset->notes."\n" : '').($data['reason'] ?? '')) ?: null,
            ])->save();
            $this->audit->event('asset.disposed', __(':asset disposed for :amount', ['asset' => $asset->name, 'amount' => number_format($proceeds, 2)]), $asset, ['gain' => $gain]);

            return $asset->setAttribute('gain_or_loss', $gain);
        });
    }
}

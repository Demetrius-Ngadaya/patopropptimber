<?php

namespace App\Services\Notifications;

use App\Models\Catalog\Product;
use App\Models\Core\Branch;
use App\Models\Inventory\ProductStock;
use App\Models\Sales\SaleItem;
use App\Services\Core\SettingsService;
use App\Services\Messaging\OutgoingMessage;

/** Raises "low stock" and "out of stock" the moment a product crosses the line in a branch. */
class StockAlerts
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly SettingsService $settings,
    ) {}

    public function afterDecrease(Product $product, int $branchId, float $before, float $after): void
    {
        if ($product->is_service) {
            return;
        }
        $level = (float) $product->reorder_level;

        if ($before > 0 && $after <= 0) {
            $this->raise('out_of_stock', $product, $branchId, $after);
        } elseif ($level > 0 && $before > $level && $after <= $level) {
            $this->raise('low_stock', $product, $branchId, $after);
        }
    }

    private function raise(string $type, Product $product, int $branchId, float $quantity): void
    {
        $branch = Branch::query()->find($branchId);
        $unit = $product->unit?->short_name ?? '';
        $out = $type === 'out_of_stock';
        $params = ['product' => $product->name, 'branch' => $branch?->name ?? '', 'quantity' => 0 + round($quantity, 3), 'unit' => $unit, 'level' => 0 + (float) $product->reorder_level];

        $this->notifier->notify(new StaffNotice(
            type: $type,
            title: $out ? ':product is out of stock in :branch' : ':product is running low in :branch',
            body: $out ? 'Nothing left to sell. Order more or move stock from another branch.' : ':quantity :unit left; the reorder level is :level :unit.',
            params: $params,
            level: $out ? 'danger' : 'warning',
            url: "/admin/products/{$product->id}",
            branchId: $branchId,
            dedupeKey: "{$type}:{$product->id}:{$branchId}",
            dedupeHours: 12,
            message: fn () => $this->message($type, $product, $branch, $quantity, $unit),
        ));
    }

    private function message(string $type, Product $product, ?Branch $branch, float $quantity, string $unit): OutgoingMessage
    {
        $b = $this->settings->get('business_name');
        $out = $type === 'out_of_stock';
        $qty = 0 + round($quantity, 3);
        $branchName = $branch?->name ?? '';

        $sms = $out
            ? __(':business: :product is out of stock in :branch.', ['business' => $b, 'product' => $product->name, 'branch' => $branchName])
            : __(':business: :product is low in :branch (:quantity :unit left).', ['business' => $b, 'product' => $product->name, 'branch' => $branchName, 'quantity' => $qty, 'unit' => $unit]);

        $stocks = ProductStock::query()->where('product_id', $product->id)->with('branch:id,name')->get();
        $sold30 = (float) SaleItem::query()->where('product_id', $product->id)
            ->whereHas('sale', fn ($q) => $q->completed()->where('sold_at', '>=', now()->subDays(30)))->sum('quantity');
        $perDay = $sold30 / 30;

        $lines = ["*{$b}*", $sms, ''];
        foreach ($stocks as $s) {
            $lines[] = '• '.($s->branch?->name ?? '-').': '.(0 + (float) $s->quantity)." {$unit}";
        }
        $lines[] = '';
        $lines[] = __('Sold in the last 30 days: :quantity :unit', ['quantity' => 0 + round($sold30, 2), 'unit' => $unit]);

        $doc = [
            'title' => $out ? __(':product is out of stock', ['product' => $product->name]) : __(':product is running low', ['product' => $product->name]),
            'subtitle' => $branchName,
            'meta' => array_filter([
                __('Product') => $product->name,
                __('SKU') => $product->sku,
                __('Barcode') => $product->barcode,
                __('Left in :branch', ['branch' => $branchName]) => "{$qty} {$unit}",
                __('Reorder level') => (0 + (float) $product->reorder_level)." {$unit}",
                __('Sold in the last 30 days') => (0 + round($sold30, 2))." {$unit}",
                __('Days of stock left') => $perDay > 0 ? (string) max(0, (int) floor($quantity / $perDay)) : '-',
            ], fn ($v) => $v !== null && $v !== ''),
            'sections' => [[
                'heading' => __('Stock in every branch'),
                'columns' => [__('Branch'), __('Quantity')],
                'numeric' => [1],
                'rows' => $stocks->map(fn ($s) => [$s->branch?->name ?? '-', (0 + (float) $s->quantity)." {$unit}"])->all(),
            ]],
            'closing' => [$out ? __('Order more, or transfer stock from a branch that has it.') : __('Consider ordering more before it runs out.')],
        ];

        return (new OutgoingMessage($type, $sms, implode("\n", $lines), $doc['title'].' · '.$b, $doc, "{$type}-".($product->sku ?: $product->id).'.pdf'))->withRelated($product);
    }
}

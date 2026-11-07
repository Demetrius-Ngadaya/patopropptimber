<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Product;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Inventory\Transfer;
use App\Models\Inventory\TransferItem;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use App\Services\Finance\PostingService;
use Illuminate\Support\Facades\DB;

/**
 * Moving stock between branches:
 *  - free   : goods are moved, nothing is charged
 *  - sale   : the receiving branch pays for the goods (recorded as paid)
 *  - credit : the receiving branch owes the sending branch until marked paid
 *
 * Stock leaves the sending branch immediately ("in transit") and enters the
 * receiving branch only when someone there confirms receipt.
 */
class TransferService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly NumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly PostingService $posting,
    ) {}

    public function send(array $data, User $user, int $fromBranchId): Transfer
    {
        $toBranchId = (int) $data['to_branch_id'];
        if ($toBranchId === $fromBranchId) {
            throw new BusinessException(__('Choose a different branch to send stock to.'));
        }
        $toBranch = Branch::query()->whereKey($toBranchId)->where('is_active', true)->first();
        if (! $toBranch) {
            throw new BusinessException(__('The receiving branch is not active.'));
        }

        $type = $data['type'] ?? 'free';

        return DB::transaction(function () use ($data, $user, $fromBranchId, $toBranchId, $type) {
            $products = Product::query()->whereIn('id', collect($data['items'])->pluck('product_id'))->get()->keyBy('id');

            $transfer = Transfer::create([
                'number' => $this->numbers->next('transfer'),
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'type' => $type,
                'status' => 'in_transit',
                'payment_status' => match ($type) {
                    'sale' => 'paid',
                    'credit' => 'unpaid',
                    default => 'not_applicable',
                },
                'paid_at' => $type === 'sale' ? now() : null,
                'due_date' => $type === 'credit' ? ($data['due_date'] ?? now()->addDays(30)->toDateString()) : null,
                'notes' => $data['notes'] ?? null,
                'sent_by' => $user->id,
                'sent_at' => now(),
            ]);

            $totalCost = $totalAmount = 0.0;
            foreach ($data['items'] as $line) {
                $product = $products->get($line['product_id']);
                if (! $product || $product->is_service) {
                    throw new BusinessException(__('A selected product cannot be transferred.'));
                }
                $qty = round((float) $line['quantity'], 3);
                $this->stock->assertQuantity($product, $qty);

                $consumed = $this->stock->decrease($product, $fromBranchId, $qty, 'transfer_out', $transfer, [
                    'note' => "{$transfer->number} to branch #{$toBranchId}",
                    'allow_expired' => false,
                ]);
                $unitCost = $this->stock->averageCost($consumed);
                $unitPrice = $type === 'free' ? 0.0 : round((float) ($line['unit_price'] ?? $product->selling_price), 2);

                TransferItem::create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'unit_price' => $unitPrice,
                    'batches' => $consumed,
                ]);

                $totalCost += $unitCost * $qty;
                $totalAmount += $unitPrice * $qty;
            }

            $transfer->forceFill([
                'total_cost' => round($totalCost, 2),
                'total_amount' => round($totalAmount, 2),
            ])->saveQuietly();

            $this->posting->transferSent($transfer);
            $this->audit->event('transfer.sent', "Transfer {$transfer->number} sent ({$type})", $transfer);

            return $transfer->load(['items.product', 'fromBranch', 'toBranch']);
        });
    }

    /**
     * @param  array<int, array{transfer_item_id: int, received_quantity: float}>  $lines
     */
    public function receive(Transfer $transfer, array $lines, float $receivingCosts, ?string $notes, User $user, int $branchId): Transfer
    {
        if ((int) $transfer->to_branch_id !== $branchId) {
            throw new BusinessException(__('Only the receiving branch can confirm this transfer.'), 'FORBIDDEN', 403);
        }

        return DB::transaction(function () use ($transfer, $lines, $receivingCosts, $notes, $user) {
            $transfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->status !== 'in_transit') {
                throw new BusinessException(__('This transfer has already been :status.', ['status' => __(str_replace('_', ' ', $transfer->status))]));
            }

            $items = $transfer->items()->with('product')->get()->keyBy('id');
            $received = collect($lines)->keyBy('transfer_item_id');

            $baseValue = $receivedCost = 0.0;
            foreach ($items as $item) {
                $qty = (float) ($received[$item->id]['received_quantity'] ?? $item->quantity);
                $baseValue += $qty * $this->receivingUnitCost($transfer, $item);
            }

            foreach ($items as $item) {
                $qty = round((float) ($received[$item->id]['received_quantity'] ?? $item->quantity), 3);
                if ($qty < 0 || $qty - (float) $item->quantity > 0.0005) {
                    throw new BusinessException(__('Received quantity of :product must be between 0 and :quantity.', ['product' => $item->product->name, 'quantity' => $item->quantity]));
                }

                $unitCost = $this->receivingUnitCost($transfer, $item);
                $extra = ($receivingCosts > 0 && $baseValue > 0 && $qty > 0) ? ($receivingCosts * ($qty * $unitCost / $baseValue)) / $qty : 0.0;
                $landed = round($unitCost + $extra, 2);

                // Keep expiry dates of the source batches.
                $left = $qty;
                foreach ($item->batches ?? [] as $part) {
                    if ($left <= 0.0005) {
                        break;
                    }
                    $put = min($left, (float) $part['quantity']);
                    $this->stock->increase($item->product, $transfer->to_branch_id, $put, $landed, 'transfer_in', $transfer, [
                        'expiry_date' => $part['expiry_date'] ?? null,
                        'batch_no' => $part['batch_no'] ?? null,
                        'note' => "{$transfer->number} from branch #{$transfer->from_branch_id}",
                    ]);
                    $left = round($left - $put, 3);
                }
                if ($left > 0.0005) {
                    $this->stock->increase($item->product, $transfer->to_branch_id, $left, $landed, 'transfer_in', $transfer, [
                        'expiry_date' => $item->product->track_expiry ? now()->addYear()->toDateString() : null,
                        'note' => $transfer->number,
                    ]);
                }

                $item->received_quantity = $qty;
                $receivedCost += $qty * (float) $item->unit_cost;
                $item->save();
            }

            $transfer->forceFill([
                'status' => 'received',
                'received_by' => $user->id,
                'received_at' => now(),
                'receiving_costs' => round($receivingCosts, 2),
                'receive_notes' => $notes,
            ])->save();

            $short = $items->filter(fn ($i) => (float) $i->received_quantity + 0.0005 < (float) $i->quantity)->count();
            $this->posting->transferReceived($transfer, round($receivedCost, 2), round($receivingCosts, 2));
            $this->audit->event('transfer.received', "Transfer {$transfer->number} received".($short ? " with {$short} short item(s)" : ''), $transfer);

            return $transfer->load(['items.product', 'fromBranch', 'toBranch']);
        });
    }

    public function cancel(Transfer $transfer, string $reason, User $user, int $branchId): Transfer
    {
        if ((int) $transfer->from_branch_id !== $branchId) {
            throw new BusinessException(__('Only the sending branch can cancel this transfer.'), 'FORBIDDEN', 403);
        }

        return DB::transaction(function () use ($transfer, $reason, $user) {
            $transfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->status !== 'in_transit') {
                throw new BusinessException(__('Only transfers that are still in transit can be cancelled.'));
            }

            foreach ($transfer->items()->with('product')->get() as $item) {
                $this->stock->restore($item->product, $transfer->from_branch_id, $item->batches ?? [], (float) $item->quantity, 'transfer_cancel', $transfer, "Cancelled {$transfer->number}");
            }

            $transfer->auditReason = $reason;
            $transfer->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'payment_status' => 'not_applicable',
            ])->save();

            $this->posting->transferCancelled($transfer, $reason);

            return $transfer->load(['items.product', 'fromBranch', 'toBranch']);
        });
    }

    public function markPaid(Transfer $transfer): Transfer
    {
        if ($transfer->type !== 'credit' || $transfer->payment_status !== 'unpaid') {
            throw new BusinessException(__('Only unpaid credit transfers can be marked as paid.'));
        }
        if ($transfer->status === 'cancelled') {
            throw new BusinessException(__('This transfer was cancelled.'));
        }

        $transfer->forceFill(['payment_status' => 'paid', 'paid_at' => now()])->save();
        $this->audit->event('transfer.paid', "Transfer {$transfer->number} marked as paid", $transfer);

        return $transfer;
    }

    /** The receiving branch carries goods at what it paid (sale / credit) or at source cost (free). */
    private function receivingUnitCost(Transfer $transfer, TransferItem $item): float
    {
        return $transfer->type === 'free' ? (float) $item->unit_cost : (float) $item->unit_price;
    }
}

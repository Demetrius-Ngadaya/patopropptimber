<?php

namespace App\Services\Purchasing;

use App\Exceptions\BusinessException;
use App\Models\Core\User;
use App\Models\Purchasing\Purchase;
use App\Models\Purchasing\PurchasePlan;
use App\Models\Purchasing\PurchasePlanItem;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\StaffNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purchase plans: draft → pending (approved / ordered) → partially received
 * → received → closed, or cancelled. Each delivery is recorded as a normal
 * purchase (stock, landed cost, supplier debt and the books follow), linked
 * to the plan; credit deliveries get a due date and a payment reminder.
 */
class PurchasePlanService
{
    private const EPSILON = 0.0005;

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly PurchaseService $purchases,
        private readonly PurchaseReminders $reminders,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function save(array $data, User $user, int $branchId, ?PurchasePlan $plan = null): PurchasePlan
    {
        if ($plan && ! in_array($plan->status, ['draft', 'pending'], true)) {
            throw new BusinessException(__('A plan that has started receiving goods cannot be changed. Close it and make a new one.'));
        }

        return DB::transaction(function () use ($data, $user, $branchId, $plan) {
            $isNew = ! $plan;
            $plan ??= new PurchasePlan(['branch_id' => $branchId, 'status' => 'draft']);
            if ($isNew) {
                $plan->number = $this->numbers->next('purchase_plan');
            }
            $plan->fill([
                'supplier_id' => $data['supplier_id'] ?? null,
                'title' => $data['title'] ?? null,
                'expected_date' => $data['expected_date'] ?? null,
                'payment_type' => $data['payment_type'] ?? 'cash',
                'credit_days' => ($data['payment_type'] ?? 'cash') === 'credit' ? ($data['credit_days'] ?? null) : null,
                'reminder_days_before' => $data['reminder_days_before'] ?? null,
                'reminder_channels' => $data['reminder_channels'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $plan->save();

            $plan->items()->delete();
            $total = 0.0;
            foreach ($data['items'] as $line) {
                $qty = round((float) $line['quantity'], 3);
                $cost = round((float) ($line['unit_cost'] ?? 0), 2);
                $plan->items()->create([
                    'product_id' => $line['product_id'],
                    'quantity' => $qty,
                    'unit_cost' => $cost,
                    'line_total' => round($qty * $cost, 2),
                    'note' => $line['note'] ?? null,
                ]);
                $total += $qty * $cost;
            }
            $plan->forceFill(['estimated_total' => round($total, 2)])->save();
            $this->event($plan, $isNew ? 'created' : 'updated', $user);

            return $plan->fresh(['items.product', 'supplier']);
        });
    }

    /** Draft → pending: approved and ordered from the supplier, waiting for goods. */
    public function submit(PurchasePlan $plan, User $user): PurchasePlan
    {
        if ($plan->status !== 'draft') {
            throw new BusinessException(__('Only a draft can be made pending.'));
        }
        if (! $plan->items()->exists()) {
            throw new BusinessException(__('Add at least one product.'));
        }
        if ($plan->payment_type === 'credit' && ! $plan->supplier_id) {
            throw new BusinessException(__('Choose the supplier first: goods on credit are owed to a supplier.'));
        }
        $plan->forceFill(['status' => 'pending', 'submitted_at' => now(), 'submitted_by' => $user->id])->save();
        $this->event($plan, 'pending', $user);
        $this->notifier->notify(new StaffNotice(
            type: 'purchase_plan',
            title: 'Purchase plan :number is waiting for goods',
            body: ':supplier, about :amount, expected :date.',
            params: ['number' => $plan->number, 'supplier' => $plan->supplier?->name ?? __('No supplier yet'), 'amount' => number_format((float) $plan->estimated_total, 2), 'date' => $plan->expected_date?->format('d/m/Y') ?? '-'],
            url: '/admin/purchase-plans/'.$plan->id,
            branchId: $plan->branch_id,
            permission: 'purchase_plans.receive',
        ));

        return $plan;
    }

    /**
     * Goods arrive: record them as a purchase linked to the plan.
     *
     * @param  array{items: array<int, array{plan_item_id: int, quantity: float, unit_cost: float}>}  $data
     */
    public function receive(PurchasePlan $plan, array $data, User $user): Purchase
    {
        if (! in_array($plan->status, PurchasePlan::RECEIVABLE, true)) {
            throw new BusinessException(__('Goods can be received only on a pending plan.'));
        }
        $plan->load('items');
        $lines = [];
        foreach ($data['items'] as $line) {
            $qty = round((float) $line['quantity'], 3);
            if ($qty <= 0) {
                continue;
            }
            /** @var PurchasePlanItem|null $item */
            $item = $plan->items->firstWhere('id', (int) $line['plan_item_id']);
            if (! $item) {
                throw new BusinessException(__('A line does not belong to this plan.'));
            }
            if ($qty - $item->remaining_quantity > self::EPSILON) {
                throw new BusinessException(__('More :product than ordered: :remaining left to receive.', ['product' => $item->product?->name, 'remaining' => 0 + $item->remaining_quantity]), 'OVER_RECEIPT');
            }
            $lines[] = ['item' => $item, 'quantity' => $qty] + $line;
        }
        if (! $lines) {
            throw new BusinessException(__('Enter the quantity received for at least one product.'));
        }

        return DB::transaction(function () use ($plan, $data, $user, $lines) {
            $credit = $plan->payment_type === 'credit';
            $purchaseDate = Carbon::parse($data['purchase_date'] ?? today());
            $subtotal = collect($lines)->sum(fn ($l) => $l['quantity'] * (float) ($l['unit_cost'] ?? $l['item']->unit_cost));
            $dueDate = $data['due_date'] ?? ($credit && $plan->credit_days !== null ? $purchaseDate->copy()->addDays($plan->credit_days)->toDateString() : null);

            $purchase = $this->purchases->create([
                'supplier_id' => $plan->supplier_id,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'purchase_date' => $purchaseDate->toDateString(),
                'items' => array_map(fn ($l) => [
                    'product_id' => $l['item']->product_id,
                    'quantity' => $l['quantity'],
                    'unit_cost' => round((float) ($l['unit_cost'] ?? $l['item']->unit_cost), 2),
                    'selling_price' => $l['selling_price'] ?? null,
                    'batch_no' => $l['batch_no'] ?? null,
                    'expiry_date' => $l['expiry_date'] ?? null,
                ], $lines),
                'tax_cost' => $data['tax_cost'] ?? 0,
                'loading_cost' => $data['loading_cost'] ?? 0,
                'unloading_cost' => $data['unloading_cost'] ?? 0,
                'transport_cost' => $data['transport_cost'] ?? 0,
                'other_cost' => $data['other_cost'] ?? 0,
                'cost_allocation' => $data['cost_allocation'] ?? 'value',
                // Credit plans are paid later unless something is paid now.
                'amount_paid' => $data['amount_paid'] ?? ($credit ? 0 : $subtotal),
                'payment_method' => $data['payment_method'] ?? 'cash',
                'payment_reference' => $data['payment_reference'] ?? null,
                'due_date' => $dueDate,
                'notes' => trim(($data['notes'] ?? '').' '.__('Plan :number', ['number' => $plan->number])),
            ], $user, $plan->branch_id, creditApproved: $credit && $plan->submitted_at !== null);

            $purchase->forceFill(['purchase_plan_id' => $plan->id])->save();
            $this->reminders->apply($purchase, [
                'remind_on' => $data['remind_on'] ?? null,
                'reminder_channels' => $data['reminder_channels'] ?? $plan->reminder_channels,
                'days_before' => $plan->reminder_days_before,
            ]);

            foreach ($lines as $l) {
                $l['item']->forceFill(['received_quantity' => round((float) $l['item']->received_quantity + $l['quantity'], 3)])->save();
            }
            $plan->load('items');
            $complete = $plan->items->every(fn (PurchasePlanItem $i) => $i->remaining_quantity <= self::EPSILON);
            $plan->forceFill([
                'status' => $complete ? 'received' : 'partially_received',
                'received_total' => round((float) $plan->received_total + (float) $purchase->total, 2),
            ])->save();
            $this->event($plan, $complete ? 'received' : 'partially_received', $user, __('Purchase :number, :amount', ['number' => $purchase->number, 'amount' => number_format((float) $purchase->total, 2)]));

            return $purchase->fresh(['items.product', 'supplier']);
        });
    }

    public function cancel(PurchasePlan $plan, User $user, string $reason): PurchasePlan
    {
        if (! in_array($plan->status, ['draft', 'pending', 'partially_received'], true)) {
            throw new BusinessException(__('This plan is :status and cannot be cancelled.', ['status' => __(str_replace('_', ' ', $plan->status))]));
        }
        $plan->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        $this->event($plan, 'cancelled', $user, $reason);
        $this->audit->event('purchase_plan.cancelled', __('Purchase plan :number cancelled: :reason', ['number' => $plan->number, 'reason' => $reason]), $plan);

        return $plan;
    }

    /** Finished: everything received, or the rest will not come. */
    public function close(PurchasePlan $plan, User $user, ?string $note): PurchasePlan
    {
        if (! in_array($plan->status, ['received', 'partially_received'], true)) {
            throw new BusinessException(__('Only a plan that has received goods can be closed. Cancel it instead.'));
        }
        $plan->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $this->event($plan, 'closed', $user, $note);

        return $plan;
    }

    public function event(PurchasePlan $plan, string $event, ?User $user, ?string $note = null): void
    {
        $plan->events()->create(['event' => $event, 'note' => $note, 'user_id' => $user?->id]);
    }
}

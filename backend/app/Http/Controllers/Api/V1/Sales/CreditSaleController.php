<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Sales\Payment;
use App\Models\Sales\Sale;
use App\Services\Core\AuditLogger;
use App\Services\Sales\SaleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Credit sales ("madeni"): goods taken now and paid later. Each sale keeps the
 * original amount owed, what was paid, what remains, the expected repayment
 * date and the full payment history until it is settled.
 */
class CreditSaleController extends Controller
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request)
            ->with(['customer:id,name,phone,address', 'branch:id,name', 'cashier:id,name'])
            ->select(['id', 'number', 'branch_id', 'customer_id', 'customer_name', 'customer_phone', 'cashier_id', 'sold_at', 'total', 'credit_amount', 'amount_paid', 'balance', 'payment_status', 'due_date', 'fully_paid_at', 'status']);
        $this->sortable($query, $request, ['sold_at', 'due_date', 'balance', 'credit_amount'], 'due_date', 'asc');

        $summary = $this->filtered($request)->reorder()->selectRaw(
            'COUNT(*) as count, COALESCE(SUM(credit_amount),0) as owed, COALESCE(SUM(balance),0) as outstanding, '.
            'COALESCE(SUM(CASE WHEN balance > 0 AND due_date < ? THEN balance ELSE 0 END),0) as overdue',
            [now()->toDateString()]
        )->first();

        return $this->paginated($query->paginate($this->perPage($request)), ['summary' => [
            'count' => (int) $summary->count,
            'original_owed' => round((float) $summary->owed, 2),
            'paid' => round((float) $summary->owed - (float) $summary->outstanding, 2),
            'outstanding' => round((float) $summary->outstanding, 2),
            'overdue' => round((float) $summary->overdue, 2),
        ]]);
    }

    public function show(int $id): JsonResponse
    {
        $sale = $this->creditSales()->with(['customer:id,name,phone,address,credit_limit,balance', 'branch:id,name', 'cashier:id,name', 'items'])->findOrFail($id);

        return $this->ok([
            'sale' => $sale,
            'summary' => $this->summary($sale),
            'payments' => $this->history($sale),
        ]);
    }

    /** Pay a credit sale in full (balance becomes zero) or in part (balance reduced). */
    public function pay(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['full', 'partial'])],
            'amount' => ['required_if:mode,partial', 'nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'method' => ['required', Rule::in(Payment::TILL_METHODS)],
            'provider' => ['nullable', 'string', 'max:40'],
            'reference' => [Rule::requiredIf(fn () => in_array($request->input('method'), ['mobile_money', 'bank', 'cheque', 'card'], true)), 'nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
        ], [
            'amount.required_if' => __('Enter the amount paid.'),
            'reference.required' => __('Enter the transaction ID / reference.'),
        ]);

        $sale = $this->creditSales()->findOrFail($id);
        $payment = $this->sales->payCreditSale($sale, $data, $request->user(), $this->branch()->id() ?? $sale->branch_id);
        $sale->refresh();

        $message = (float) $sale->balance > 0
            ? __('Payment of :amount recorded. Remaining balance: :balance.', ['amount' => number_format((float) $payment->amount, 2), 'balance' => number_format((float) $sale->balance, 2)])
            : __('Payment of :amount recorded. The debt is fully paid.', ['amount' => number_format((float) $payment->amount, 2)]);

        return $this->created([
            'payment' => $payment,
            'summary' => $this->summary($sale),
        ], $message);
    }

    /** Agree a new expected repayment date with the customer. */
    public function reschedule(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $sale = $this->creditSales()->findOrFail($id);
        if ((float) $sale->balance <= 0) {
            throw new BusinessException(__('This sale has no outstanding balance.'));
        }

        $old = $sale->due_date?->toDateString();
        $sale->forceFill(['due_date' => $data['due_date']])->save();
        $this->audit->event('credit.rescheduled', __('Repayment date of :sale changed from :from to :to', ['sale' => $sale->number, 'from' => $old ?? '-', 'to' => $data['due_date']]), $sale, ['reason' => $data['reason']]);

        return $this->ok($this->summary($sale), __('Expected repayment date updated.'));
    }

    // ---------------------------------------------------------------- helpers

    private function creditSales(): Builder
    {
        return $this->branch()->scope(Sale::query())->where(fn ($q) => $q->where('is_credit', true)->orWhere('credit_amount', '>', 0));
    }

    private function filtered(Request $request): Builder
    {
        $today = now()->toDateString();

        return $this->creditSales()
            ->where('status', Sale::STATUS_COMPLETED)
            ->when($request->query('customer_id'), fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")
                ->orWhere('customer_name', 'like', "%{$t}%")->orWhere('customer_phone', 'like', "%{$t}%")))
            ->when($request->query('status'), fn ($q, $s) => match ($s) {
                'outstanding' => $q->where('balance', '>', 0),
                'overdue' => $q->where('balance', '>', 0)->where('due_date', '<', $today),
                'due_today' => $q->where('balance', '>', 0)->whereDate('due_date', $today),
                'paid' => $q->where('balance', '<=', 0),
                default => $q,
            })
            ->when($request->query('date_from'), fn ($q, $v) => $q->where('sold_at', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->where('sold_at', '<=', $v.' 23:59:59'));
    }

    private function summary(Sale $sale): array
    {
        $owed = (float) ($sale->credit_amount ?: $sale->balance);
        $balance = (float) $sale->balance;

        return [
            'sale_id' => $sale->id,
            'number' => $sale->number,
            'customer_name' => $sale->customer?->name ?? $sale->customer_name,
            'customer_phone' => $sale->customer?->phone ?? $sale->customer_phone,
            'customer_address' => $sale->customer?->address,
            'original_amount' => round($owed, 2),
            'amount_paid' => round(max(0, $owed - $balance), 2),
            'remaining_balance' => round($balance, 2),
            'expected_repayment_date' => $sale->due_date?->toDateString(),
            'days_overdue' => $balance > 0 && $sale->due_date && $sale->due_date->isPast() && ! $sale->due_date->isToday() ? (int) $sale->due_date->diffInDays(now()->startOfDay()) : 0,
            'status' => $balance <= 0 ? 'fully_paid' : ($owed - $balance > 0.004 ? 'partially_paid' : 'unpaid'),
            'fully_paid_at' => $sale->fully_paid_at,
        ];
    }

    /** Debt payments for this sale, oldest first, with the balance before and after each. */
    private function history(Sale $sale): array
    {
        return Payment::query()
            ->where('sale_id', $sale->id)
            ->where('type', 'debt')
            ->with('receiver:id,name')
            ->orderBy('paid_at')->orderBy('id')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'number' => $p->number,
                'paid_at' => $p->paid_at,
                'amount' => (float) $p->amount,
                'balance_before' => (float) $p->balance_before,
                'balance_after' => (float) $p->balance_after,
                'method' => $p->method,
                'reference' => $p->reference,
                'received_by' => $p->receiver?->name,
                'customer_name' => $sale->customer?->name ?? $sale->customer_name,
                'customer_phone' => $sale->customer?->phone ?? $sale->customer_phone,
                'original_amount' => (float) ($sale->credit_amount ?: 0),
                'expected_repayment_date' => $sale->due_date?->toDateString(),
                'notes' => $p->notes,
            ])->all();
    }
}

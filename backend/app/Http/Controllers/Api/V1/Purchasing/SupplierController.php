<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Purchasing\Purchase;
use App\Models\Purchasing\Supplier;
use App\Models\Purchasing\SupplierPayment;
use App\Services\Core\NumberGenerator;
use App\Services\Purchasing\PurchaseService;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->applyTrashed(Supplier::query(), $request)
            ->search($request->query('search'))
            ->when($request->query('filter') === 'owed', fn ($q) => $q->where('balance', '>', 0));
        $this->sortable($query, $request, ['name', 'balance', 'created_at', 'code'], 'name', 'asc');

        return $this->paginated($query->paginate($this->perPage($request)), [
            'summary' => ['total_owed' => round((float) Supplier::query()->where('balance', '>', 0)->sum('balance'), 2)],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $supplier = Supplier::withTrashed()->with('creator')->findOrFail($id);

        return $this->ok([
            'supplier' => $supplier,
            'unpaid_purchases' => Purchase::query()->where('supplier_id', $id)->where('balance', '>', 0)->orderBy('due_date')
                ->get(['id', 'number', 'purchase_date', 'supplier_invoice_no', 'supplier_total', 'amount_paid', 'balance', 'due_date']),
            'recent_purchases' => Purchase::query()->where('supplier_id', $id)->latest('purchase_date')->limit(10)
                ->get(['id', 'number', 'purchase_date', 'supplier_total', 'balance', 'payment_status']),
            'recent_payments' => SupplierPayment::query()->where('supplier_id', $id)->latest('paid_on')->limit(10)->get(),
        ]);
    }

    public function store(Request $request, NumberGenerator $numbers): JsonResponse
    {
        $data = $this->validated($request);
        $supplier = Supplier::create($data + ['code' => $numbers->next('supplier')]);

        return $this->created($supplier, __('Supplier :supplier created successfully.', ['supplier' => $supplier->name]));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->fill($this->validated($request))->save();

        return $this->ok($supplier, __('Supplier updated successfully.'));
    }

    public function destroy(int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        if ((float) $supplier->balance != 0.0) {
            throw new BusinessException(__('Settle the balance with this supplier before deleting.'));
        }
        $supplier->delete();

        return $this->ok(null, __('Supplier deleted. The record can be restored.'));
    }

    public function restore(int $id): JsonResponse
    {
        $supplier = Supplier::onlyTrashed()->findOrFail($id);
        $supplier->restore();

        return $this->ok($supplier, __('Supplier restored successfully.'));
    }

    public function pay(Request $request, int $id, PurchaseService $purchases): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', Rule::in(['cash', 'mobile_money', 'bank', 'cheque', 'lipa_namba'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'purchase_id' => ['nullable', 'integer', 'exists:purchases,id'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payments = $purchases->paySupplier($supplier, $data, $this->branch()->id());

        return $this->created(['payments' => $payments, 'supplier' => $supplier->fresh()], __('Payment of :amount to :supplier recorded.', ['amount' => number_format((float) $data['amount'], 2), 'supplier' => $supplier->name]));
    }

    public function statement(Request $request, int $id, ReportService $reports): JsonResponse
    {
        $supplier = Supplier::withTrashed()->findOrFail($id);
        [$from, $to] = $reports->range([
            'date_from' => $request->query('date_from', now()->subMonths(3)->toDateString()),
            'date_to' => $request->query('date_to'),
        ]);

        $purchasesBefore = (float) Purchase::query()->where('supplier_id', $id)->where('purchase_date', '<', $from)->sum('supplier_total');
        $paidBefore = (float) SupplierPayment::query()->where('supplier_id', $id)->where('paid_on', '<', $from)->sum('amount');
        $opening = round($purchasesBefore - $paidBefore, 2);

        $entries = Purchase::query()->where('supplier_id', $id)->whereBetween('purchase_date', [$from, $to])->get()
            ->map(fn ($p) => ['date' => $p->purchase_date->toDateString(), 'reference' => $p->number, 'description' => 'Purchase'.($p->supplier_invoice_no ? " (inv {$p->supplier_invoice_no})" : ''), 'debit' => 0.0, 'credit' => (float) $p->supplier_total])
            ->concat(SupplierPayment::query()->where('supplier_id', $id)->whereBetween('paid_on', [$from, $to])->get()
                ->map(fn ($p) => ['date' => $p->paid_on->toDateString(), 'reference' => $p->number, 'description' => 'Payment - '.str_replace('_', ' ', $p->method).($p->reference ? " #{$p->reference}" : ''), 'debit' => (float) $p->amount, 'credit' => 0.0]))
            ->sortBy('date')->values();

        $running = $opening;
        $entries = $entries->map(function ($e) use (&$running) {
            $running = round($running + $e['credit'] - $e['debit'], 2);

            return $e + ['balance' => $running];
        });

        return $this->ok([
            'supplier' => $supplier->only(['id', 'code', 'name', 'phone', 'balance']),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening_balance' => $opening,
            'closing_balance' => $running,
            'entries' => $entries,
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:30'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}

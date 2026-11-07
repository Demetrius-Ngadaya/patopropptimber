<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Customer;
use App\Models\Crm\SavingsAccount;
use App\Models\Crm\SavingsTransaction;
use App\Services\Crm\CustomerService;
use App\Services\Crm\SavingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Customer savings ("akiba"). Deposits build up a balance that the customer
 * later spends at the till; every movement is an immutable transaction.
 */
class SavingsController extends Controller
{
    private const MONEY_METHODS = ['cash', 'mobile_money', 'lipa_namba', 'bank', 'cheque', 'card'];

    public function __construct(
        private readonly SavingsService $savings,
        private readonly CustomerService $customers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request)->with(['customer:id,name,phone,address,balance', 'branch:id,name']);
        $this->sortable($query, $request, ['balance', 'total_deposited', 'last_transaction_at', 'created_at'], 'last_transaction_at');

        $totals = $this->filtered($request)->reorder()->selectRaw(
            'COUNT(*) c, COALESCE(SUM(balance),0) b, COALESCE(SUM(total_deposited),0) d, COALESCE(SUM(total_spent),0) s, COALESCE(SUM(total_withdrawn),0) w'
        )->first();

        return $this->paginated($query->paginate($this->perPage($request)), ['summary' => [
            'accounts' => (int) $totals->c,
            'balance' => round((float) $totals->b, 2),
            'total_deposited' => round((float) $totals->d, 2),
            'total_spent' => round((float) $totals->s, 2),
            'total_withdrawn' => round((float) $totals->w, 2),
        ]]);
    }

    /** Open an account for an existing customer, or register the customer at the same time. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required_without:customer', 'nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'customer' => ['required_without:customer_id', 'nullable', 'array'],
            'customer.name' => ['required_with:customer', 'string', 'max:150'],
            'customer.phone' => ['required_with:customer', 'string', 'regex:/^\+?[0-9 ]{9,15}$/'],
            'customer.address' => ['required_with:customer', 'string', 'max:255'],
            'initial_deposit' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'method' => ['required_with:initial_deposit', 'nullable', Rule::in(self::MONEY_METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'customer.phone.regex' => __('Enter a valid phone number, e.g. 0712 345 678.'),
        ]);

        $branchId = $this->branch()->id();
        $account = DB::transaction(function () use ($data, $branchId) {
            $customer = ! empty($data['customer_id'])
                ? Customer::query()->findOrFail($data['customer_id'])
                : $this->customers->findOrCreateForCredit($data['customer'], $branchId);

            $account = $this->savings->open($customer, $branchId, $data['notes'] ?? null);
            if (! empty($data['initial_deposit'])) {
                $this->savings->deposit($account, [
                    'amount' => $data['initial_deposit'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'notes' => __('Opening deposit'),
                ], $branchId);
            }

            return $account->fresh(['customer:id,name,phone,address']);
        });

        return $this->created($account, __('Savings account :account opened for :customer.', ['account' => $account->account_no, 'customer' => $account->customer->name]));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = SavingsAccount::withTrashed()->with(['customer:id,name,phone,address,balance', 'branch:id,name', 'creator'])->findOrFail($id);
        $transactions = $this->transactionsQuery($request)->where('savings_account_id', $account->id)
            ->paginate($this->perPage($request, 50));

        return $this->paginated($transactions, ['account' => $account, 'summary' => [
            'total_deposited' => (float) $account->total_deposited,
            'total_spent' => (float) $account->total_spent,
            'total_withdrawn' => (float) $account->total_withdrawn,
            'balance' => (float) $account->balance,
            'outstanding_debt' => (float) $account->customer?->balance,
        ]]);
    }

    public function deposit(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'method' => ['required', Rule::in(self::MONEY_METHODS)],
            'provider' => ['nullable', 'string', 'max:40'],
            'reference' => [Rule::requiredIf(fn () => in_array($request->input('method'), ['mobile_money', 'bank', 'cheque', 'card'], true)), 'nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'transacted_at' => ['nullable', 'date', 'before_or_equal:now'],
        ], ['reference.required' => __('Enter the transaction ID / reference.')]);

        $tx = $this->savings->deposit(SavingsAccount::query()->findOrFail($id), $data, $this->branch()->id());

        return $this->created($tx, __('Deposit of :amount recorded. New savings balance: :balance.', [
            'amount' => number_format((float) $tx->amount, 2), 'balance' => number_format((float) $tx->balance_after, 2),
        ]));
    }

    public function withdraw(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'method' => ['required', Rule::in(self::MONEY_METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['required', 'string', 'min:3', 'max:500'],
        ], ['notes.required' => __('Give the reason for paying out savings.')]);

        $tx = $this->savings->withdraw(SavingsAccount::query()->findOrFail($id), $data, $this->branch()->id());

        return $this->created($tx, __('Withdrawal of :amount recorded. Remaining savings balance: :balance.', [
            'amount' => number_format((float) $tx->amount, 2), 'balance' => number_format((float) $tx->balance_after, 2),
        ]));
    }

    public function reverse(Request $request, int $id, int $transactionId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $deposit = SavingsTransaction::query()->where('savings_account_id', $id)->findOrFail($transactionId);
        $tx = $this->savings->reverseDeposit($deposit, $data['reason']);

        return $this->created($tx, __('Deposit reversed. New savings balance: :balance.', ['balance' => number_format((float) $tx->balance_after, 2)]));
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(SavingsAccount::STATUSES)],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $account = $this->savings->changeStatus(SavingsAccount::query()->findOrFail($id), $data['status'], $data['reason']);

        return $this->ok($account, __('Savings account is now :status.', ['status' => __($account->status)]));
    }

    /** All savings movements across accounts (for the savings journal / reports). */
    public function transactions(Request $request): JsonResponse
    {
        $query = $this->transactionsQuery($request)->with('customer:id,name,phone');

        return $this->paginated($query->paginate($this->perPage($request, 50)));
    }

    // ---------------------------------------------------------------- helpers

    private function filtered(Request $request)
    {
        return $this->applyTrashed(SavingsAccount::query(), $request)
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('branch_id'), fn ($q, $v) => $q->where('branch_id', $v))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('account_no', 'like', "%{$t}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%"))));
    }

    private function transactionsQuery(Request $request)
    {
        return SavingsTransaction::query()
            ->with(['sale:id,number,total', 'creator', 'branch:id,name'])
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('customer_id'), fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->query('date_from'), fn ($q, $v) => $q->where('transacted_at', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->where('transacted_at', '<=', $v.' 23:59:59'))
            ->orderByDesc('transacted_at')->orderByDesc('id');
    }
}

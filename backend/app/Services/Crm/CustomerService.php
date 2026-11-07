<?php

namespace App\Services\Crm;

use App\Exceptions\BusinessException;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Crm\SavingsAccount;
use App\Models\Crm\SavingsTransaction;
use App\Models\Sales\Payment;
use App\Models\Sales\Sale;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use App\Services\Core\SmsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data, ?int $branchId): Customer
    {
        $data['code'] = $this->numbers->next('customer');
        $data['branch_id'] = $data['branch_id'] ?? $branchId;
        $data['phone'] = isset($data['phone']) ? $this->cleanPhone($data['phone']) : null;

        return Customer::create($data);
    }

    /**
     * A walk-in customer taking goods on credit: reuse the customer with the same
     * phone number, otherwise register them now so the debt can be followed up.
     *
     * @param  array{name: string, phone: string, address: string}  $data
     */
    public function findOrCreateForCredit(array $data, ?int $branchId): Customer
    {
        $phone = $this->cleanPhone($data['phone']);
        $customer = Customer::query()->where('phone', $phone)->first();

        if ($customer) {
            if (! $customer->is_active) {
                throw new BusinessException(__('The customer with phone :phone is deactivated.', ['phone' => $phone]));
            }
            if (! $customer->address && ! empty($data['address'])) {
                $customer->address = $data['address'];
                $customer->save();
            }

            return $customer;
        }

        $customer = $this->create([
            'name' => $data['name'],
            'phone' => $phone,
            'address' => $data['address'],
            'allow_credit' => true,
            'is_active' => true,
            'notes' => __('Registered at the till for a credit sale.'),
        ], $branchId);
        $this->audit->event('customer.registered_for_credit', __('Customer :name registered for a credit sale', ['name' => $customer->name]), $customer);

        return $customer;
    }

    /** Self registration from the online shop: creates the login and the customer profile together. */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $phone = $this->cleanPhone($data['phone']);
            $user = User::create([
                'type' => User::TYPE_CUSTOMER,
                'name' => $data['name'],
                'username' => $this->uniqueUsername($data['email'] ?? $phone),
                'email' => $data['email'] ?? null,
                'phone' => $phone,
                'password' => $data['password'],
                'is_active' => true,
            ]);

            // Link to an existing walk-in customer with the same phone, if there is one without an account.
            $customer = Customer::query()->where('phone', $phone)->whereNull('user_id')->first();
            if ($customer) {
                $customer->fill(['user_id' => $user->id, 'email' => $customer->email ?: ($data['email'] ?? null)])->save();
            } else {
                $customer = $this->create([
                    'user_id' => $user->id,
                    'name' => $data['name'],
                    'phone' => $phone,
                    'email' => $data['email'] ?? null,
                    'address' => $data['address'] ?? null,
                    'city' => $data['city'] ?? null,
                ], null);
            }

            $this->audit->event('customer.registered', "Customer {$customer->name} registered online", $customer, [], 'auth', $user->id, $user->name);

            return $user->load('customer');
        });
    }

    /**
     * Staff create or update the login of a customer.
     *
     * @param  array{username?: ?string, email?: ?string, phone?: ?string, password?: ?string, is_active?: bool}  $data
     */
    public function upsertAccount(Customer $customer, array $data): User
    {
        return DB::transaction(function () use ($customer, $data) {
            $user = $customer->user;
            if (! $user) {
                if (empty($data['password'])) {
                    throw new BusinessException(__('Set a password for the new customer login.'));
                }
                $user = User::create([
                    'type' => User::TYPE_CUSTOMER,
                    'name' => $customer->name,
                    'username' => $data['username'] ?? $this->uniqueUsername($data['email'] ?? $customer->email ?? $customer->phone ?? $customer->code),
                    'email' => $data['email'] ?? $customer->email,
                    'phone' => $data['phone'] ?? $customer->phone,
                    'password' => $data['password'],
                    'is_active' => $data['is_active'] ?? true,
                    'must_change_password' => true,
                ]);
                $customer->user_id = $user->id;
                $customer->save();
            } else {
                $fill = array_filter([
                    'username' => $data['username'] ?? null,
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'] ?? null,
                ], fn ($v) => $v !== null);
                if (array_key_exists('is_active', $data)) {
                    $fill['is_active'] = (bool) $data['is_active'];
                }
                $user->fill($fill);
                if (! empty($data['password'])) {
                    $user->password = $data['password'];
                    $user->must_change_password = true;
                }
                $user->save();
                if (isset($fill['is_active']) && ! $fill['is_active']) {
                    $user->tokens()->delete();
                }
            }

            return $user;
        });
    }

    /**
     * Statement of account with running balance.
     *
     * @return array{opening_balance: float, closing_balance: float, entries: array}
     */
    public function statement(Customer $customer, Carbon $from, Carbon $to): array
    {
        $salesBefore = (float) Sale::query()->completed()->where('customer_id', $customer->id)->where('sold_at', '<', $from)->sum(DB::raw('total - returned_amount'));
        $paidBefore = (float) Payment::query()->where('customer_id', $customer->id)->where('paid_at', '<', $from)->whereIn('type', ['sale', 'debt'])->sum('amount');
        $opening = round($salesBefore - $paidBefore, 2);

        $sales = Sale::query()->completed()->where('customer_id', $customer->id)->whereBetween('sold_at', [$from, $to])
            ->get(['id', 'number', 'sold_at', 'total', 'returned_amount', 'channel'])
            ->map(fn (Sale $s) => [
                'date' => $s->sold_at->toIso8601String(),
                'reference' => $s->number,
                'description' => 'Sale ('.$s->channel.')'.((float) $s->returned_amount > 0 ? ' less returns '.number_format((float) $s->returned_amount, 2) : ''),
                'debit' => round((float) $s->total - (float) $s->returned_amount, 2),
                'credit' => 0.0,
            ]);

        $payments = Payment::query()->where('customer_id', $customer->id)->whereIn('type', ['sale', 'debt'])->whereBetween('paid_at', [$from, $to])
            ->get(['number', 'paid_at', 'amount', 'method', 'provider', 'reference', 'type'])
            ->map(fn (Payment $p) => [
                'date' => $p->paid_at->toIso8601String(),
                'reference' => $p->number,
                'description' => ($p->type === 'debt' ? 'Debt payment' : 'Payment').' - '.str_replace('_', ' ', $p->method).($p->provider ? " ({$p->provider})" : '').($p->reference ? " #{$p->reference}" : ''),
                'debit' => 0.0,
                'credit' => (float) $p->amount,
            ]);

        $running = $opening;
        $entries = $sales->concat($payments)->sortBy('date')->values()->map(function ($e) use (&$running) {
            $running = round($running + $e['debit'] - $e['credit'], 2);

            return $e + ['balance' => $running];
        });

        return [
            'customer' => $customer->only(['id', 'code', 'name', 'phone', 'balance', 'credit_limit']),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening_balance' => $opening,
            'total_debit' => round((float) $entries->sum('debit'), 2),
            'total_credit' => round((float) $entries->sum('credit'), 2),
            'closing_balance' => $running,
            'entries' => $entries->all(),
        ];
    }

    /**
     * One customer's complete money picture: savings (deposits, purchases from
     * savings, withdrawals), credit (debts, repayments) and a single timeline
     * of every event with the running balances recorded at the time.
     */
    public function financialHistory(Customer $customer, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $account = SavingsAccount::withTrashed()->where('customer_id', $customer->id)->first();

        $credit = Sale::query()->where('customer_id', $customer->id)->completed()
            ->where(fn ($q) => $q->where('is_credit', true)->orWhere('credit_amount', '>', 0));
        $today = now()->toDateString();
        $creditTotals = (clone $credit)->selectRaw(
            'COUNT(*) c, COALESCE(SUM(credit_amount),0) owed, COALESCE(SUM(balance),0) outstanding, '.
            'COALESCE(SUM(CASE WHEN balance > 0 AND due_date < ? THEN balance ELSE 0 END),0) overdue, MIN(CASE WHEN balance > 0 THEN due_date END) next_due',
            [$today]
        )->first();

        $within = fn ($q, string $column) => $q->when($from, fn ($w) => $w->where($column, '>=', $from))->when($to, fn ($w) => $w->where($column, '<=', $to));

        $timeline = collect();
        if ($account) {
            $within(SavingsTransaction::query()->where('savings_account_id', $account->id), 'transacted_at')
                ->with('sale:id,number')->orderBy('transacted_at')->orderBy('id')->get()
                ->each(fn (SavingsTransaction $t) => $timeline->push([
                    'date' => $t->transacted_at,
                    'kind' => 'savings_'.$t->type,
                    'reference' => $t->number,
                    'sale_number' => $t->sale?->number,
                    'amount' => (float) $t->amount,
                    'purchase_amount' => $t->purchase_amount !== null ? (float) $t->purchase_amount : null,
                    'debt_amount' => $t->debt_amount !== null ? (float) $t->debt_amount : null,
                    'savings_before' => (float) $t->balance_before,
                    'savings_after' => (float) $t->balance_after,
                    'debt_before' => null,
                    'debt_after' => null,
                    'method' => $t->method,
                    'notes' => $t->notes,
                ]));
        }

        $within((clone $credit), 'sold_at')->get(['id', 'number', 'sold_at', 'total', 'credit_amount', 'balance', 'due_date'])
            ->each(fn (Sale $s) => $timeline->push([
                'date' => $s->sold_at,
                'kind' => 'credit_sale',
                'reference' => $s->number,
                'sale_number' => $s->number,
                'amount' => (float) $s->credit_amount,
                'purchase_amount' => (float) $s->total,
                'debt_amount' => (float) $s->credit_amount,
                'savings_before' => null,
                'savings_after' => null,
                'debt_before' => null,
                'debt_after' => (float) $s->credit_amount,
                'due_date' => $s->due_date?->toDateString(),
                'current_balance' => (float) $s->balance,
                'method' => 'credit',
                'notes' => null,
            ]));

        $within(Payment::query()->where('customer_id', $customer->id)->where('type', 'debt'), 'paid_at')
            ->with('sale:id,number,due_date')->get()
            ->each(fn (Payment $p) => $timeline->push([
                'date' => $p->paid_at,
                'kind' => 'debt_payment',
                'reference' => $p->number,
                'sale_number' => $p->sale?->number,
                'amount' => (float) $p->amount,
                'purchase_amount' => null,
                'debt_amount' => null,
                'savings_before' => null,
                'savings_after' => null,
                'debt_before' => $p->balance_before !== null ? (float) $p->balance_before : null,
                'debt_after' => $p->balance_after !== null ? (float) $p->balance_after : null,
                'due_date' => $p->sale?->due_date?->toDateString(),
                'method' => $p->method,
                'notes' => $p->notes,
            ]));

        return [
            'customer' => $customer->only(['id', 'name', 'phone', 'address', 'customer_type', 'credit_limit']),
            'savings' => $account ? [
                'account_id' => $account->id,
                'account_no' => $account->account_no,
                'status' => $account->status,
                'total_deposited' => (float) $account->total_deposited,
                'total_spent' => (float) $account->total_spent,
                'total_withdrawn' => (float) $account->total_withdrawn,
                'balance' => (float) $account->balance,
            ] : null,
            'credit' => [
                'sales' => (int) $creditTotals->c,
                'original_owed' => round((float) $creditTotals->owed, 2),
                'repaid' => round((float) $creditTotals->owed - (float) $creditTotals->outstanding, 2),
                'outstanding' => round((float) $creditTotals->outstanding, 2),
                'overdue' => round((float) $creditTotals->overdue, 2),
                'next_due_date' => $creditTotals->next_due ? Carbon::parse($creditTotals->next_due)->toDateString() : null,
            ],
            // Positive = the business owes the customer (savings); negative = the customer owes.
            'net_position' => round(((float) ($account?->balance ?? 0)) - (float) $creditTotals->outstanding, 2),
            'timeline' => $timeline->sortBy(fn ($row) => Carbon::parse($row['date'])->getTimestamp())->values()->all(),
        ];
    }

    public function cleanPhone(?string $phone): ?string
    {
        return SmsService::normalizePhone($phone) ?? ($phone ? preg_replace('/[^\d+]/', '', $phone) : null);
    }

    private function uniqueUsername(string $seed): string
    {
        $base = Str::of(Str::before($seed, '@'))->lower()->replaceMatches('/[^a-z0-9_\-]/', '')->limit(40, '')->value() ?: 'customer';
        $username = $base;
        $i = 1;
        while (User::withTrashed()->where('username', $username)->exists()) {
            $username = $base.$i++;
        }

        return $username;
    }
}

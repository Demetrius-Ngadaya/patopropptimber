<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CustomerRequest;
use App\Models\Crm\Customer;
use App\Models\Sales\Payment;
use App\Services\Crm\CustomerService;
use App\Services\Messaging\CustomerMessages;
use App\Services\Reports\ReportService;
use App\Services\Sales\SaleService;
use App\Support\Channels;
use App\Support\DeliveryReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->applyTrashed(Customer::query(), $request)
            ->with(['user:id,username,is_active,last_login_at'])
            ->search($request->query('search'))
            ->when($request->query('filter') === 'debtors', fn ($q) => $q->where('balance', '>', 0))
            ->when($request->query('filter') === 'online', fn ($q) => $q->whereNotNull('user_id'))
            ->when($request->query('filter') === 'overdue', fn ($q) => $q->whereHas('sales', fn ($s) => $s->completed()->where('balance', '>', 0)->where('due_date', '<', now()->startOfDay())))
            ->when($request->query('customer_type'), fn ($q, $v) => $q->where('customer_type', $v));

        $this->sortable($query, $request, ['name', 'balance', 'created_at', 'code'], 'name', 'asc');

        return $this->paginated($query->paginate($this->perPage($request)), [
            'summary' => [
                'total_debt' => round((float) Customer::query()->where('balance', '>', 0)->sum('balance'), 2),
                'debtors' => Customer::query()->where('balance', '>', 0)->count(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $customer = Customer::withTrashed()->with(['user:id,username,email,phone,is_active,last_login_at', 'branch:id,name', 'creator'])->findOrFail($id);

        return $this->ok([
            'customer' => $customer,
            'unpaid_sales' => $customer->sales()->completed()->where('balance', '>', 0)->orderBy('due_date')
                ->get(['id', 'number', 'sold_at', 'total', 'amount_paid', 'balance', 'due_date', 'branch_id']),
            'recent_sales' => $customer->sales()->latest('sold_at')->limit(10)
                ->get(['id', 'number', 'sold_at', 'total', 'balance', 'payment_status', 'status', 'channel']),
            'recent_payments' => Payment::query()->where('customer_id', $id)->latest('paid_at')->limit(10)
                ->get(['id', 'number', 'paid_at', 'amount', 'method', 'provider', 'reference', 'type']),
            'orders' => $customer->orders()->latest()->limit(10)->get(['id', 'number', 'status', 'total', 'created_at']),
            'stats' => [
                'total_purchases' => round((float) $customer->sales()->completed()->sum('total'), 2),
                'invoices' => $customer->sales()->completed()->count(),
            ],
        ]);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->guardCreditFields($request, $data);
        $customer = $this->customers->create($data, $this->branch()->id());

        return $this->created($customer, __('Customer :customer created successfully.', ['customer' => $customer->name]));
    }

    public function update(CustomerRequest $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $data = $request->validated();
        $this->guardCreditFields($request, $data, $customer);
        if (array_key_exists('phone', $data)) {
            $data['phone'] = $this->customers->cleanPhone($data['phone']);
        }
        $customer->fill($data)->save();

        if ($customer->user && $customer->wasChanged('name')) {
            $customer->user->forceFill(['name' => $customer->name])->save();
        }

        return $this->ok($customer->fresh(), __('Customer updated successfully.'));
    }

    public function destroy(int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        if ((float) $customer->balance > 0) {
            throw new BusinessException(__(':customer still owes :amount. Clear the debt before deleting.', ['customer' => $customer->name, 'amount' => number_format((float) $customer->balance, 2)]));
        }
        $customer->user?->tokens()->delete();
        $customer->user?->delete();
        $customer->delete();

        return $this->ok(null, __('Customer deleted. The record can be restored.'));
    }

    public function restore(int $id): JsonResponse
    {
        $customer = Customer::onlyTrashed()->findOrFail($id);
        $customer->restore();
        $customer->user()->withTrashed()->first()?->restore();

        return $this->ok($customer, __('Customer restored successfully.'));
    }

    public function statement(Request $request, int $id, ReportService $reports): JsonResponse
    {
        $customer = Customer::withTrashed()->findOrFail($id);
        [$from, $to] = $reports->range([
            'date_from' => $request->query('date_from', now()->subMonths(3)->toDateString()),
            'date_to' => $request->query('date_to'),
        ]);

        return $this->ok($this->customers->statement($customer, $from, $to));
    }

    /** Savings, credit and repayments for one customer, in one timeline. */
    public function financialHistory(Request $request, int $id): JsonResponse
    {
        $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);
        $customer = Customer::withTrashed()->findOrFail($id);

        return $this->ok($this->customers->financialHistory(
            $customer,
            $request->query('date_from') ? Carbon::parse($request->query('date_from'))->startOfDay() : null,
            $request->query('date_to') ? Carbon::parse($request->query('date_to'))->endOfDay() : null,
        ));
    }

    public function receivePayment(Request $request, int $id, SaleService $sales): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', Rule::in(Payment::TILL_METHODS)],
            'provider' => ['nullable', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:100', Rule::requiredIf(in_array($request->input('method'), ['mobile_money', 'bank', 'cheque'], true))],
            'sale_id' => ['nullable', 'integer', 'exists:sales,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['reference.required' => __('Enter the transaction ID / reference.')]);

        $payments = $sales->receiveDebtPayment($customer, $data, $request->user(), $this->branch()->requireId());

        return $this->created([
            'payments' => $payments,
            'customer' => $customer->fresh(),
        ], __('Payment of :amount received from :customer.', ['amount' => number_format((float) $data['amount'], 2), 'customer' => $customer->name]));
    }

    public function remind(Request $request, int $id, CustomerMessages $messages): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $data = $request->validate(['channels' => ['nullable', 'array'], 'channels.*' => ['string', 'in:sms,whatsapp,email']]);
        if ((float) $customer->balance <= 0) {
            throw new BusinessException(__(':customer has no debt.', ['customer' => $customer->name]));
        }
        if (! $customer->phone && ! $customer->email) {
            throw new BusinessException(__(':customer has no phone number or email.', ['customer' => $customer->name]));
        }
        $channels = Channels::filter($data['channels'] ?? Channels::forType('customer_debt_reminder') ?: [Channels::SMS]);
        if ($channels === []) {
            throw new BusinessException(__('Choose at least one way of sending.'));
        }
        $result = $messages->debtReminder($customer, $channels);

        return $this->ok(['result' => $result, 'sent' => DeliveryReport::sentAny($result)], DeliveryReport::message($result));
    }

    /** Create / update / disable the online login of a customer. */
    public function account(Request $request, int $id): JsonResponse
    {
        $customer = Customer::with('user')->findOrFail($id);
        $userId = $customer->user_id;
        $data = $request->validate([
            'username' => ['nullable', 'string', 'min:3', 'max:60', 'alpha_dash', Rule::unique('users', 'username')->ignore($userId)],
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($userId)],
            'password' => [$userId ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'is_active' => ['boolean'],
        ]);

        $user = $this->customers->upsertAccount($customer, $data);

        return $this->ok($user->only(['id', 'username', 'email', 'phone', 'is_active']), $userId ? 'Customer login updated.' : 'Customer login created. They will be asked to change the password at first login.');
    }

    private function guardCreditFields(Request $request, array &$data, ?Customer $customer = null): void
    {
        if ($request->user()->hasPermission('customers.set_credit')) {
            return;
        }
        foreach (['credit_limit', 'allow_credit'] as $field) {
            if (array_key_exists($field, $data) && (! $customer || $data[$field] != $customer->{$field})) {
                if ($customer || ! empty($data[$field])) {
                    throw new BusinessException(__('You are not allowed to set credit limits.'), 'FORBIDDEN', 403);
                }
            }
        }
    }
}

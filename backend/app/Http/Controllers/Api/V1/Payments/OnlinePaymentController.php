<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Crm\Customer;
use App\Models\Payments\OnlinePayment;
use App\Services\Payments\OnlinePaymentService;
use App\Services\Payments\SnippeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Payments by phone (Snippe USSD push): requested at the till, followed while
 * the customer enters their PIN, and listed for reconciliation.
 */
class OnlinePaymentController extends Controller
{
    public function __construct(
        private readonly OnlinePaymentService $payments,
        private readonly SnippeClient $gateway,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([...OnlinePayment::STATUSES, 'unused'])],
            'purpose' => ['nullable', Rule::in(['pos', 'order'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:60'],
        ]);
        $from = Carbon::parse($f['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($f['to'] ?? now())->endOfDay();

        $base = $this->branch()->scope(OnlinePayment::query())->whereBetween('created_at', [$from, $to]);
        $query = (clone $base)
            ->with(['branch:id,name', 'sale:id,number', 'order:id,number', 'customer:id,name', 'requester'])
            ->when(($f['status'] ?? null) === 'unused', fn ($q) => $q->where('status', 'completed')->whereNull('sale_id')->whereNull('used_at'))
            ->when(isset($f['status']) && $f['status'] !== 'unused', fn ($q) => $q->where('status', $f['status']))
            ->when($f['purpose'] ?? null, fn ($q, $p) => $q->where('purpose', $p))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('phone', 'like', '%'.ltrim($s, '0+').'%')
                ->orWhere('reference', 'like', "%{$s}%")->orWhere('external_reference', 'like', "%{$s}%")->orWhere('payer_name', 'like', "%{$s}%")))
            ->latest('id');

        $done = (clone $base)->where('status', 'completed');

        return $this->paginated($query->paginate($this->perPage($request)), [
            'summary' => [
                'received' => round((float) (clone $done)->sum('amount'), 2),
                'fees' => round((float) (clone $done)->sum('fee'), 2),
                'count' => (clone $done)->count(),
                'unused' => round((float) (clone $done)->whereNull('sale_id')->whereNull('used_at')->sum('amount'), 2),
                'waiting' => (clone $base)->where('status', 'pending')->count(),
                'failed' => (clone $base)->whereIn('status', ['failed', 'expired', 'voided'])->count(),
            ],
        ]);
    }

    /** Ask the customer at the till to pay by phone. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:50000000'],
            'phone' => ['required', 'string', 'max:20'],
            'payer_name' => ['nullable', 'string', 'max:150'],
            'customer_id' => ['nullable', 'integer'],
        ]);
        $customer = isset($data['customer_id']) ? Customer::query()->find($data['customer_id']) : null;

        $payment = $this->payments->request([
            'purpose' => 'pos',
            'amount' => (float) $data['amount'],
            'phone' => $data['phone'],
            'payer_name' => $data['payer_name'] ?? null,
            'customer' => $customer,
            'branch_id' => $this->branch()->requireId(),
            'user' => $request->user(),
        ]);

        return $this->created($payment, __('Payment request sent to :phone. Ask the customer to enter their PIN.', ['phone' => $payment->phone]));
    }

    /** Polled by the till while the customer confirms; asks the gateway now and then. */
    public function show(Request $request, int $id): JsonResponse
    {
        $payment = $this->find($id);
        $payment = $this->payments->refresh($payment, $request->boolean('force'));

        return $this->ok($payment->load(['sale:id,number', 'order:id,number', 'branch:id,name', 'requester']));
    }

    public function resend(int $id): JsonResponse
    {
        $this->payments->resend($this->find($id));

        return $this->ok(null, __('The request was sent to the phone again.'));
    }

    public function cancel(int $id): JsonResponse
    {
        $payment = $this->find($id);
        if ($payment->purpose !== 'pos') {
            throw new BusinessException(__('Only requests made at the till can be cancelled here.'));
        }

        return $this->ok($this->payments->cancel($payment), __('Stopped waiting for this payment.'));
    }

    /** Settings page: is it set up, where should Snippe send results, and (on request) does the key work. */
    public function status(Request $request): JsonResponse
    {
        $data = $this->payments->availability();
        if ($request->boolean('test')) {
            try {
                $data['balance'] = $this->gateway->balance();
                $data['test'] = ['ok' => true, 'message' => __('Connected to Snippe.')];
            } catch (BusinessException $e) {
                $data['test'] = ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        return $this->ok($data);
    }

    private function find(int $id): OnlinePayment
    {
        return $this->branch()->scope(OnlinePayment::query())->findOrFail($id);
    }
}

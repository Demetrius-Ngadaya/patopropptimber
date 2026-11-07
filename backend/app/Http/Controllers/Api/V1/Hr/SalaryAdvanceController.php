<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Employee;
use App\Models\Hr\SalaryAdvance;
use App\Services\Hr\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalaryAdvanceController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): JsonResponse
    {
        $query = SalaryAdvance::query()->with('employee:id,employee_no,first_name,middle_name,last_name')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'gt:0'],
            'installments' => ['required', 'integer', 'min:1', 'max:12'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $advance = $this->payroll->requestAdvance(Employee::query()->findOrFail($data['employee_id']), (float) $data['amount'], (int) $data['installments'], $data['reason'] ?? null);

        return $this->created($advance, __('Advance request :number recorded.', ['number' => $advance->number]));
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);
        $advance = $this->payroll->decideAdvance(SalaryAdvance::query()->findOrFail($id), $data['approve'], $request->user(), $data['note'] ?? null);

        return $this->ok($advance, $data['approve'] ? __('Advance approved.') : __('Advance rejected.'));
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['method' => ['required', Rule::in(['bank', 'mobile_money', 'cash'])], 'reference' => ['nullable', 'string', 'max:100']]);
        $advance = $this->payroll->payAdvance(SalaryAdvance::query()->findOrFail($id), $data['method'], $data['reference'] ?? null);

        return $this->ok($advance, __('Advance paid. It will be recovered from salary.'));
    }
}

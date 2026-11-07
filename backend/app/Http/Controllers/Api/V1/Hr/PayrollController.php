<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\Payslip;
use App\Services\Core\AuditLogger;
use App\Services\Core\SettingsService;
use App\Services\Hr\PayrollCalculator;
use App\Services\Hr\PayrollService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->applyTrashed(PayrollRun::query(), $request)->with(['branch:id,name', 'approver', 'creator'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('year'), fn ($q, $v) => $q->where('period', 'like', $v.'-%'))
            ->when(! $this->branch()->canAccessAll(), fn ($q) => $q->where(fn ($w) => $w->whereIn('branch_id', $this->branch()->accessibleIds())->orWhereNull('branch_id')))
            ->orderByDesc('period')->orderByDesc('id');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function show(int $id): JsonResponse
    {
        $run = PayrollRun::withTrashed()->with(['branch:id,name', 'approver', 'creator'])->findOrFail($id);

        return $this->ok(['run' => $run, 'payslips' => $run->payslips()->with('employee:id,employee_no')->orderBy('employee_name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $run = $this->payroll->prepare($data['period'], $data['branch_id'] ?? null, $data['notes'] ?? null);

        return $this->created($run, __('Payroll :number prepared for :count employee(s). Net pay :net.', [
            'number' => $run->number, 'count' => $run->employees, 'net' => number_format((float) $run->net, 2),
        ]));
    }

    public function recalculate(int $id): JsonResponse
    {
        $run = $this->payroll->recalculate(PayrollRun::query()->findOrFail($id));

        return $this->ok($run, __('Payroll recalculated.'));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $run = $this->payroll->approve(PayrollRun::query()->findOrFail($id), $request->user());

        return $this->ok($run, __('Payroll approved and posted to the accounts.'));
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['bank', 'mobile_money', 'cash'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        $run = $this->payroll->pay(PayrollRun::query()->findOrFail($id), $data['method'], $data['reference'] ?? null, $data['paid_on'] ?? null);

        return $this->ok($run, __('Salaries marked as paid.'));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $run = $this->payroll->cancel(PayrollRun::query()->findOrFail($id), $data['reason']);

        return $this->ok($run, __('Payroll cancelled.'));
    }

    public function payslipPdf(int $id, int $payslipId, SettingsService $settings): Response
    {
        $slip = Payslip::query()->where('payroll_run_id', $id)->with(['run', 'employee:id,employee_no'])->findOrFail($payslipId);

        return $this->renderPayslip($slip, $settings);
    }

    public function remit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(PayrollService::REMITTABLE))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'method' => ['required', Rule::in(['bank', 'mobile_money', 'cash'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'date' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $this->payroll->remit($data['type'], (float) $data['amount'], $data['method'], $data['reference'] ?? null, $data['date']);

        return $this->ok(null, __(':type remittance of :amount recorded.', ['type' => strtoupper($data['type']), 'amount' => number_format((float) $data['amount'], 2)]));
    }

    /** Try the tax settings on a salary before running payroll. */
    public function preview(Request $request, PayrollCalculator $calculator): JsonResponse
    {
        $data = $request->validate(['basic' => ['required', 'numeric', 'min:0', 'max:9999999999'], 'allowances' => ['nullable', 'numeric', 'min:0']]);

        return $this->ok($calculator->calculate([
            'basic' => (float) $data['basic'],
            'earnings' => ! empty($data['allowances']) ? [['name' => __('Allowances'), 'amount' => (float) $data['allowances'], 'taxable' => true, 'pensionable' => true]] : [],
            'deductions' => [],
        ]));
    }

    public static function renderPayslip(Payslip $slip, SettingsService $settings): Response
    {
        app(AuditLogger::class)->event('payslip.downloaded', __('Payslip :period of :name downloaded', ['period' => $slip->run->period, 'name' => $slip->employee_name]), $slip->employee, ['payslip_id' => $slip->id], 'security');

        $pdf = Pdf::loadView('pdf.payslip', ['slip' => $slip, 'run' => $slip->run, 'business' => $settings->public()])->setPaper('a4');
        $name = 'payslip-'.$slip->run->period.'-'.($slip->employee?->employee_no ?? $slip->employee_id).'.pdf';

        return $pdf->download($name);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\LeaveApplicationRequest;
use App\Models\Hr\Attendance;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\Payslip;
use App\Services\Core\SettingsService;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\LeaveService;
use App\Services\Hr\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Employee self-service. Everything is resolved from the logged-in user's own
 * employee record: there is no id in these URLs to tamper with.
 */
class SelfServiceController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        $employee = $this->me($request)->load(['department:id,name', 'position:id,title', 'branch:id,name', 'manager:id,first_name,last_name']);
        $employee->makeHidden(['national_id', 'tin', 'bank_account_number']);

        return $this->ok($employee);
    }

    public function payslips(Request $request): JsonResponse
    {
        $slips = Payslip::query()->where('employee_id', $this->me($request)->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', [PayrollRun::APPROVED, PayrollRun::PAID]))
            ->with('run:id,number,period,status,paid_at')->latest('id')->paginate($this->perPage($request, 12));

        return $this->paginated($slips);
    }

    public function payslipPdf(Request $request, int $id, SettingsService $settings): Response
    {
        $slip = Payslip::query()->where('employee_id', $this->me($request)->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', [PayrollRun::APPROVED, PayrollRun::PAID]))
            ->with(['run', 'employee:id,employee_no'])->findOrFail($id);

        return PayrollController::renderPayslip($slip, $settings);
    }

    public function leave(Request $request, LeaveService $leave): JsonResponse
    {
        $employee = $this->me($request);
        $year = (int) now()->year;

        return $this->ok([
            'balances' => LeaveType::query()->where('is_active', true)->get()
                ->filter(fn ($t) => ! $t->gender || ! $employee->gender || $t->gender === $employee->gender)
                ->map(fn ($t) => $leave->balance($employee, $t, $year)->only(['leave_type_id', 'entitled', 'carried_forward', 'adjustment', 'taken', 'remaining']) + ['type' => $t->name, 'is_paid' => $t->is_paid, 'requires_attachment' => $t->requires_attachment])
                ->values(),
            'requests' => LeaveRequest::query()->where('employee_id', $employee->id)->with('leaveType:id,name')->latest('id')->limit(50)->get(),
        ]);
    }

    public function applyLeave(LeaveApplicationRequest $request, LeaveService $leave): JsonResponse
    {
        $result = $leave->request($this->me($request), $request->validated(), $request->file('attachment'));

        return $this->created($result, __('Leave request sent for approval (:days day(s)).', ['days' => (float) $result->days]));
    }

    public function cancelLeave(Request $request, int $id, LeaveService $leave): JsonResponse
    {
        $request_ = LeaveRequest::query()->where('employee_id', $this->me($request)->id)->findOrFail($id);
        if ($request_->status !== 'pending') {
            throw new BusinessException(__('Only pending requests can be withdrawn. Ask HR to cancel approved leave.'));
        }

        return $this->ok($leave->cancel($request_, __('Withdrawn by employee')), __('Leave request withdrawn.'));
    }

    public function attendance(Request $request): JsonResponse
    {
        $month = $request->query('month', now()->format('Y-m'));
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $rows = Attendance::query()->where('employee_id', $this->me($request)->id)
            ->where('date', 'like', $month.'%')->orderBy('date')->get();
        $today = Attendance::query()->where('employee_id', $this->me($request)->id)->whereDate('date', today())->first();

        return $this->ok(['today' => $today, 'records' => $rows]);
    }

    public function clockIn(Request $request, AttendanceService $attendance): JsonResponse
    {
        $record = $attendance->clockIn($this->me($request), $request->ip());

        return $this->created($record, $record->status === 'late' ? __('Clocked in (late) at :time.', ['time' => $record->clock_in->format('H:i')]) : __('Clocked in at :time.', ['time' => $record->clock_in->format('H:i')]));
    }

    public function clockOut(Request $request, AttendanceService $attendance): JsonResponse
    {
        $record = $attendance->clockOut($this->me($request));

        return $this->ok($record, __('Clocked out at :time. Hours worked: :hours.', ['time' => $record->clock_out->format('H:i'), 'hours' => (float) $record->hours_worked]));
    }

    public function advances(Request $request): JsonResponse
    {
        return $this->ok($this->me($request)->advances()->latest('id')->limit(50)->get());
    }

    public function requestAdvance(Request $request, PayrollService $payroll): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'installments' => ['required', 'integer', 'min:1', 'max:12'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $advance = $payroll->requestAdvance($this->me($request), (float) $data['amount'], (int) $data['installments'], $data['reason']);

        return $this->created($advance, __('Advance request sent for approval.'));
    }

    private function me(Request $request): Employee
    {
        return Employee::query()->where('user_id', $request->user()->id)->first()
            ?? throw new BusinessException(__('Your account is not linked to an employee record. Ask HR to link it.'), 'NOT_AN_EMPLOYEE', 404);
    }
}

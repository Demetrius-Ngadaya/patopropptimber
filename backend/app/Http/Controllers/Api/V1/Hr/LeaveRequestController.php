<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\LeaveApplicationRequest;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveBalance;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Services\Core\AuditLogger;
use App\Services\Hr\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveRequestController extends Controller
{
    public function __construct(private readonly LeaveService $leave) {}

    public function index(Request $request): JsonResponse
    {
        $query = LeaveRequest::query()
            ->with(['employee:id,employee_no,first_name,middle_name,last_name,branch_id', 'leaveType:id,name,code', 'decider'])
            ->when(! $this->branch()->canAccessAll(), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where(fn ($w) => $w->whereIn('branch_id', $this->branch()->accessibleIds())->orWhereNull('branch_id'))))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('leave_type_id'), fn ($q, $v) => $q->where('leave_type_id', $v))
            ->when($request->query('date_from'), fn ($q, $v) => $q->whereDate('end_date', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->whereDate('start_date', '<=', $v));
        $this->sortable($query, $request, ['start_date', 'created_at'], 'created_at');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    /** HR records leave on behalf of an employee. */
    public function store(LeaveApplicationRequest $request): JsonResponse
    {
        $data = $request->validated() + $request->validate(['employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')]]);
        $leave = $this->leave->request(Employee::query()->findOrFail($data['employee_id']), $data, $request->file('attachment'));

        return $this->created($leave->load('leaveType:id,name'), __('Leave request :number created for :days day(s).', ['number' => $leave->number, 'days' => (float) $leave->days]));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $leave = $this->leave->decide(LeaveRequest::query()->findOrFail($id), true, $request->user(), $data['note'] ?? null);

        return $this->ok($leave, __('Leave approved.'));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);
        $leave = $this->leave->decide(LeaveRequest::query()->findOrFail($id), false, $request->user(), $data['note']);

        return $this->ok($leave, __('Leave rejected.'));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $leave = $this->leave->cancel(LeaveRequest::query()->findOrFail($id), $data['note'] ?? null);

        return $this->ok($leave, __('Leave cancelled.'));
    }

    public function attachment(int $id): StreamedResponse
    {
        $leave = LeaveRequest::query()->findOrFail($id);
        abort_unless($leave->attachment_path && Storage::disk('local')->exists($leave->attachment_path), 404);

        return Storage::disk('local')->download($leave->attachment_path, $leave->number.'.'.pathinfo($leave->attachment_path, PATHINFO_EXTENSION));
    }

    public function balances(Request $request): JsonResponse
    {
        $year = $request->integer('year') ?: (int) now()->year;
        $types = LeaveType::query()->where('is_active', true)->get();
        $employees = Employee::query()->employed()
            ->when(! $this->branch()->canAccessAll(), fn ($q) => $q->where(fn ($w) => $w->whereIn('branch_id', $this->branch()->accessibleIds())->orWhereNull('branch_id')))
            ->when($request->query('employee_id'), fn ($q, $v) => $q->whereKey($v))
            ->orderBy('first_name')->limit(500)->get();

        return $this->ok($employees->map(fn (Employee $e) => [
            'employee_id' => $e->id,
            'employee' => $e->full_name,
            'balances' => $types->filter(fn ($t) => ! $t->gender || ! $e->gender || $t->gender === $e->gender)
                ->map(fn ($t) => $this->leave->balance($e, $t, $year)->only(['id', 'leave_type_id', 'entitled', 'carried_forward', 'adjustment', 'taken', 'remaining']) + ['type' => $t->name])->values(),
        ]));
    }

    public function adjustBalance(Request $request, int $balanceId): JsonResponse
    {
        $data = $request->validate([
            'adjustment' => ['required', 'numeric', 'between:-366,366'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $balance = LeaveBalance::query()->findOrFail($balanceId);
        $old = (float) $balance->adjustment;
        $balance->update(['adjustment' => $data['adjustment']]);
        app(AuditLogger::class)->event('leave.balance_adjusted', __('Leave balance adjusted from :from to :to', ['from' => $old, 'to' => $data['adjustment']]), $balance->employee, ['reason' => $data['reason'], 'leave_type_id' => $balance->leave_type_id]);

        return $this->ok($balance->fresh(), __('Leave balance updated.'));
    }
}

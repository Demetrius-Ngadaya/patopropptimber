<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Attendance;
use App\Services\Hr\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date']]);
        $branchId = $this->branch()->id();
        // Staff without a branch work company-wide and appear in every branch's register.
        $query = Attendance::query()->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->when(! $branchId && ! $this->branch()->canAccessAll(), fn ($q) => $q->where(fn ($w) => $w->whereIn('branch_id', $this->branch()->accessibleIds())->orWhereNull('branch_id')))
            ->with('employee:id,employee_no,first_name,middle_name,last_name')
            ->when($request->query('date'), fn ($q, $v) => $q->whereDate('date', $v))
            ->when($request->query('date_from'), fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('date');

        return $this->paginated($query->paginate($this->perPage($request, 50)));
    }

    public function record(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.employee_id' => ['required', 'integer', 'distinct', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'rows.*.status' => ['required', Rule::in(Attendance::STATUSES)],
            'rows.*.clock_in' => ['nullable', 'date_format:H:i'],
            'rows.*.clock_out' => ['nullable', 'date_format:H:i'],
            'rows.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
        $count = $this->attendance->record($data['date'], $data['rows']);

        return $this->ok(['saved' => $count], __('Attendance saved for :count employee(s).', ['count' => $count]));
    }

    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        return $this->ok($this->attendance->monthSummary($data['month'], $this->branch()->id()));
    }
}

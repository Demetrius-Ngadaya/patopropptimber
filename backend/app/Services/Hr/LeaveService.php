<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Core\User;
use App\Models\Hr\Attendance;
use App\Models\Hr\Employee;
use App\Models\Hr\Holiday;
use App\Models\Hr\LeaveBalance;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use App\Services\Notifications\StaffNotices;
use Carbon\CarbonPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Leave: working-day counting (Sundays and public holidays excluded),
 * yearly balances with carry-forward, and the request / approval flow.
 */
class LeaveService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly StaffNotices $notices,
    ) {}

    public function workingDays(Carbon $start, Carbon $end): float
    {
        $holidays = $this->holidayDates($start, $end);
        $days = 0;
        foreach (CarbonPeriod::create($start->copy()->startOfDay(), $end->copy()->startOfDay()) as $day) {
            if (! $day->isSunday() && ! in_array($day->toDateString(), $holidays, true)) {
                $days++;
            }
        }

        return (float) $days;
    }

    /** @return array<int, string> Y-m-d dates of holidays in the range (recurring ones repeated every year) */
    public function holidayDates(Carbon $start, Carbon $end): array
    {
        $dates = [];
        foreach (Holiday::query()->get(['date', 'recurring']) as $h) {
            if (! $h->recurring) {
                $dates[] = $h->date->toDateString();

                continue;
            }
            for ($y = $start->year; $y <= $end->year; $y++) {
                $dates[] = Carbon::create($y, $h->date->month, min($h->date->day, Carbon::create($y, $h->date->month)->daysInMonth))->toDateString();
            }
        }

        return array_values(array_filter($dates, fn ($d) => $d >= $start->toDateString() && $d <= $end->toDateString()));
    }

    /** The balance row for a year, created on first use with the entitlement and capped carry-forward. */
    public function balance(Employee $employee, LeaveType $type, int $year): LeaveBalance
    {
        $existing = LeaveBalance::query()->where(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year])->first();
        if ($existing) {
            return $existing;
        }

        $previous = LeaveBalance::query()->where(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year - 1])->first();
        $carry = $previous ? min(max(0, $previous->remaining), (float) $type->max_carry_forward) : 0;

        // Joiners get a share of the year's entitlement for the months they work.
        $entitled = (float) $type->days_per_year;
        if ($employee->hire_date && $employee->hire_date->year === $year) {
            $entitled = round($entitled * (12 - $employee->hire_date->month + 1) / 12 * 2) / 2;
        }

        return LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year,
            'entitled' => $entitled, 'carried_forward' => $carry,
        ]);
    }

    public function request(Employee $employee, array $data, ?UploadedFile $attachment = null): LeaveRequest
    {
        $type = LeaveType::query()->where('is_active', true)->findOrFail($data['leave_type_id']);
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();

        if (! $employee->isEmployed()) {
            throw new BusinessException(__(':name is no longer employed.', ['name' => $employee->full_name]));
        }
        if ($end->lt($start)) {
            throw new BusinessException(__('The leave must end on or after the day it starts.'));
        }
        if ($start->year !== $end->year) {
            throw new BusinessException(__('Split leave that crosses into a new year into two requests.'));
        }
        if ($type->gender && $employee->gender && $type->gender !== $employee->gender) {
            throw new BusinessException(__(':type does not apply to this employee.', ['type' => $type->name]));
        }
        if ($type->requires_attachment && ! $attachment) {
            throw new BusinessException(__('Attach the supporting document (e.g. medical certificate).'), 'ATTACHMENT_REQUIRED');
        }

        $days = $this->workingDays($start, $end);
        if (! empty($data['half_day']) && $start->equalTo($end)) {
            $days = 0.5;
        }
        if ($days <= 0) {
            throw new BusinessException(__('The selected dates have no working days.'));
        }

        $overlap = LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())->exists();
        if ($overlap) {
            throw new BusinessException(__('There is already a leave request covering some of these days.'), 'OVERLAP');
        }

        if ($type->is_paid && (float) $type->days_per_year > 0) {
            $balance = $this->balance($employee, $type, $start->year);
            $pending = (float) LeaveRequest::query()->where(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'status' => 'pending'])
                ->whereYear('start_date', $start->year)->sum('days');
            if ($days > $balance->remaining - $pending + 0.001) {
                throw new BusinessException(__('Not enough :type days: :left left (including pending requests), :days requested.', [
                    'type' => $type->name, 'left' => max(0, $balance->remaining - $pending), 'days' => $days,
                ]), 'INSUFFICIENT_LEAVE');
            }
        }

        $leave = new LeaveRequest([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'start_date' => $start, 'end_date' => $end, 'days' => $days, 'reason' => $data['reason'] ?? null,
            'attachment_path' => $attachment?->store("hr/leave/{$employee->id}", 'local'),
        ]);
        $leave->number = $this->numbers->next('leave');
        $leave->status = 'pending';
        $leave->save();
        $this->notices->leaveRequested($leave);

        return $leave;
    }

    public function decide(LeaveRequest $leave, bool $approve, User $by, ?string $note): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $approve, $by, $note) {
            $leave = LeaveRequest::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            if ($leave->status !== 'pending') {
                throw new BusinessException(__('This request has already been decided.'));
            }
            if ($leave->employee->user_id && $leave->employee->user_id === $by->id) {
                throw new BusinessException(__('You cannot approve your own leave.'), 'SELF_APPROVAL', 403);
            }

            if ($approve) {
                $type = $leave->leaveType;
                if ($type->is_paid && (float) $type->days_per_year > 0) {
                    $balance = $this->balance($leave->employee, $type, $leave->start_date->year);
                    if ((float) $leave->days > $balance->remaining + 0.001) {
                        throw new BusinessException(__('Not enough :type days left (:left).', ['type' => $type->name, 'left' => $balance->remaining]), 'INSUFFICIENT_LEAVE');
                    }
                    $balance->increment('taken', (float) $leave->days);
                } else {
                    $this->balance($leave->employee, $type, $leave->start_date->year)->increment('taken', (float) $leave->days);
                }
                $this->markAttendance($leave);
            }

            $leave->forceFill([
                'status' => $approve ? 'approved' : 'rejected',
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
            $this->audit->event($approve ? 'leave.approved' : 'leave.rejected', __('Leave :number :status', ['number' => $leave->number, 'status' => __($leave->status)]), $leave, ['note' => $note]);

            return $leave;
        });
    }

    /** Pending requests are withdrawn; approved future leave is cancelled and the days returned. */
    public function cancel(LeaveRequest $leave, ?string $note): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $note) {
            $leave = LeaveRequest::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            if (! in_array($leave->status, ['pending', 'approved'], true)) {
                throw new BusinessException(__('Only pending or approved leave can be cancelled.'));
            }
            if ($leave->status === 'approved') {
                if ($leave->start_date->lte(today())) {
                    throw new BusinessException(__('Leave that has already started cannot be cancelled.'));
                }
                LeaveBalance::query()->where(['employee_id' => $leave->employee_id, 'leave_type_id' => $leave->leave_type_id, 'year' => $leave->start_date->year])
                    ->decrement('taken', (float) $leave->days);
                Attendance::query()->where('employee_id', $leave->employee_id)->where('status', 'on_leave')
                    ->whereBetween('date', [$leave->start_date->toDateString(), $leave->end_date->toDateString()])->delete();
            }
            $leave->forceFill(['status' => 'cancelled', 'decision_note' => $note ?: $leave->decision_note])->save();

            return $leave;
        });
    }

    /** Unpaid leave days that fall inside a pay period (used by payroll). */
    public function unpaidDays(Employee $employee, Carbon $from, Carbon $to): float
    {
        $days = 0.0;
        LeaveRequest::query()->where('employee_id', $employee->id)->where('status', 'approved')
            ->whereHas('leaveType', fn ($q) => $q->where('is_paid', false))
            ->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString())
            ->get()->each(function (LeaveRequest $l) use (&$days, $from, $to) {
                if ((float) $l->days === 0.5) {
                    $days += 0.5;

                    return;
                }
                $start = $l->start_date->max($from);
                $end = $l->end_date->min($to);
                $days += $this->workingDays($start, $end);
            });

        return $days;
    }

    private function markAttendance(LeaveRequest $leave): void
    {
        $holidays = $this->holidayDates($leave->start_date, $leave->end_date);
        foreach (CarbonPeriod::create($leave->start_date, $leave->end_date) as $day) {
            if ($day->isSunday() || in_array($day->toDateString(), $holidays, true)) {
                continue;
            }
            Attendance::query()->updateOrCreate(
                ['employee_id' => $leave->employee_id, 'date' => $day->toDateString()],
                ['status' => 'on_leave', 'branch_id' => $leave->employee->branch_id, 'source' => 'manual', 'notes' => $leave->number],
            );
        }
    }
}

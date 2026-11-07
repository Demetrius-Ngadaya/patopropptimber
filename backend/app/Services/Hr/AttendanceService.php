<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\Attendance;
use App\Models\Hr\Employee;
use App\Services\Core\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    private const STANDARD_HOURS = 8.0;

    public function __construct(private readonly SettingsService $settings) {}

    public function clockIn(Employee $employee, ?string $ip): Attendance
    {
        if (! $employee->isEmployed()) {
            throw new BusinessException(__(':name is no longer employed.', ['name' => $employee->full_name]));
        }

        return DB::transaction(function () use ($employee, $ip) {
            $today = Attendance::query()->where('employee_id', $employee->id)->whereDate('date', today())->lockForUpdate()->first();
            if ($today?->clock_in) {
                throw new BusinessException(__('You already clocked in at :time.', ['time' => $today->clock_in->format('H:i')]), 'ALREADY_CLOCKED_IN');
            }
            if ($today && $today->status === 'on_leave') {
                throw new BusinessException(__('You are on approved leave today.'));
            }

            $now = now();
            $attendance = $today ?? new Attendance(['employee_id' => $employee->id, 'date' => today()]);
            $attendance->fill([
                'branch_id' => $employee->branch_id,
                'clock_in' => $now,
                'status' => $this->isLate($now) ? 'late' : 'present',
                'source' => 'self',
                'clock_in_ip' => $ip,
            ])->save();

            return $attendance;
        });
    }

    public function clockOut(Employee $employee): Attendance
    {
        $attendance = Attendance::query()->where('employee_id', $employee->id)->whereDate('date', today())->first();
        if (! $attendance?->clock_in) {
            throw new BusinessException(__('You have not clocked in today.'), 'NOT_CLOCKED_IN');
        }
        if ($attendance->clock_out) {
            throw new BusinessException(__('You already clocked out at :time.', ['time' => $attendance->clock_out->format('H:i')]));
        }
        $attendance->clock_out = now();
        $this->computeHours($attendance);
        $attendance->save();

        return $attendance;
    }

    /**
     * Record or correct attendance for many employees on one day.
     *
     * @param  array<int, array{employee_id: int, status: string, clock_in?: ?string, clock_out?: ?string, notes?: ?string}>  $rows
     */
    public function record(string $date, array $rows): int
    {
        $day = Carbon::parse($date)->startOfDay();
        if ($day->isFuture()) {
            throw new BusinessException(__('Attendance cannot be recorded for a future date.'));
        }

        return DB::transaction(function () use ($day, $rows) {
            $employees = Employee::query()->whereIn('id', collect($rows)->pluck('employee_id'))->get()->keyBy('id');
            $count = 0;
            foreach ($rows as $row) {
                $employee = $employees->get($row['employee_id']);
                if (! $employee) {
                    continue;
                }
                $attendance = Attendance::query()->firstOrNew(['employee_id' => $employee->id, 'date' => $day->toDateString()]);
                $attendance->fill([
                    'branch_id' => $employee->branch_id,
                    'status' => $row['status'],
                    'clock_in' => ! empty($row['clock_in']) ? $day->copy()->setTimeFromTimeString($row['clock_in']) : null,
                    'clock_out' => ! empty($row['clock_out']) ? $day->copy()->setTimeFromTimeString($row['clock_out']) : null,
                    'source' => 'manual',
                    'notes' => $row['notes'] ?? null,
                ]);
                if ($attendance->clock_in && $attendance->clock_out && $attendance->clock_out->lt($attendance->clock_in)) {
                    throw new BusinessException(__(':name: clock-out is before clock-in.', ['name' => $employee->full_name]));
                }
                $this->computeHours($attendance);
                $attendance->save();
                $count++;
            }

            return $count;
        });
    }

    /** Per-employee totals for a month. */
    public function monthSummary(string $month, ?int $branchId = null): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $to = $from->copy()->endOfMonth();

        return Attendance::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->groupBy('employee_id')
            ->selectRaw("employee_id,
                SUM(CASE WHEN status IN ('present','late') THEN 1 WHEN status = 'half_day' THEN 0.5 ELSE 0 END) as days_present,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as days_late,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as days_absent,
                SUM(CASE WHEN status = 'on_leave' THEN 1 ELSE 0 END) as days_on_leave,
                SUM(hours_worked) as hours, SUM(overtime_hours) as overtime")
            ->with('employee:id,employee_no,first_name,middle_name,last_name')
            ->get()
            ->map(fn ($r) => [
                'employee_id' => $r->employee_id,
                'employee' => $r->employee?->full_name,
                'employee_no' => $r->employee?->employee_no,
                'days_present' => (float) $r->days_present,
                'days_late' => (int) $r->days_late,
                'days_absent' => (int) $r->days_absent,
                'days_on_leave' => (int) $r->days_on_leave,
                'hours' => round((float) $r->hours, 2),
                'overtime' => round((float) $r->overtime, 2),
            ])->all();
    }

    private function isLate(Carbon $time): bool
    {
        $start = $time->copy()->setTimeFromTimeString((string) $this->settings->get('hr_work_start_time'));

        return $time->gt($start->addMinutes($this->settings->int('hr_late_grace_minutes')));
    }

    private function computeHours(Attendance $a): void
    {
        if ($a->clock_in && $a->clock_out) {
            $hours = round($a->clock_in->diffInMinutes($a->clock_out) / 60, 2);
            $a->hours_worked = $hours;
            $a->overtime_hours = max(0, round($hours - self::STANDARD_HOURS, 2));
        } else {
            $a->hours_worked = 0;
            $a->overtime_hours = 0;
        }
    }
}

<?php

namespace App\Models\Core;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/** A notification the admin designed: what it reports, who gets it, how and when. */
class NotificationRule extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const FREQUENCIES = ['hourly', 'daily', 'weekly', 'monthly', 'once'];

    public const PERIODS = ['today', 'yesterday', 'last_7_days', 'this_week', 'last_week', 'this_month', 'last_month', 'last_30_days'];

    protected $guarded = ['id', 'next_run_at', 'last_run_at', 'last_result'];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'role_ids' => 'array',
            'send_to_role_users' => 'boolean',
            'include_company_contacts' => 'boolean',
            'skip_when_empty' => 'boolean',
            'is_active' => 'boolean',
            'run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'last_result' => 'array',
        ];
    }

    /** The next time this rule should run after $after (null = never again). */
    public function nextRun(?Carbon $after = null): ?Carbon
    {
        $after ??= now();
        [$h, $m] = array_map('intval', explode(':', $this->time ?: '08:00'));

        switch ($this->frequency) {
            case 'once':
                return $this->run_at && $this->run_at->gt($after) ? $this->run_at->copy() : null;
            case 'hourly':
                $next = $after->copy()->setMinute($m)->setSecond(0);

                return $next->gt($after) ? $next : $next->addHour();
            case 'weekly':
                $next = $after->copy()->setTime($h, $m);
                while ($next->dayOfWeek !== (int) ($this->weekday ?? 1) || $next->lte($after)) {
                    $next->addDay()->setTime($h, $m);
                }

                return $next;
            case 'monthly':
                $day = (int) ($this->month_day ?? 1);
                $next = $after->copy()->startOfMonth();
                for ($i = 0; $i < 3; $i++) {
                    $candidate = $next->copy()->day($day === 0 ? $next->daysInMonth : min($day, $next->daysInMonth))->setTime($h, $m);
                    if ($candidate->gt($after)) {
                        return $candidate;
                    }
                    $next->addMonthNoOverflow();
                }

                return null;
            default: // daily
                $next = $after->copy()->setTime($h, $m);

                return $next->gt($after) ? $next : $next->addDay();
        }
    }

    /** [from, to, label] for the period the message covers, as of $at. */
    public function periodRange(?Carbon $at = null): array
    {
        $at ??= now();

        return match ($this->period) {
            'yesterday' => [$at->copy()->subDay()->startOfDay(), $at->copy()->subDay()->endOfDay(), $at->copy()->subDay()->format('d/m/Y')],
            'last_7_days' => [$at->copy()->subDays(6)->startOfDay(), $at->copy()->endOfDay(), $at->copy()->subDays(6)->format('d/m').' - '.$at->format('d/m/Y')],
            'this_week' => [$at->copy()->startOfWeek(), $at->copy()->endOfDay(), $at->copy()->startOfWeek()->format('d/m').' - '.$at->format('d/m/Y')],
            'last_week' => [$at->copy()->subWeek()->startOfWeek(), $at->copy()->subWeek()->endOfWeek(), $at->copy()->subWeek()->startOfWeek()->format('d/m').' - '.$at->copy()->subWeek()->endOfWeek()->format('d/m/Y')],
            'this_month' => [$at->copy()->startOfMonth(), $at->copy()->endOfDay(), $at->translatedFormat('F Y')],
            'last_month' => [$at->copy()->subMonthNoOverflow()->startOfMonth(), $at->copy()->subMonthNoOverflow()->endOfMonth(), $at->copy()->subMonthNoOverflow()->translatedFormat('F Y')],
            'last_30_days' => [$at->copy()->subDays(29)->startOfDay(), $at->copy()->endOfDay(), $at->copy()->subDays(29)->format('d/m').' - '.$at->format('d/m/Y')],
            default => [$at->copy()->startOfDay(), $at->copy()->endOfDay(), $at->format('d/m/Y')],
        };
    }
}

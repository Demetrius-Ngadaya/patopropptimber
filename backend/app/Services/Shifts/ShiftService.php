<?php

namespace App\Services\Shifts;

use App\Exceptions\BusinessException;
use App\Models\Core\User;
use App\Models\Shifts\Shift;
use App\Models\Shifts\ShiftDelegation;
use App\Models\Shifts\ShiftHandover;
use App\Models\Shifts\ShiftType;
use App\Services\Core\AuditLogger;
use App\Services\Core\SettingsService;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\StaffNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who works when. A person starts and ends their own shift; at the end they
 * hand over to whoever comes next (notes, cash counted) and that person
 * confirms. In an emergency a person asks a colleague to cover their shift;
 * once the colleague accepts, the shift is theirs.
 */
class ShiftService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Plan shifts for several people over several days.
     *
     * @return array{created: int, skipped: array<int, string>}
     */
    public function schedule(array $data, int $branchId): array
    {
        $type = ! empty($data['shift_type_id']) ? ShiftType::query()->findOrFail($data['shift_type_id']) : null;
        $start = $type ? substr((string) $type->start_time, 0, 5) : $data['start_time'];
        $end = $type ? substr((string) $type->end_time, 0, 5) : $data['end_time'];
        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to = Carbon::parse($data['date_to'] ?? $data['date_from'])->startOfDay();
        if ($to->lt($from) || $from->diffInDays($to) > 92) {
            throw new BusinessException(__('Choose up to three months at a time.'));
        }
        $weekdays = array_map('intval', $data['weekdays'] ?? [0, 1, 2, 3, 4, 5, 6]);
        $users = User::query()->whereIn('id', $data['user_ids'])->where('is_active', true)->get()->keyBy('id');

        $created = 0;
        $skipped = [];
        DB::transaction(function () use ($from, $to, $weekdays, $users, $start, $end, $type, $branchId, $data, &$created, &$skipped) {
            for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
                if (! in_array($date->dayOfWeek, $weekdays, true)) {
                    continue;
                }
                [$startsAt, $endsAt] = $this->window($date, $start, $end);
                foreach ($users as $user) {
                    if ($this->overlaps($user->id, $startsAt, $endsAt)) {
                        $skipped[] = __(':name already works at :time', ['name' => $user->name, 'time' => $startsAt->format('d/m H:i')]);

                        continue;
                    }
                    Shift::create([
                        'branch_id' => $branchId,
                        'shift_type_id' => $type?->id,
                        'user_id' => $user->id,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'notes' => $data['notes'] ?? null,
                    ]);
                    $created++;
                }
            }
        });
        foreach ($users as $user) {
            $this->tell($user->id, 'New shifts planned for you', 'Open My shifts to see when you work.', [], '/admin/shifts/mine');
        }

        return ['created' => $created, 'skipped' => array_slice($skipped, 0, 50)];
    }

    public function update(Shift $shift, array $data): Shift
    {
        if (in_array($shift->status, ['completed', 'cancelled', 'missed'], true)) {
            throw new BusinessException(__('This shift is :status and cannot be changed.', ['status' => __($shift->status)]));
        }
        $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $shift->starts_at;
        $endsAt = isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : $shift->ends_at;
        if ($endsAt->lte($startsAt)) {
            throw new BusinessException(__('The shift must end after it starts.'));
        }
        $userId = (int) ($data['user_id'] ?? $shift->user_id);
        if ($this->overlaps($userId, $startsAt, $endsAt, $shift->id)) {
            throw new BusinessException(__('This person already has a shift at that time.'), 'OVERLAP');
        }
        $previous = $shift->user_id;
        $shift->fill(['user_id' => $userId, 'starts_at' => $startsAt, 'ends_at' => $endsAt] + array_intersect_key($data, array_flip(['notes'])))->save();
        if ($previous !== $userId) {
            $this->tell($userId, 'You have been given a shift', ':time', ['time' => $this->label($shift)], '/admin/shifts/mine');
            $this->tell($previous, 'A shift was taken off you', ':time', ['time' => $this->label($shift)], '/admin/shifts/mine');
        }

        return $shift;
    }

    public function cancel(Shift $shift, string $reason): Shift
    {
        if (! in_array($shift->status, ['scheduled', 'in_progress'], true)) {
            throw new BusinessException(__('This shift is :status and cannot be cancelled.', ['status' => __($shift->status)]));
        }
        $shift->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();
        $shift->delegations()->where('status', 'pending')->update(['status' => 'cancelled']);
        $this->tell($shift->user_id, 'Your shift was cancelled', ':time - :reason', ['time' => $this->label($shift), 'reason' => $reason], '/admin/shifts/mine');

        return $shift;
    }

    public function start(Shift $shift, User $user): Shift
    {
        $this->assertOwn($shift, $user);
        if ($shift->status !== 'scheduled') {
            throw new BusinessException(__('This shift is :status.', ['status' => __($shift->status)]));
        }
        $early = max(0, $this->settings->int('shift_start_early_minutes'));
        if (now()->lt($shift->starts_at->copy()->subMinutes($early))) {
            throw new BusinessException(__('This shift starts at :time. You can start it from :from.', ['time' => $shift->starts_at->format('d/m H:i'), 'from' => $shift->starts_at->copy()->subMinutes($early)->format('H:i')]), 'TOO_EARLY');
        }
        if (now()->gte($shift->ends_at)) {
            throw new BusinessException(__('This shift has already ended.'));
        }
        $shift->forceFill(['status' => 'in_progress', 'started_at' => now()])->save();

        return $shift;
    }

    /** End my shift and hand over to the next person (or whoever I choose). */
    public function end(Shift $shift, User $user, array $data): Shift
    {
        $this->assertOwn($shift, $user);
        if ($shift->status !== 'in_progress') {
            throw new BusinessException(__('Start the shift before ending it.'));
        }
        $next = $this->nextShift($shift);
        $toUserId = ! empty($data['to_user_id']) ? (int) $data['to_user_id'] : $next?->user_id;
        if (! $toUserId && $next === null) {
            $toUserId = null;
        }
        if ($this->settings->bool('shift_handover_required') && $next && ! $toUserId) {
            throw new BusinessException(__('Hand over to :name, who works next.', ['name' => $next->user?->name]), 'HANDOVER_REQUIRED');
        }
        if ($toUserId === $user->id) {
            throw new BusinessException(__('Choose the person you hand over to.'));
        }

        return DB::transaction(function () use ($shift, $user, $data, $toUserId, $next) {
            $shift->forceFill(['status' => 'completed', 'ended_at' => now()])->save();
            if ($toUserId) {
                ShiftHandover::create([
                    'shift_id' => $shift->id,
                    'next_shift_id' => $next && $next->user_id === $toUserId ? $next->id : null,
                    'from_user_id' => $user->id,
                    'to_user_id' => $toUserId,
                    'cash_counted' => isset($data['cash_counted']) && $data['cash_counted'] !== '' ? round((float) $data['cash_counted'], 2) : null,
                    'notes' => $data['notes'] ?? null,
                ]);
                $this->tell($toUserId, ':name handed over to you', ':notes', ['name' => $user->name, 'notes' => mb_substr((string) ($data['notes'] ?? __('Please confirm the handover.')), 0, 200)], '/admin/shifts/mine', 'warning');
            }

            return $shift->load('handover');
        });
    }

    public function acceptHandover(ShiftHandover $handover, User $user, array $data): ShiftHandover
    {
        if ($handover->to_user_id !== $user->id) {
            throw new BusinessException(__('This handover is for someone else.'), 'FORBIDDEN', 403);
        }
        if ($handover->status !== 'pending') {
            throw new BusinessException(__('This handover is already confirmed.'));
        }
        $confirmed = isset($data['cash_confirmed']) && $data['cash_confirmed'] !== '' ? round((float) $data['cash_confirmed'], 2) : null;
        $handover->forceFill([
            'status' => 'accepted',
            'accepted_at' => now(),
            'receiver_comment' => $data['comment'] ?? null,
            'cash_confirmed' => $confirmed,
        ])->save();

        $this->tell($handover->from_user_id, ':name confirmed your handover', ':comment', ['name' => $user->name, 'comment' => (string) ($data['comment'] ?? '')], '/admin/shifts/mine');
        if ($confirmed !== null && $handover->cash_counted !== null && abs($confirmed - (float) $handover->cash_counted) > 0.004) {
            $this->notifier->notify(new StaffNotice(
                type: 'shift',
                title: 'Cash differs at shift handover',
                body: ':from counted :counted, :to found :found.',
                params: ['from' => $handover->fromUser?->name, 'to' => $user->name, 'counted' => number_format((float) $handover->cash_counted, 2), 'found' => number_format($confirmed, 2)],
                level: 'warning',
                url: '/admin/shifts',
                branchId: $handover->shift?->branch_id,
                permission: 'shifts.manage',
            ));
        }

        return $handover;
    }

    /** Ask a colleague to cover my shift. */
    public function delegate(Shift $shift, User $user, array $data): ShiftDelegation
    {
        $this->assertOwn($shift, $user);
        if (! in_array($shift->status, ['scheduled', 'in_progress'], true)) {
            throw new BusinessException(__('This shift is :status.', ['status' => __($shift->status)]));
        }
        $to = User::query()->where('type', User::TYPE_STAFF)->where('is_active', true)->findOrFail($data['to_user_id']);
        if ($to->id === $user->id) {
            throw new BusinessException(__('Choose a colleague.'));
        }
        if ($this->overlaps($to->id, $shift->starts_at, $shift->ends_at, $shift->id)) {
            throw new BusinessException(__(':name already works at that time.', ['name' => $to->name]), 'OVERLAP');
        }
        if ($shift->delegations()->where('status', 'pending')->exists()) {
            throw new BusinessException(__('You already asked someone to cover this shift. Cancel that request first.'));
        }

        $delegation = $shift->delegations()->create([
            'from_user_id' => $user->id,
            'to_user_id' => $to->id,
            'reason' => $data['reason'],
            'emergency' => (bool) ($data['emergency'] ?? false),
        ]);
        $this->tell($to->id, ':name asks you to cover a shift', ':time - :reason', ['name' => $user->name, 'time' => $this->label($shift), 'reason' => $data['reason']], '/admin/shifts/mine', $delegation->emergency ? 'warning' : 'info');
        if ($delegation->emergency) {
            $this->notifier->notify(new StaffNotice(
                type: 'shift',
                title: 'Emergency: :name asks :to to cover a shift',
                body: ':time - :reason',
                params: ['name' => $user->name, 'to' => $to->name, 'time' => $this->label($shift), 'reason' => $data['reason']],
                level: 'warning',
                url: '/admin/shifts',
                branchId: $shift->branch_id,
                permission: 'shifts.manage',
            ));
        }

        return $delegation;
    }

    public function respond(ShiftDelegation $delegation, User $user, bool $accept, ?string $note): ShiftDelegation
    {
        if ($delegation->to_user_id !== $user->id) {
            throw new BusinessException(__('This request is for someone else.'), 'FORBIDDEN', 403);
        }
        if ($delegation->status !== 'pending') {
            throw new BusinessException(__('This request is already answered.'));
        }
        $shift = $delegation->shift;

        DB::transaction(function () use ($delegation, $accept, $note, $shift, $user) {
            if ($accept) {
                if (! in_array($shift->status, ['scheduled', 'in_progress'], true)) {
                    throw new BusinessException(__('This shift is :status.', ['status' => __($shift->status)]));
                }
                if ($this->overlaps($user->id, $shift->starts_at, $shift->ends_at, $shift->id)) {
                    throw new BusinessException(__('You already work at that time.'), 'OVERLAP');
                }
                $shift->forceFill(['user_id' => $user->id])->save();
            }
            $delegation->forceFill(['status' => $accept ? 'accepted' : 'declined', 'responded_at' => now(), 'response_note' => $note])->save();
        });

        $this->tell($delegation->from_user_id, $accept ? ':name will cover your shift' : ':name cannot cover your shift', ':time', ['name' => $user->name, 'time' => $this->label($shift)], '/admin/shifts/mine', $accept ? 'info' : 'warning');
        if ($accept) {
            $this->audit->event('shift.delegated', __('Shift :time moved from :from to :to: :reason', ['time' => $this->label($shift), 'from' => $delegation->fromUser?->name, 'to' => $user->name, 'reason' => $delegation->reason]), $shift);
        }

        return $delegation;
    }

    public function cancelDelegation(ShiftDelegation $delegation, User $user): void
    {
        if ($delegation->from_user_id !== $user->id || $delegation->status !== 'pending') {
            throw new BusinessException(__('This request cannot be cancelled.'));
        }
        $delegation->forceFill(['status' => 'cancelled'])->save();
    }

    /** Shifts that ended without being started become "missed". */
    public function markMissed(): int
    {
        return Shift::query()->where('status', 'scheduled')->where('ends_at', '<', now())->update(['status' => 'missed']);
    }

    /** The next shift at the branch after this one, worked by someone else. */
    public function nextShift(Shift $shift): ?Shift
    {
        return Shift::query()->with('user:id,name')
            ->where('branch_id', $shift->branch_id)->where('id', '!=', $shift->id)
            ->where('user_id', '!=', $shift->user_id)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereBetween('starts_at', [$shift->ends_at->copy()->subHours(2), $shift->ends_at->copy()->addHours(12)])
            ->orderBy('starts_at')->first();
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: Carbon, 1: Carbon} a night shift ends the next day */
    private function window(Carbon $date, string $start, string $end): array
    {
        $startsAt = $date->copy()->setTimeFromTimeString($start);
        $endsAt = $date->copy()->setTimeFromTimeString($end);
        if ($endsAt->lte($startsAt)) {
            $endsAt->addDay();
        }

        return [$startsAt, $endsAt];
    }

    private function overlaps(int $userId, Carbon $startsAt, Carbon $endsAt, ?int $except = null): bool
    {
        return Shift::query()->where('user_id', $userId)->whereIn('status', ['scheduled', 'in_progress'])
            ->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)->exists();
    }

    private function assertOwn(Shift $shift, User $user): void
    {
        if ($shift->user_id !== $user->id) {
            throw new BusinessException(__('This is not your shift.'), 'FORBIDDEN', 403);
        }
    }

    private function label(Shift $shift): string
    {
        return $shift->starts_at->format('d/m/Y H:i').' - '.$shift->ends_at->format('H:i');
    }

    private function tell(int $userId, string $title, string $body, array $params, string $url, string $level = 'info'): void
    {
        $this->notifier->notify(new StaffNotice(type: 'shift', title: $title, body: $body, params: $params, level: $level, url: $url, userId: $userId));
    }
}

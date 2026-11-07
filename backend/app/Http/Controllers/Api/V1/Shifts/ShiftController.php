<?php

namespace App\Http\Controllers\Api\V1\Shifts;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Models\Shifts\Shift;
use App\Models\Shifts\ShiftDelegation;
use App\Models\Shifts\ShiftHandover;
use App\Models\Shifts\ShiftType;
use App\Services\Shifts\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Shifts: an optional module, switched on in Settings for businesses that work in shifts. */
class ShiftController extends Controller
{
    private const WITH = ['user:id,name', 'type:id,name,color', 'branch:id,name', 'delegations.fromUser:id,name', 'delegations.toUser:id,name', 'handover.toUser:id,name', 'receivedHandover.fromUser:id,name'];

    public function __construct(private readonly ShiftService $shifts) {}

    /** The rota for a period (everyone's with shifts.view, otherwise only mine). */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'user_id' => ['nullable', 'integer']]);
        $from = Carbon::parse($f['from'] ?? now()->startOfWeek())->startOfDay();
        $to = Carbon::parse($f['to'] ?? $from->copy()->addDays(6))->endOfDay();
        $all = $request->user()->hasAnyPermission(['shifts.view', 'shifts.manage']);

        $shifts = $this->branch()->scope(Shift::query())->with(self::WITH)
            ->where('starts_at', '<=', $to)->where('ends_at', '>=', $from)
            ->when(! $all, fn ($q) => $q->where('user_id', $request->user()->id))
            ->when($all && ($f['user_id'] ?? null), fn ($q) => $q->where('user_id', $f['user_id']))
            ->orderBy('starts_at')->get();

        return $this->ok(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'shifts' => $shifts]);
    }

    /** My screen: the shift now, what is coming, and requests waiting for me. */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->shifts->markMissed();
        $mine = Shift::query()->with(self::WITH)->where('user_id', $user->id);
        $current = (clone $mine)->where('status', 'in_progress')->orderBy('starts_at')->first()
            ?? (clone $mine)->where('status', 'scheduled')->where('ends_at', '>', now())->orderBy('starts_at')->first();

        return $this->ok([
            'current' => $current,
            'next_shift' => $current && $current->status === 'in_progress' ? $this->shifts->nextShift($current) : null,
            'upcoming' => (clone $mine)->where('status', 'scheduled')->where('starts_at', '>', now())->when($current, fn ($q) => $q->where('id', '!=', $current->id))
                ->orderBy('starts_at')->limit(30)->get(),
            'recent' => (clone $mine)->whereIn('status', ['completed', 'missed', 'cancelled'])->orderByDesc('starts_at')->limit(10)->get(),
            'cover_requests' => ShiftDelegation::query()->with(['shift.branch:id,name', 'shift.type:id,name', 'fromUser:id,name'])
                ->where('to_user_id', $user->id)->where('status', 'pending')->orderBy('id')->get(),
            'my_requests' => ShiftDelegation::query()->with(['shift', 'toUser:id,name'])
                ->where('from_user_id', $user->id)->where('created_at', '>=', now()->subDays(14))->orderByDesc('id')->limit(10)->get(),
            'handovers' => ShiftHandover::query()->with(['shift:id,starts_at,ends_at,branch_id', 'fromUser:id,name'])
                ->where('to_user_id', $user->id)->where('status', 'pending')->orderBy('id')->get(),
        ]);
    }

    /** Colleagues (to cover a shift, to hand over to, or to plan). */
    public function staff(Request $request): JsonResponse
    {
        $branchId = $this->branch()->id();
        $users = User::query()->visibleTo($request->user())->with('role:id,name')
            ->where('type', User::TYPE_STAFF)->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhere('all_branches', true)))
            ->orderBy('name')->get(['id', 'name', 'role_id', 'branch_id']);

        return $this->ok($users->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role?->name]));
    }

    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'shift_type_id' => ['nullable', 'integer', 'exists:shift_types,id'],
            'start_time' => ['required_without:shift_type_id', 'nullable', 'date_format:H:i'],
            'end_time' => ['required_without:shift_type_id', 'nullable', 'date_format:H:i'],
            'date_from' => ['required', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $result = $this->shifts->schedule($data, $this->branch()->requireId());

        return $this->created($result, $result['skipped']
            ? __(':count shifts planned. :skipped skipped because the person already works then.', ['count' => $result['created'], 'skipped' => count($result['skipped'])])
            : __(':count shifts planned.', ['count' => $result['created']]));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->ok($this->shifts->update($this->find($id), $data)->load(self::WITH), __('Shift saved.'));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];

        return $this->ok($this->shifts->cancel($this->find($id), $reason), __('Shift cancelled.'));
    }

    public function start(Request $request, int $id): JsonResponse
    {
        return $this->ok($this->shifts->start($this->find($id), $request->user()), __('Shift started. Have a good shift!'));
    }

    public function end(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'cash_counted' => ['nullable', 'numeric', 'min:0'],
        ]);
        $shift = $this->shifts->end($this->find($id), $request->user(), $data);

        return $this->ok($shift, $shift->handover ? __('Shift ended and handed over to :name.', ['name' => $shift->handover->toUser?->name]) : __('Shift ended.'));
    }

    public function delegate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'to_user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'emergency' => ['boolean'],
        ]);
        $delegation = $this->shifts->delegate($this->find($id), $request->user(), $data);

        return $this->created($delegation->load('toUser:id,name'), __(':name has been asked to cover your shift.', ['name' => $delegation->toUser?->name]));
    }

    public function respond(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['accept' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);
        $delegation = $this->shifts->respond(ShiftDelegation::query()->findOrFail($id), $request->user(), $data['accept'], $data['note'] ?? null);

        return $this->ok($delegation, $data['accept'] ? __('The shift is now yours.') : __('You declined. :name has been told.', ['name' => $delegation->fromUser?->name]));
    }

    public function cancelDelegation(Request $request, int $id): JsonResponse
    {
        $this->shifts->cancelDelegation(ShiftDelegation::query()->findOrFail($id), $request->user());

        return $this->ok(null, __('Request cancelled.'));
    }

    public function acceptHandover(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000'], 'cash_confirmed' => ['nullable', 'numeric', 'min:0']]);
        $this->shifts->acceptHandover(ShiftHandover::query()->with('shift', 'fromUser:id,name')->findOrFail($id), $request->user(), $data);

        return $this->ok(null, __('Handover confirmed.'));
    }

    // ------------------------------------------------------------------ shift types

    public function types(): JsonResponse
    {
        $id = $this->branch()->id();

        return $this->ok(ShiftType::query()->when($id, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $id)))
            ->orderBy('start_time')->get());
    }

    public function storeType(Request $request): JsonResponse
    {
        $type = ShiftType::create($this->typeData($request));

        return $this->created($type, __(':name saved.', ['name' => $type->name]));
    }

    public function updateType(Request $request, int $id): JsonResponse
    {
        $type = ShiftType::query()->findOrFail($id);
        $type->fill($this->typeData($request))->save();

        return $this->ok($type, __(':name saved.', ['name' => $type->name]));
    }

    public function destroyType(int $id): JsonResponse
    {
        ShiftType::query()->findOrFail($id)->delete();

        return $this->ok(null, __('Shift type removed. Planned shifts keep their times.'));
    }

    private function typeData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'color' => ['nullable', Rule::in(['blue', 'green', 'amber', 'purple', 'red', 'gray'])],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'is_active' => ['boolean'],
        ]);
    }

    private function find(int $id): Shift
    {
        return $this->branch()->scope(Shift::query())->findOrFail($id);
    }
}

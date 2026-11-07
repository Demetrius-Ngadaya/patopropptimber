<?php

namespace App\Http\Controllers\Api\V1\Notes;

use App\Http\Controllers\Controller;
use App\Models\Notes\StaffMessage;
use App\Services\Notes\StaffMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Messages, reminders and announcements from the administration to staff. */
class StaffMessageController extends Controller
{
    public function __construct(private readonly StaffMessageService $messages) {}

    /** My messages (everyone). */
    public function inbox(Request $request): JsonResponse
    {
        $query = $this->messages->inboxQuery($request->user())
            ->when($request->query('filter') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id');
        $unread = (clone $this->messages->inboxQuery($request->user()))->whereNull('read_at')->count();

        return $this->paginated($query->paginate($this->perPage($request)), ['unread' => $unread]);
    }

    /** What must be shown now: reminders / announcements not yet confirmed, and the unread count. */
    public function pending(Request $request): JsonResponse
    {
        return $this->ok([
            'pending' => $this->messages->pending($request->user()),
            'unread' => $this->messages->inboxQuery($request->user())->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $row = $this->messages->recipientFor($id, $request->user());
        $row->read_at ??= now();
        $row->save();

        return $this->ok(null);
    }

    public function acknowledge(Request $request, int $id): JsonResponse
    {
        $row = $this->messages->recipientFor($id, $request->user());
        $row->forceFill(['read_at' => $row->read_at ?? now(), 'acknowledged_at' => $row->acknowledged_at ?? now()])->save();

        return $this->ok(null, __('Thank you. Marked as read.'));
    }

    /** Messages sent (people allowed to send). */
    public function sent(Request $request): JsonResponse
    {
        $query = StaffMessage::query()->with('sender:id,name')
            ->withCount(['recipients', 'recipients as read_count' => fn ($q) => $q->whereNotNull('read_at'), 'recipients as acknowledged_count' => fn ($q) => $q->whereNotNull('acknowledged_at')])
            ->when($request->query('kind'), fn ($q, $v) => $q->where('kind', $v))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function show(int $id): JsonResponse
    {
        $message = StaffMessage::query()->with(['sender:id,name', 'recipients.user:id,name,role_id', 'recipients.user.role:id,name'])->findOrFail($id);

        return $this->ok($message);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(StaffMessage::KINDS)],
            'priority' => ['nullable', Rule::in(['normal', 'high'])],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['required', Rule::in(['all', 'roles', 'users'])],
            'role_ids' => ['required_if:audience,roles', 'nullable', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('is_developer', 0)->whereNull('deleted_at')],
            'user_ids' => ['required_if:audience,users', 'nullable', 'array', 'max:1000'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'requires_ack' => ['nullable', 'boolean'],
            'show_from' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'in:sms,whatsapp,email'],
        ]);
        $message = $this->messages->send($request->user(), $data);

        return $this->created($message->loadCount('recipients'), __(':kind sent to :count people.', ['kind' => __(ucfirst($message->kind)), 'count' => $message->recipients_count]));
    }

    public function destroy(int $id): JsonResponse
    {
        StaffMessage::query()->findOrFail($id)->delete();

        return $this->ok(null, __('Message withdrawn. Staff no longer see it.'));
    }
}

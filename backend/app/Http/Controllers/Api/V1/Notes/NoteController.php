<?php

namespace App\Http\Controllers\Api\V1\Notes;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Models\Notes\Note;
use App\Models\Notes\NoteAttachment;
use App\Models\Notes\NoteMember;
use App\Services\Notes\NoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Personal notes: every staff user has them; sharing decides who else sees one. */
class NoteController extends Controller
{
    public function __construct(private readonly NoteService $notes) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = $request->query('filter', 'all');
        $search = trim((string) $request->query('search', ''));

        $query = Note::query()
            ->join('note_members as me', fn ($j) => $j->on('me.note_id', '=', 'notes.id')->where('me.user_id', $user->id))
            ->select('notes.*', 'me.access as my_access', 'me.pinned_at as my_pinned_at')
            ->with(['owner:id,name', 'members.user:id,name', 'attachments'])
            ->when($filter === 'mine', fn ($q) => $q->where('notes.user_id', $user->id))
            ->when($filter === 'shared', fn ($q) => $q->where('notes.user_id', '!=', $user->id))
            ->when($filter === 'pinned', fn ($q) => $q->whereNotNull('me.pinned_at'))
            ->when($filter === 'reminders', fn ($q) => $q->whereNotNull('notes.remind_at'))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('notes.title', 'like', "%{$search}%")->orWhere('notes.body', 'like', "%{$search}%")))
            // Pinned first, then the most recently changed.
            ->orderByRaw('CASE WHEN me.pinned_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('me.pinned_at')->orderByDesc('notes.updated_at');

        return $this->paginated($query->paginate($this->perPage($request, 30)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $note = Note::query()->findOrFail($id);
        $member = $this->notes->memberOrFail($note, $request->user());

        return $this->ok($note->load(['owner:id,name', 'members.user:id,name', 'attachments'])->toArray() + [
            'my_access' => $member->access,
            'my_pinned_at' => $member->pinned_at,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $note = $this->notes->create($request->user(), $this->validated($request));

        return $this->created($note->load(['members.user:id,name', 'attachments']), __('Note saved.'));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $note = Note::query()->findOrFail($id);
        $this->notes->memberOrFail($note, $request->user());
        $note = $this->notes->update($note, $request->user(), $this->validated($request));

        return $this->ok($note->load(['members.user:id,name', 'attachments']), __('Note saved.'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $note = Note::query()->findOrFail($id);
        $this->notes->memberOrFail($note, $request->user());
        $mine = $note->user_id === $request->user()->id;
        $this->notes->delete($note, $request->user());

        return $this->ok(null, $mine ? __('Note deleted.') : __('Note removed from your list.'));
    }

    public function pin(Request $request, int $id): JsonResponse
    {
        $pinned = $request->validate(['pinned' => ['required', 'boolean']])['pinned'];
        $note = Note::query()->findOrFail($id);
        $this->notes->memberOrFail($note, $request->user());
        $this->notes->pin($note, $request->user(), $pinned);

        return $this->ok(['pinned' => $pinned], $pinned ? __('Pinned to the top.') : __('Unpinned.'));
    }

    public function upload(Request $request, int $id): JsonResponse
    {
        $file = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ], ['file.mimes' => __('Only PDF and images (JPG, PNG, WebP) can be attached.')])['file'];
        $note = Note::query()->findOrFail($id);
        $this->notes->memberOrFail($note, $request->user());

        return $this->created($this->notes->attach($note, $request->user(), $file), __('File attached.'));
    }

    public function download(Request $request, int $attachmentId): StreamedResponse
    {
        $attachment = NoteAttachment::query()->findOrFail($attachmentId);
        $this->notes->memberOrFail(Note::query()->findOrFail($attachment->note_id), $request->user());
        abort_unless(Storage::disk(NoteService::DISK)->exists($attachment->path), 404);

        // Images and PDFs open in the browser; nothing else is ever stored.
        return Storage::disk(NoteService::DISK)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function removeAttachment(Request $request, int $attachmentId): JsonResponse
    {
        $this->notes->detach(NoteAttachment::query()->findOrFail($attachmentId), $request->user());

        return $this->ok(null, __('File removed.'));
    }

    /** Colleagues a note can be shared with. */
    public function people(Request $request): JsonResponse
    {
        return $this->ok(User::query()->visibleTo($request->user())->with('role:id,name')
            ->where('type', User::TYPE_STAFF)->where('is_active', true)->where('id', '!=', $request->user()->id)
            ->orderBy('name')->get(['id', 'name', 'role_id', 'branch_id'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role?->name]));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => [$request->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:20000'],
            'color' => ['nullable', Rule::in(Note::COLORS)],
            'remind_at' => ['nullable', 'date'],
            'pinned' => ['boolean'],
            'shares' => ['sometimes', 'array', 'max:200'],
            'shares.*.user_id' => ['required', 'integer'],
            'shares.*.access' => ['required', Rule::in(NoteMember::ACCESS)],
        ]);
    }
}

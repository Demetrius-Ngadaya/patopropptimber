<?php

namespace App\Services\Notes;

use App\Exceptions\BusinessException;
use App\Models\Core\User;
use App\Models\Notes\Note;
use App\Models\Notes\NoteAttachment;
use App\Models\Notes\NoteMember;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\StaffNotice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Notes belong to their owner, who can share them with colleagues to read
 * ("view") or to change ("edit"). Everyone pins notes for themselves.
 */
class NoteService
{
    public const DISK = 'local';

    public function __construct(private readonly Notifier $notifier) {}

    public function create(User $owner, array $data): Note
    {
        return DB::transaction(function () use ($owner, $data) {
            $note = new Note($this->fields($data));
            $note->user_id = $owner->id;
            $note->updated_by = $owner->id;
            $note->save();
            $note->members()->create(['user_id' => $owner->id, 'access' => 'owner', 'pinned_at' => ! empty($data['pinned']) ? now() : null]);
            if (array_key_exists('shares', $data)) {
                $this->share($note, $owner, (array) $data['shares']);
            }

            return $note;
        });
    }

    public function update(Note $note, User $user, array $data): Note
    {
        $this->assertCanEdit($note, $user);
        $note->fill($this->fields($data));
        if ($note->isDirty('remind_at')) {
            $note->reminded_at = null;
        }
        $note->updated_by = $user->id;
        $note->save();
        if (array_key_exists('shares', $data) && $note->user_id === $user->id) {
            $this->share($note, $user, (array) $data['shares']);
        }

        return $note;
    }

    /**
     * Replace who the note is shared with.
     *
     * @param  array<int, array{user_id: int, access: string}>  $shares
     */
    public function share(Note $note, User $owner, array $shares): void
    {
        if ($note->user_id !== $owner->id) {
            throw new BusinessException(__('Only the owner can choose who sees this note.'), 'FORBIDDEN', 403);
        }
        $wanted = collect($shares)->filter(fn ($s) => (int) $s['user_id'] !== $owner->id)
            ->mapWithKeys(fn ($s) => [(int) $s['user_id'] => in_array($s['access'], NoteMember::ACCESS, true) ? $s['access'] : 'view']);
        $valid = User::query()->visibleTo($owner)->where('type', User::TYPE_STAFF)->where('is_active', true)->whereIn('id', $wanted->keys())->pluck('id');
        $wanted = $wanted->only($valid->all());

        $existing = $note->members()->where('access', '!=', 'owner')->get()->keyBy('user_id');
        foreach ($existing as $userId => $member) {
            if (! $wanted->has($userId)) {
                $member->delete();
            }
        }
        foreach ($wanted as $userId => $access) {
            $member = $existing->get($userId);
            if ($member) {
                $member->update(['access' => $access]);

                continue;
            }
            $note->members()->create(['user_id' => $userId, 'access' => $access]);
            $this->notifier->notify(new StaffNotice(
                type: 'note_reminder',
                title: ':name shared a note with you',
                body: ':title',
                params: ['name' => $owner->name, 'title' => $note->title],
                url: '/admin/notes?open='.$note->id,
                userId: $userId,
            ));
        }
        $note->unsetRelation('members');
    }

    public function pin(Note $note, User $user, bool $pinned): void
    {
        $member = $note->members()->where('user_id', $user->id)->firstOrFail();
        $member->update(['pinned_at' => $pinned ? now() : null]);
    }

    public function attach(Note $note, User $user, UploadedFile $file): NoteAttachment
    {
        $this->assertCanEdit($note, $user);
        if ($note->attachments()->count() >= 20) {
            throw new BusinessException(__('A note can have at most 20 files.'));
        }
        $path = $file->store('notes/'.$note->id, self::DISK);

        return $note->attachments()->create([
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => $user->id,
        ]);
    }

    public function detach(NoteAttachment $attachment, User $user): void
    {
        $note = Note::query()->findOrFail($attachment->note_id);
        $this->assertCanEdit($note, $user);
        Storage::disk(self::DISK)->delete($attachment->path);
        $attachment->delete();
    }

    public function delete(Note $note, User $user): void
    {
        if ($note->user_id !== $user->id) {
            // A colleague removes the note from their own list only.
            $note->members()->where('user_id', $user->id)->delete();

            return;
        }
        $note->delete();
    }

    /** The user's membership (access level) on a note, or 404 when they may not see it. */
    public function memberOrFail(Note $note, User $user): NoteMember
    {
        $member = $note->members()->where('user_id', $user->id)->first();
        abort_unless($member, 404);

        return $member;
    }

    public function canEdit(Note $note, User $user): bool
    {
        return in_array($note->members()->where('user_id', $user->id)->value('access'), ['owner', 'edit'], true);
    }

    private function assertCanEdit(Note $note, User $user): void
    {
        if (! $this->canEdit($note, $user)) {
            throw new BusinessException(__('You can read this note but not change it.'), 'FORBIDDEN', 403);
        }
    }

    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip(['title', 'body', 'color', 'remind_at']));
    }
}

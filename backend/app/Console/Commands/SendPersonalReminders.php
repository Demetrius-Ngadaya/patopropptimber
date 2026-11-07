<?php

namespace App\Console\Commands;

use App\Models\Notes\Note;
use App\Models\Shifts\Shift;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\StaffNotice;
use App\Services\Shifts\ShiftService;
use App\Support\Modules;
use Illuminate\Console\Command;

/** Every minute: note reminders, "your shift starts soon", and shifts nobody started. */
class SendPersonalReminders extends Command
{
    protected $signature = 'mauzo:personal-reminders';

    protected $description = 'Note reminders and shift reminders (bell)';

    public function handle(Notifier $notifier, ShiftService $shifts): int
    {
        $notes = Note::query()->whereNotNull('remind_at')->whereNull('reminded_at')->where('remind_at', '<=', now())->limit(500)->get();
        foreach ($notes as $note) {
            $notifier->notify(new StaffNotice(type: 'note_reminder', title: 'Reminder: :title', body: ':text', params: ['title' => $note->title, 'text' => mb_substr((string) $note->body, 0, 200)], level: 'warning', url: '/admin/notes?open='.$note->id, userId: $note->user_id));
            $note->forceFill(['reminded_at' => now()])->saveQuietly();
        }

        $soon = 0;
        if (Modules::enabled('shifts')) {
            $shifts->markMissed();
            $starting = Shift::query()->where('status', 'scheduled')->whereBetween('starts_at', [now()->addMinutes(55), now()->addMinutes(60)])->get();
            foreach ($starting as $shift) {
                $notifier->notify(new StaffNotice(type: 'shift', title: 'Your shift starts at :time', body: 'Start it on My shifts when you arrive.', params: ['time' => $shift->starts_at->format('H:i')], url: '/admin/shifts/mine', userId: $shift->user_id, dedupeKey: 'shift_soon:'.$shift->id));
                $soon++;
            }
        }
        $this->info("Note reminders: {$notes->count()}, shift reminders: {$soon}.");

        return self::SUCCESS;
    }
}

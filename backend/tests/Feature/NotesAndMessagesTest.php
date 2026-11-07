<?php

namespace Tests\Feature;

use App\Models\Core\ActivityLog;
use App\Models\Core\AppNotification;
use App\Models\Core\Role;
use App\Models\Notes\Note;
use App\Services\Core\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotesAndMessagesTest extends TestCase
{
    public function test_notes_are_private_shared_by_access_level_and_pinned_per_person(): void
    {
        Storage::fake('local');
        $owner = $this->staff('cashier');
        $reader = $this->staff('cashier');
        $stranger = $this->staff('cashier');

        Sanctum::actingAs($owner);
        $note = $this->postJson('/api/v1/admin/notes', [
            'title' => 'Order sugar on Monday', 'body' => 'Ask Juma for the price', 'color' => 'green',
            'shares' => [['user_id' => $reader->id, 'access' => 'view']],
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/admin/notes', ['title' => 'Second note'])->assertCreated();
        $pdf = $this->post("/api/v1/admin/notes/{$note['id']}/attachments", ['file' => UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
        $this->post("/api/v1/admin/notes/{$note['id']}/attachments", ['file' => UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertSame(1, AppNotification::query()->where('type', 'note_reminder')->where('user_id', $reader->id)->count());

        // The note's text never reaches the activity log.
        $logged = ActivityLog::query()->where('route_name', 'admin.notes.store')->latest('id')->first();
        $this->assertNotNull($logged);
        $this->assertNull($logged->payload);

        // The colleague reads it, cannot change it, pins it for themselves only.
        Sanctum::actingAs($reader);
        $this->getJson('/api/v1/admin/notes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.my_access', 'view');
        $this->putJson("/api/v1/admin/notes/{$note['id']}", ['title' => 'Changed'])->assertForbidden();
        $this->get("/api/v1/admin/notes/attachments/{$pdf['id']}")->assertOk();
        $this->postJson("/api/v1/admin/notes/{$note['id']}/pin", ['pinned' => true])->assertOk();
        $this->getJson('/api/v1/admin/notes?filter=pinned')->assertJsonCount(1, 'data');

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/admin/notes?filter=pinned')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/notes')->assertJsonCount(2, 'data');

        // Someone it was not shared with sees nothing.
        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/admin/notes/{$note['id']}")->assertNotFound();
        $this->get("/api/v1/admin/notes/attachments/{$pdf['id']}")->assertNotFound();

        // Given edit access, the colleague can change it; when unshared, it is gone for them.
        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/admin/notes/{$note['id']}", ['shares' => [['user_id' => $reader->id, 'access' => 'edit']]])->assertOk();
        Sanctum::actingAs($reader);
        $this->putJson("/api/v1/admin/notes/{$note['id']}", ['title' => 'Order sugar on Tuesday'])->assertOk();
        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/admin/notes/{$note['id']}", ['shares' => []])->assertOk();
        Sanctum::actingAs($reader);
        $this->getJson("/api/v1/admin/notes/{$note['id']}")->assertNotFound();
        $this->assertSame('Order sugar on Tuesday', Note::find($note['id'])->title);
    }

    public function test_note_reminder_reaches_the_owner(): void
    {
        $owner = $this->actingAsStaff('cashier');
        $this->postJson('/api/v1/admin/notes', ['title' => 'Pay rent', 'remind_at' => now()->subMinute()->toDateTimeString()])->assertCreated();
        $this->artisan('mauzo:personal-reminders')->assertSuccessful();
        $this->artisan('mauzo:personal-reminders')->assertSuccessful();
        $this->assertSame(1, AppNotification::query()->where('type', 'note_reminder')->where('user_id', $owner->id)->count());
    }

    public function test_announcements_show_at_login_until_confirmed(): void
    {
        app(SettingsService::class)->update(['messaging_channels' => ['sms']]);
        $cashier = $this->staff('cashier');
        $storekeeper = $this->staff('storekeeper');
        $this->actingAsStaff();

        $sent = $this->postJson('/api/v1/admin/staff-messages', [
            'kind' => 'announcement', 'priority' => 'high', 'title' => 'Stock count on Saturday', 'body' => 'Everyone comes at 7:00.',
            'audience' => 'roles', 'role_ids' => [Role::query()->where('slug', 'cashier')->value('id')],
        ])->assertCreated()->assertJsonPath('data.recipients_count', 1)->json('data');
        $this->postJson('/api/v1/admin/staff-messages', ['kind' => 'message', 'title' => 'x', 'body' => 'y', 'audience' => 'users', 'user_ids' => [999999]])->assertUnprocessable();

        Sanctum::actingAs($cashier);
        $this->getJson('/api/v1/admin/staff-messages/pending')->assertOk()->assertJsonCount(1, 'data.pending')->assertJsonPath('data.unread', 1);
        $this->postJson('/api/v1/admin/staff-messages', ['kind' => 'message', 'title' => 'x', 'body' => 'y', 'audience' => 'all'])->assertForbidden();
        $this->postJson("/api/v1/admin/staff-messages/{$sent['id']}/acknowledge")->assertOk();
        $this->getJson('/api/v1/admin/staff-messages/pending')->assertJsonCount(0, 'data.pending')->assertJsonPath('data.unread', 0);
        $this->getJson('/api/v1/admin/staff-messages')->assertJsonCount(1, 'data');

        Sanctum::actingAs($storekeeper);
        $this->getJson('/api/v1/admin/staff-messages/pending')->assertJsonCount(0, 'data.pending');
        $this->postJson("/api/v1/admin/staff-messages/{$sent['id']}/acknowledge")->assertNotFound();

        $this->actingAsStaff();
        $this->getJson('/api/v1/admin/staff-messages/sent')->assertOk()->assertJsonPath('data.0.acknowledged_count', 1);
        $this->deleteJson("/api/v1/admin/staff-messages/{$sent['id']}")->assertOk();
        Sanctum::actingAs($cashier);
        $this->getJson('/api/v1/admin/staff-messages')->assertJsonCount(0, 'data');
    }
}

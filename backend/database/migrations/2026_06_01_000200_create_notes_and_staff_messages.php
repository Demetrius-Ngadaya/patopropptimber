<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal notes (with attachments, sharing and pinning) and messages from
 * the administration to staff (plain messages, reminders, announcements).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->comment('owner');
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->string('color', 20)->default('yellow');
            $table->timestamp('remind_at')->nullable()->comment('bell reminder for the owner');
            $table->timestamp('reminded_at')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['remind_at', 'reminded_at']);
        });

        // Who can see a note (owner included) and whether they pinned it.
        Schema::create('note_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('access', 10)->comment('owner | edit | view');
            $table->timestamp('pinned_at')->nullable();
            $table->timestamps();
            $table->unique(['note_id', 'user_id']);
        });

        Schema::create('note_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users');
            $table->string('kind', 20)->default('message')->comment('message | reminder | announcement');
            $table->string('priority', 10)->default('normal')->comment('normal | high');
            $table->string('title', 150);
            $table->text('body');
            $table->string('audience', 10)->comment('all | roles | users');
            $table->json('role_ids')->nullable();
            $table->json('user_ids')->nullable();
            $table->foreignId('branch_id')->nullable()->comment('only staff of this branch');
            $table->boolean('requires_ack')->default(false)->comment('shown at login until the person confirms reading');
            $table->timestamp('show_from')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('staff_message_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['staff_message_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_message_recipients');
        Schema::dropIfExists('staff_messages');
        Schema::dropIfExists('note_attachments');
        Schema::dropIfExists('note_members');
        Schema::dropIfExists('notes');
    }
};

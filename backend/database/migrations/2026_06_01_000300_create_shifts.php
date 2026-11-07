<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff shifts: the usual shift times, who works when, giving a shift to a
 * colleague in an emergency, and handing over at the end of a shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->comment('null = every branch');
            $table->string('name', 60)->comment('e.g. Morning, Evening, Night');
            $table->time('start_time');
            $table->time('end_time')->comment('earlier than start = ends the next day');
            $table->string('color', 20)->default('blue');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('shift_type_id')->nullable()->constrained();
            $table->foreignId('user_id')->constrained();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('scheduled')->comment('scheduled | in_progress | completed | missed | cancelled');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'starts_at']);
            $table->index(['user_id', 'starts_at']);
        });

        Schema::create('shift_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users');
            $table->foreignId('to_user_id')->constrained('users');
            $table->string('reason', 500);
            $table->boolean('emergency')->default(false);
            $table->string('status', 20)->default('pending')->comment('pending | accepted | declined | cancelled');
            $table->string('response_note', 500)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shift_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete()->comment('the shift that is ending');
            $table->foreignId('next_shift_id')->nullable()->constrained('shifts');
            $table->foreignId('from_user_id')->constrained('users');
            $table->foreignId('to_user_id')->constrained('users');
            SchemaColumns::money($table, 'cash_counted', true);
            $table->text('notes')->nullable()->comment('what the next person must know');
            $table->string('status', 20)->default('pending')->comment('pending | accepted');
            $table->string('receiver_comment', 1000)->nullable();
            SchemaColumns::money($table, 'cash_confirmed', true);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_handovers');
        Schema::dropIfExists('shift_delegations');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('shift_types');
    }
};

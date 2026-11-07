<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PatoPro mobile app:
 *  - mobile_devices: one row per app installation (push token, how each kind
 *    of notification should alert: voice / sound / vibration, quiet hours);
 *  - sync_mutations: every change the app made offline and sent later, keyed
 *    by the id the phone gave it, so a change sent twice is applied once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('installation_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable()->index()->comment('personal_access_tokens.id of the login');
            $table->string('push_token', 255)->nullable()->index();
            $table->string('platform', 10)->comment('android | ios');
            $table->string('device_name', 120)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->string('locale', 5)->nullable();
            $table->json('preferences')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('push_failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_mutations', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_id')->unique()->comment('id the phone gave the change');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('mobile_devices')->nullOnDelete();
            $table->string('type', 40)->index();
            $table->unsignedBigInteger('result_id')->nullable()->comment('id of the record the change created or changed');
            $table->json('result')->nullable();
            $table->timestamp('client_created_at')->nullable()->comment('when it happened on the phone');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_mutations');
        Schema::dropIfExists('mobile_devices');
    }
};

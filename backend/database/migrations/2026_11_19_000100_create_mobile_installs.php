<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every phone the PatoPro app was opened on, from the first open (before anyone
 * signs in): which app version, when it was last used, and whether someone has
 * signed in, tried and failed, or never tried. Feeds the "Mobile app users" report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_installs', function (Blueprint $table) {
            $table->id();
            $table->uuid('installation_id')->unique();
            $table->string('platform', 10)->default('android');
            $table->string('device_model', 120)->nullable();
            $table->string('os_version', 40)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->unsignedInteger('build_number')->nullable();
            $table->string('locale', 5)->nullable();
            $table->unsignedInteger('opens')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_login_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedInteger('logins')->default(0);
            $table->unsignedInteger('failed_logins')->default(0);
            $table->timestamp('last_failed_at')->nullable();
            $table->string('last_failed_login', 80)->nullable()->comment('What was typed as the username (never the password)');
            $table->string('last_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_installs');
    }
};

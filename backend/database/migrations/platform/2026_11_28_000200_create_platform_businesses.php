<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Central database only (php artisan platform:install). */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('patopro.platform.connection');
    }

    public function up(): void
    {
        Schema::create('platform_businesses', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();          // patopro.shezoni.com/<slug>
            $table->string('name', 120);
            $table->string('owner_name', 120)->nullable();
            $table->string('phone', 20)->nullable()->index(); // 255XXXXXXXXX
            $table->string('email', 160)->nullable();
            $table->foreignId('plan_id')->nullable()->constrained('platform_plans')->nullOnDelete();
            $table->string('billing_cycle', 10)->default('monthly'); // monthly | yearly

            // Where its data lives.
            $table->string('db_name', 64)->unique();
            $table->string('db_host', 120)->nullable();      // null: the platform's host
            $table->string('db_username', 64)->nullable();   // null: the shared business user
            $table->text('db_password')->nullable();         // encrypted

            // The subscription. The state (trial, active, grace, read-only…) is worked out from these dates.
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('paid_until')->nullable();
            $table->timestamp('suspended_at')->nullable();   // stopped by the Software Engineer, whatever the dates say
            $table->string('suspended_reason', 255)->nullable();
            $table->timestamp('archived_at')->nullable();    // data backed up and taken off the server

            $table->string('status', 20)->default('provisioning'); // provisioning | ready | failed
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_businesses');
    }
};

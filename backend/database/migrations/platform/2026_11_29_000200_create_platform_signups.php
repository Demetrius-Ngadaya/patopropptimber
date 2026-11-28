<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Someone registering a new business: kept until the phone is confirmed
 * with the SMS code, then the business is created from it.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('patopro.platform.connection');
    }

    public function up(): void
    {
        Schema::create('platform_signups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('phone', 20)->index();
            $table->string('slug', 40);
            $table->text('data');                        // encrypted: names, email, password hash, package
            $table->string('code_hash');
            $table->unsignedTinyInteger('codes_sent')->default(1);
            $table->timestamp('code_sent_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('business_id')->nullable()->constrained('platform_businesses')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_signups');
    }
};

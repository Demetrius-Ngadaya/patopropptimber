<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Payments for packages, and the platform's own settings (which gateways are offered). */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('patopro.platform.connection');
    }

    public function up(): void
    {
        Schema::create('platform_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();                 // PP-2026-000123
            $table->foreignId('business_id')->constrained('platform_businesses')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('platform_plans');
            $table->string('billing_cycle', 10);                     // monthly | yearly
            $table->unsignedSmallInteger('months');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('TZS');
            $table->string('gateway', 20);                           // azampay | snippe | manual
            $table->string('method', 30)->nullable();                // Airtel, Tigo, mpesa…
            $table->string('phone', 20)->nullable();
            $table->string('status', 15)->default('pending')->index(); // pending | completed | failed | expired
            $table->string('gateway_reference', 120)->nullable()->index();
            $table->json('gateway_response')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_from')->nullable();
            $table->timestamp('period_to')->nullable();
            $table->string('recorded_by', 120)->nullable();          // who started it (user name) or recorded it (manual)
            $table->string('note', 255)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payments');
        Schema::dropIfExists('platform_settings');
    }
};

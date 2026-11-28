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
        Schema::create('platform_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->decimal('price_monthly', 12, 2)->default(0);
            $table->decimal('price_yearly', 12, 2)->default(0);
            $table->string('currency', 3)->default('TZS');
            $table->unsignedSmallInteger('trial_days')->nullable(); // null: the platform default
            $table->json('features');   // ["*"] or ["sales","purchases","voice",…]
            $table->json('limits');     // {"users":3,"branches":1,"products":500,"sms_per_month":100}; null value = no limit
            $table->boolean('is_active')->default(true);   // can be bought or renewed
            $table->boolean('is_public')->default(true);   // shown on the pricing page
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_plans');
    }
};

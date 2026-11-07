<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website visitor counter: one row per browser (anonymous id), one row per
 * browser per day (unique visitors per day / week / month / year), and page
 * views per path per day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visitors', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_key', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10)->default('guest')->index()->comment('guest | customer | staff');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->unsignedInteger('visits')->default(1)->comment('sessions: a new one after 30 minutes away');
            $table->unsignedInteger('page_views')->default(0);
            $table->string('current_path', 255)->nullable();
            $table->string('landing_path', 255)->nullable();
            $table->string('referrer', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('country', 2)->nullable()->index();
            $table->string('region', 80)->nullable();
            $table->string('city', 80)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('timezone', 60)->nullable();
            $table->string('device', 10)->nullable()->comment('mobile | tablet | desktop');
            $table->string('browser', 40)->nullable();
            $table->string('os', 40)->nullable();
            $table->string('language', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('site_visitor_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_visitor_id')->constrained()->cascadeOnDelete();
            $table->date('day')->index();
            $table->unsignedInteger('page_views')->default(0);
            $table->timestamp('first_at');
            $table->timestamp('last_at');
            $table->unique(['site_visitor_id', 'day']);
        });

        Schema::create('site_page_stats', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('path', 191);
            $table->unsignedInteger('views')->default(0);
            $table->unique(['day', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_page_stats');
        Schema::dropIfExists('site_visitor_days');
        Schema::dropIfExists('site_visitors');
    }
};

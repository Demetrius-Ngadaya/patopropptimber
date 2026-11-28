<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The package shown as "Most popular" on the pricing page. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('patopro.platform.connection');
    }

    public function up(): void
    {
        Schema::table('platform_plans', function (Blueprint $table) {
            $table->boolean('is_recommended')->default(false)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('platform_plans', fn (Blueprint $table) => $table->dropColumn('is_recommended'));
    }
};

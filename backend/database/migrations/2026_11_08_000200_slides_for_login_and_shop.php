<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One picture library for the login page and the online shop: each picture can be
 * shown on either or both. A small copy (for phones) loads much faster.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_slides', function (Blueprint $table) {
            $table->boolean('show_on_login')->default(true)->after('is_active');
            $table->boolean('show_on_shop')->default(false)->after('show_on_login');
            $table->string('image_small_path')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('login_slides', function (Blueprint $table) {
            $table->dropColumn(['show_on_login', 'show_on_shop', 'image_small_path']);
        });
    }
};

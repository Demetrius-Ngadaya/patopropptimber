<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->default('en')->after('avatar_path')->comment('en | sw');
            $table->string('theme', 10)->default('system')->after('locale')->comment('light | dark | system');
        });

        // Pictures shown beside the login form, managed by administrators.
        Schema::create('login_slides', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('caption', 500)->nullable();
            $table->string('image_path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_slides');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['locale', 'theme']));
    }
};

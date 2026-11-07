<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - roles.is_developer: the hidden Software Engineer role (sees and switches system modules;
 *   invisible to everyone else, including super admins).
 * - user_permissions: per-user exceptions on top of the role (grant or deny one permission).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_developer')->default(false)->after('is_super_admin')->comment('Software Engineer: hidden from all other users');
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('effect', 5)->comment('grant | deny');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'permission_id'], 'user_permissions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('is_developer'));
    }
};

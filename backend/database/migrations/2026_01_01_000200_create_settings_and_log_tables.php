<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable()->comment('JSON encoded');
            $table->string('group', 40)->default('general')->index();
            $table->boolean('is_public')->default(false)->comment('Exposed to the storefront / login page');
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        });

        // Data change log: who changed which record, before/after values.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('log_type', 20)->index()->comment('data | auth | business | security');
            $table->string('event', 60)->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->foreignId('branch_id')->nullable()->index();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('description', 500)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('method', 10)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['auditable_type', 'auditable_id']);
        });

        // Request log: every API action performed by a signed-in user.
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('user_type', 20)->nullable();
            $table->foreignId('branch_id')->nullable()->index();
            $table->string('method', 10);
            $table->string('route_name')->nullable()->index();
            $table->string('action')->nullable()->comment('Human readable action');
            $table->string('path', 500);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('identifier')->comment('What was typed in the username box');
            $table->string('status', 20)->index()->comment('success | failed | locked | inactive');
            $table->string('failure_reason')->nullable();
            $table->foreignId('branch_id')->nullable();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('logged_in_at')->nullable();
            $table->timestamp('logged_out_at')->nullable();
            $table->string('logout_reason', 30)->nullable()->comment('manual | expired | revoked');
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('recipient', 30)->index();
            $table->text('message');
            $table->string('purpose', 40)->index()->comment('debt_reminder | receipt | order_status | report | manual');
            $table->string('status', 20)->index()->comment('sent | failed | disabled');
            $table->text('response')->nullable();
            $table->nullableMorphs('related');
            $table->foreignId('sent_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->unique(['type', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('login_logs');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
    }
};

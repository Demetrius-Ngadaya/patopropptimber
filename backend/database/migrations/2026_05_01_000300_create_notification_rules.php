<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Notifications the admin designs: what, to whom, how, when, and the wording. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('source', 40)->comment('what the message reports, see NotificationSources');
            $table->foreignId('branch_id')->nullable()->comment('null = all branches');
            $table->json('channels')->nullable()->comment('sms | whatsapp | email (the bell is always used)');
            $table->json('role_ids')->nullable()->comment('roles whose users get it');
            $table->boolean('send_to_role_users')->default(false)->comment("also to those users' own phone / email");
            $table->boolean('include_company_contacts')->default(true)->comment('business phone / WhatsApp / email from Settings');
            $table->text('extra_phones')->nullable();
            $table->text('extra_whatsapp')->nullable();
            $table->text('extra_emails')->nullable();
            $table->string('frequency', 12)->default('daily')->comment('hourly | daily | weekly | monthly | once');
            $table->string('time', 5)->default('08:00');
            $table->unsignedTinyInteger('weekday')->nullable()->comment('0 = Sunday');
            $table->unsignedTinyInteger('month_day')->nullable()->comment('1-28, or 0 = last day');
            $table->timestamp('run_at')->nullable()->comment('for once');
            $table->string('period', 20)->default('today');
            $table->boolean('skip_when_empty')->default(true);
            $table->string('sms_template', 640)->nullable();
            $table->text('whatsapp_template')->nullable();
            $table->string('email_subject', 200)->nullable();
            $table->text('email_template')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->json('last_result')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('app_notifications', function (Blueprint $table) {
            $table->json('role_ids')->nullable()->after('permission')->comment('only users of these roles; null = by permission');
            $table->foreignId('user_id')->nullable()->after('role_ids')->index()->comment('only this user (e.g. a delivery assigned to them)');
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', fn (Blueprint $table) => $table->dropColumn(['role_ids', 'user_id']));
        Schema::dropIfExists('notification_rules');
    }
};

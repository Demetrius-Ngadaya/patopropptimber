<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * - sms_logs becomes the log of every outgoing message: SMS, WhatsApp and email.
 * - In-app notifications (the bell) with per-user read state.
 * - A random, unguessable token per product for its QR code link.
 * - The old report recipient settings move to the shared recipient lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->string('channel', 12)->default('sms')->after('id')->index()->comment('sms | whatsapp | email');
            $table->string('subject', 200)->nullable()->after('recipient');
            $table->boolean('has_attachment')->default(false)->after('message');
        });
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->string('recipient', 150)->change(); // email addresses are longer than phone numbers
        });

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->index();
            $table->string('level', 10)->default('info')->comment('info | success | warning | danger');
            $table->string('title', 255)->comment('English text, translated when shown');
            $table->text('body')->nullable();
            $table->json('params')->nullable()->comment('placeholders for title and body');
            $table->string('url', 255)->nullable();
            $table->string('permission', 80)->nullable()->index()->comment('only users with this permission see it; null = all staff');
            $table->foreignId('branch_id')->nullable()->index()->comment('null = every branch');
            $table->string('dedupe_key', 120)->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('app_notification_reads', function (Blueprint $table) {
            $table->foreignId('app_notification_id')->constrained('app_notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            $table->primary(['app_notification_id', 'user_id'], 'app_notification_reads_pk');
            $table->index('user_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('qr_token', 24)->nullable()->unique()->after('barcode');
        });
        DB::table('products')->whereNull('qr_token')->orderBy('id')->select('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('products')->where('id', $row->id)->update(['qr_token' => Str::lower(Str::random(16))]);
            }
        });

        $moves = ['report_sms_recipients' => 'notify_sms_numbers', 'report_email_recipients' => 'notify_emails'];
        foreach ($moves as $from => $to) {
            $old = DB::table('settings')->where('key', $from)->first();
            if ($old && ! DB::table('settings')->where('key', $to)->exists()) {
                DB::table('settings')->where('key', $from)->update(['key' => $to]);
            }
        }
    }

    public function down(): void
    {
        foreach (['notify_sms_numbers' => 'report_sms_recipients', 'notify_emails' => 'report_email_recipients'] as $from => $to) {
            DB::table('settings')->where('key', $from)->update(['key' => $to]);
        }
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('qr_token'));
        Schema::dropIfExists('app_notification_reads');
        Schema::dropIfExists('app_notifications');
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropColumn(['channel', 'subject', 'has_attachment']);
        });
    }
};

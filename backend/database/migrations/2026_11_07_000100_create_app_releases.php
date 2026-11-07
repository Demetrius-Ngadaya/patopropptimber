<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versions of the PatoPro mobile app offered for download from the system itself
 * (the app is not in an app store). The newest published Android release is the
 * one behind the "Download the app" buttons and the in-app update notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 10)->default('android')->index();
            $table->string('version', 20);
            $table->unsignedInteger('build_number')->default(1)->comment('Android versionCode; higher = newer');
            $table->string('file_path')->nullable()->comment('APK on the private disk, when uploaded');
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('external_url', 500)->nullable()->comment('Or a download link elsewhere (e.g. an EAS build link)');
            $table->text('notes')->nullable()->comment("What's new, shown before downloading and in the app");
            $table->boolean('is_required')->default(false)->comment('Older apps must update before continuing');
            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('downloads')->default(0);
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('title', 150);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->comment('user | assistant');
            $table->longText('content')->comment('Text shown to the user');
            $table->json('tool_calls')->nullable()->comment('Tools the assistant used, for transparency');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamps();
        });

        // Every call to the AI provider, for budgets and cost reporting.
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature', 40)->index();
            $table->string('model', 80);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('success')->default(true);
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // Bilingual online shop: Swahili product description next to the English one.
        Schema::table('products', function (Blueprint $table) {
            $table->text('description_sw')->nullable()->after('description');
        });

        // Snapshot of computed scores so changes over time can be shown.
        Schema::create('customer_credit_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->unsignedTinyInteger('score');
            $table->string('band', 2);
            $table->decimal('suggested_limit', 15, 2)->default(0);
            $table->json('factors');
            $table->timestamp('computed_at');
            $table->index(['customer_id', 'computed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_scores');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('description_sw'));
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};

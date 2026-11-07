<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Promotions for chosen groups of customers, and which sales used them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('type', 12)->comment('percent | amount');
            $table->decimal('value', 15, 2);
            $table->string('applies_to', 12)->default('all')->comment('all | categories | products');
            $table->json('category_ids')->nullable();
            $table->json('product_ids')->nullable();
            SchemaColumns::money($table, 'min_purchase');
            SchemaColumns::money($table, 'max_discount', true)->comment('cap for percentage promotions');
            $table->string('audience', 12)->default('all')->comment('all | segments | customers');
            $table->json('segments')->nullable()->comment('vip, best, frequent, new, declining, lost, occasional');
            $table->json('customer_ids')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('uses_per_customer')->nullable()->comment('null = no limit');
            $table->boolean('is_active')->default(true);
            $table->timestamp('notified_at')->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'starts_on', 'ends_on']);
        });

        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->index();
            SchemaColumns::money($table, 'amount');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['promotion_id', 'customer_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('promotion_id')->nullable()->after('price_level')->index();
            SchemaColumns::money($table, 'promotion_discount')->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['promotion_id', 'promotion_discount']);
        });
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('promotions');
    }
};

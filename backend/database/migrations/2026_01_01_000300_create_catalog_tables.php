<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description', 500)->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('show_online')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('short_name', 20)->unique();
            $table->boolean('allow_decimal')->default(false)->comment('e.g. kg, litre');
            $table->boolean('is_active')->default(true);
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku', 60)->nullable()->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('brand')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            SchemaColumns::money($table, 'cost_price');
            SchemaColumns::money($table, 'selling_price');
            SchemaColumns::money($table, 'wholesale_price', true);
            SchemaColumns::money($table, 'min_selling_price', true);
            $table->decimal('tax_rate', 5, 2)->default(0);
            SchemaColumns::quantity($table, 'reorder_level');
            $table->boolean('track_expiry')->default(false)->comment('Pharmacy / food items with batches and expiry dates');
            $table->unsignedSmallInteger('expiry_alert_days')->nullable()->comment('Overrides the global setting');
            $table->boolean('allow_decimal')->default(false)->comment('Sell by weight / volume');
            $table->boolean('is_service')->default(false)->comment('Not stock tracked');
            $table->boolean('show_online')->default(false)->comment('Visible in the online shop');
            $table->boolean('is_active')->default(true);
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'show_online']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
    }
};

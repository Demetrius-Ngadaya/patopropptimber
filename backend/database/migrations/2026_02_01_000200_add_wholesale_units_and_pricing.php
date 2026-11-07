<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            SchemaColumns::quantity($table, 'wholesale_min_qty', true)
                ->after('wholesale_price')
                ->comment('Buying at least this many base units gets the wholesale price automatically');
        });

        // Packs / alternative selling units, e.g. "Carton (24)" = 24 base units.
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            SchemaColumns::quantity($table, 'factor')->comment('Base units contained in one of this unit');
            $table->string('barcode', 64)->nullable()->unique();
            SchemaColumns::money($table, 'retail_price');
            SchemaColumns::money($table, 'wholesale_price', true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('price_level', 12)->default('retail')->after('channel')->comment('retail | wholesale');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->string('unit_name', 80)->nullable()->after('product_name');
            SchemaColumns::quantity($table, 'unit_factor')->default(1)->after('unit_name');
            SchemaColumns::quantity($table, 'unit_quantity')->after('unit_factor')->comment('Quantity in the unit sold; quantity holds base units');
            $table->string('price_level', 12)->default('retail')->after('unit_quantity');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->string('unit_name', 80)->nullable()->after('product_unit_id');
            SchemaColumns::quantity($table, 'unit_factor')->default(1)->after('unit_name');
            SchemaColumns::quantity($table, 'unit_quantity')->after('unit_factor');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn(['unit_name', 'unit_factor', 'unit_quantity']);
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn(['unit_name', 'unit_factor', 'unit_quantity', 'price_level']);
        });
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('price_level'));
        Schema::dropIfExists('product_units');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('wholesale_min_qty'));
    }
};

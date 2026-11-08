<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch prices: the same product can be bought and sold at different prices in
 * different branches (e.g. timber costs more at a far branch). Empty = the product's
 * own price applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->decimal('cost_price', 15, 2)->nullable()->after('quantity');
            $table->decimal('selling_price', 15, 2)->nullable()->after('cost_price');
            $table->decimal('wholesale_price', 15, 2)->nullable()->after('selling_price');
            $table->decimal('min_selling_price', 15, 2)->nullable()->after('wholesale_price');
        });
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'selling_price', 'wholesale_price', 'min_selling_price']);
        });
    }
};

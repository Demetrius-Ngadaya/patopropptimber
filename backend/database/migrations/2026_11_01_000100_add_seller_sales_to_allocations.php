<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sellers record their own sales from an allocation as they happen (with a receipt). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allocation_items', function (Blueprint $table) {
            $table->decimal('quantity_recorded', 15, 3)->default(0)->after('quantity_sold')->comment('sold by the seller through the system before reconciling');
        });
        Schema::table('allocations', function (Blueprint $table) {
            $table->decimal('recorded_amount', 15, 2)->default(0)->after('expected_amount')->comment('value of the sales the seller recorded');
            $table->unsignedInteger('recorded_sales')->default(0)->after('recorded_amount');
        });
    }

    public function down(): void
    {
        Schema::table('allocation_items', fn (Blueprint $table) => $table->dropColumn('quantity_recorded'));
        Schema::table('allocations', fn (Blueprint $table) => $table->dropColumn(['recorded_amount', 'recorded_sales']));
    }
};

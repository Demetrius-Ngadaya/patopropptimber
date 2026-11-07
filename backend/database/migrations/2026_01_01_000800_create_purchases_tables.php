<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('supplier_id')->nullable()->constrained();
            $table->string('supplier_invoice_no', 60)->nullable()->index();
            $table->date('purchase_date')->index();
            SchemaColumns::money($table, 'subtotal')->comment('Sum of item costs');
            SchemaColumns::money($table, 'tax_cost');
            SchemaColumns::money($table, 'loading_cost');
            SchemaColumns::money($table, 'unloading_cost');
            SchemaColumns::money($table, 'transport_cost');
            SchemaColumns::money($table, 'other_cost');
            $table->string('cost_allocation', 20)->default('value')->comment('value | quantity');
            SchemaColumns::money($table, 'total')->comment('Subtotal + extra costs');
            SchemaColumns::money($table, 'supplier_total')->comment('What we owe the supplier (items, minus returns)');
            SchemaColumns::money($table, 'amount_paid');
            SchemaColumns::money($table, 'balance');
            SchemaColumns::money($table, 'returned_amount');
            $table->string('payment_status', 20)->default('paid')->index();
            $table->date('due_date')->nullable()->index();
            $table->string('notes', 1000)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('stock_batch_id')->nullable()->constrained();
            SchemaColumns::quantity($table, 'quantity');
            SchemaColumns::quantity($table, 'returned_quantity');
            SchemaColumns::money($table, 'unit_cost')->comment('Supplier price');
            SchemaColumns::money($table, 'extra_cost_per_unit')->comment('Share of tax / transport / handling');
            SchemaColumns::money($table, 'landed_unit_cost');
            SchemaColumns::money($table, 'line_total');
            SchemaColumns::money($table, 'selling_price', true)->comment('New selling price set at receipt');
            $table->string('batch_no', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('purchase_id')->nullable()->constrained();
            $table->foreignId('branch_id')->nullable()->constrained();
            SchemaColumns::money($table, 'amount');
            $table->string('method', 30);
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->string('notes', 500)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('purchase_id')->constrained();
            $table->foreignId('supplier_id')->nullable()->constrained();
            $table->foreignId('branch_id')->constrained();
            SchemaColumns::money($table, 'total');
            $table->string('reason', 500);
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained();
            $table->foreignId('product_id')->constrained();
            SchemaColumns::quantity($table, 'quantity');
            SchemaColumns::money($table, 'unit_cost');
            SchemaColumns::money($table, 'line_total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};

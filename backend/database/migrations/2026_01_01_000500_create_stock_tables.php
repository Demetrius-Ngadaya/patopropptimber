<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fast lookup of quantity on hand per product per branch (sum of batches).
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            SchemaColumns::quantity($table, 'quantity');
            $table->timestamps();
            $table->unique(['product_id', 'branch_id']);
        });

        // Every receipt of goods creates a batch. Sales consume batches first-expiry-first-out.
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->string('batch_no', 60)->nullable();
            $table->date('expiry_date')->nullable()->index();
            SchemaColumns::money($table, 'unit_cost');
            SchemaColumns::quantity($table, 'quantity_received');
            SchemaColumns::quantity($table, 'quantity_remaining');
            $table->string('source_type', 40)->comment('opening | purchase | transfer | return | adjustment');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['product_id', 'branch_id', 'quantity_remaining']);
        });

        // Ledger of every stock change (the stock audit trail).
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('stock_batch_id')->nullable()->constrained();
            $table->string('type', 30)->index()->comment('opening|purchase|sale|sale_return|purchase_return|transfer_out|transfer_in|transfer_cancel|allocation_out|allocation_return|adjustment_in|adjustment_out|damage|expired|order');
            $table->decimal('quantity', 15, 3)->comment('Positive = in, negative = out');
            $table->decimal('balance_after', 15, 3);
            SchemaColumns::money($table, 'unit_cost');
            $table->nullableMorphs('reference');
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['product_id', 'branch_id', 'created_at']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('stock_batch_id')->nullable()->constrained();
            $table->string('type', 20)->comment('increase | decrease | damage | expired | count');
            SchemaColumns::quantity($table, 'quantity');
            SchemaColumns::quantity($table, 'quantity_before');
            SchemaColumns::quantity($table, 'quantity_after');
            SchemaColumns::money($table, 'unit_cost');
            $table->date('expiry_date')->nullable();
            $table->string('reason', 500);
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('product_stocks');
    }
};

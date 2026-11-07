<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stock moved between branches.
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('from_branch_id')->constrained('branches');
            $table->foreignId('to_branch_id')->constrained('branches');
            $table->string('type', 20)->default('free')->comment('free | sale | credit');
            $table->string('status', 20)->default('in_transit')->index()->comment('in_transit | received | cancelled');
            SchemaColumns::money($table, 'total_cost')->comment('Cost value of goods sent');
            SchemaColumns::money($table, 'total_amount')->comment('Charged to receiving branch (sale / credit)');
            SchemaColumns::money($table, 'receiving_costs')->comment('Transport etc. paid by receiver');
            $table->string('payment_status', 20)->default('not_applicable')->comment('not_applicable | paid | unpaid');
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('receive_notes', 1000)->nullable();
            $table->foreignId('sent_by')->constrained('users');
            $table->timestamp('sent_at');
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestamp('received_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            SchemaColumns::quantity($table, 'quantity');
            SchemaColumns::quantity($table, 'received_quantity', true);
            SchemaColumns::money($table, 'unit_cost');
            SchemaColumns::money($table, 'unit_price')->comment('Charge per unit for sale / credit transfers');
            $table->json('batches')->nullable()->comment('Source batches incl. expiry dates');
            $table->timestamps();
        });

        // Stock issued to an individual seller (field agent, hawker, outside counter) to sell and reconcile later.
        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('seller_id')->constrained('users');
            $table->string('status', 20)->default('open')->index()->comment('open | reconciled | cancelled');
            SchemaColumns::money($table, 'expected_amount')->comment('Value of goods at selling price when issued');
            SchemaColumns::money($table, 'sold_amount');
            SchemaColumns::money($table, 'amount_received');
            SchemaColumns::money($table, 'shortage');
            $table->timestamp('issued_at');
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users');
            $table->foreignId('sale_id')->nullable()->constrained();
            $table->string('notes', 1000)->nullable();
            $table->string('reconcile_notes', 1000)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('allocation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            SchemaColumns::quantity($table, 'quantity_issued');
            SchemaColumns::quantity($table, 'quantity_returned');
            SchemaColumns::quantity($table, 'quantity_damaged');
            SchemaColumns::quantity($table, 'quantity_sold');
            SchemaColumns::money($table, 'unit_price');
            SchemaColumns::money($table, 'unit_cost');
            $table->json('batches')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocation_items');
        Schema::dropIfExists('allocations');
        Schema::dropIfExists('transfer_items');
        Schema::dropIfExists('transfers');
    }
};

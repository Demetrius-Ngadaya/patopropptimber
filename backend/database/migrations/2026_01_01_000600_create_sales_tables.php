<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->string('customer_name')->nullable()->comment('Walk-in customer name');
            $table->string('customer_phone', 30)->nullable();
            $table->foreignId('cashier_id')->constrained('users')->comment('Who rang up the sale');
            $table->foreignId('seller_id')->nullable()->constrained('users')->comment('Sales person, e.g. allocation seller');
            $table->string('channel', 20)->default('pos')->index()->comment('pos | online | order | allocation');
            $table->string('status', 20)->default('completed')->index()->comment('completed | cancelled');
            SchemaColumns::money($table, 'subtotal');
            SchemaColumns::money($table, 'item_discount');
            SchemaColumns::money($table, 'discount');
            SchemaColumns::money($table, 'tax');
            SchemaColumns::money($table, 'delivery_fee');
            SchemaColumns::money($table, 'total');
            SchemaColumns::money($table, 'cost_total');
            SchemaColumns::money($table, 'amount_paid');
            SchemaColumns::money($table, 'balance');
            SchemaColumns::money($table, 'returned_amount');
            SchemaColumns::money($table, 'change_given');
            $table->string('payment_status', 20)->default('paid')->index()->comment('paid | partial | unpaid');
            $table->date('due_date')->nullable()->index();
            $table->timestamp('fully_paid_at')->nullable();
            $table->timestamp('sold_at')->index();
            $table->string('notes', 1000)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->nullableMorphs('source'); // order or allocation this sale came from
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'sold_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('product_name')->comment('Snapshot at time of sale');
            SchemaColumns::quantity($table, 'quantity');
            SchemaColumns::quantity($table, 'returned_quantity');
            SchemaColumns::money($table, 'unit_price');
            SchemaColumns::money($table, 'list_price')->comment('Catalogue price when sold');
            SchemaColumns::money($table, 'discount');
            $table->decimal('tax_rate', 5, 2)->default(0);
            SchemaColumns::money($table, 'tax');
            SchemaColumns::money($table, 'line_total');
            SchemaColumns::money($table, 'unit_cost')->comment('Weighted cost of consumed batches');
            $table->json('batches')->nullable()->comment('[{batch_id, quantity, unit_cost}] consumed');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->foreignId('sale_id')->nullable()->constrained();
            $table->string('type', 20)->default('sale')->comment('sale | debt | refund');
            SchemaColumns::money($table, 'amount');
            $table->string('method', 30)->index()->comment('cash | mobile_money | bank | card | lipa_namba | cheque | credit_note');
            $table->string('provider', 40)->nullable()->comment('M-Pesa, Tigo Pesa, Airtel Money, HaloPesa, CRDB ...');
            $table->string('reference', 100)->nullable()->index()->comment('Transaction ID / receipt');
            $table->string('payer_phone', 30)->nullable();
            $table->timestamp('paid_at')->index();
            $table->string('notes', 500)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users');
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        // Carts parked at the till (unfinished sales).
        Schema::create('held_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->string('label')->nullable();
            $table->json('cart');
            SchemaColumns::money($table, 'total');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('sale_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            SchemaColumns::money($table, 'total');
            SchemaColumns::money($table, 'refund_amount')->comment('Cash given back');
            SchemaColumns::money($table, 'credit_amount')->comment('Deducted from customer debt');
            $table->string('refund_method', 30)->nullable();
            $table->string('reason', 500);
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained();
            $table->foreignId('product_id')->constrained();
            SchemaColumns::quantity($table, 'quantity');
            SchemaColumns::money($table, 'unit_price');
            SchemaColumns::money($table, 'line_total');
            $table->string('condition', 20)->comment('restock | damaged');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('held_sales');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};

<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Online shop orders and orders taken by staff on behalf of customers.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('branch_id')->constrained()->comment('Fulfilling branch');
            $table->foreignId('customer_id')->constrained();
            $table->string('source', 20)->default('online')->comment('online | staff');
            $table->string('status', 30)->default('pending')->index()
                ->comment('pending | confirmed | processing | ready | out_for_delivery | completed | cancelled');
            $table->string('delivery_method', 20)->default('pickup')->comment('pickup | delivery');
            $table->string('delivery_address')->nullable();
            $table->string('contact_phone', 30);
            $table->string('payment_method', 30)->default('cash_on_delivery')->comment('No online payment yet');
            SchemaColumns::money($table, 'subtotal');
            SchemaColumns::money($table, 'delivery_fee');
            SchemaColumns::money($table, 'discount');
            SchemaColumns::money($table, 'total');
            $table->date('preferred_date')->nullable();
            $table->string('customer_notes', 1000)->nullable();
            $table->string('staff_notes', 1000)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->foreignId('sale_id')->nullable()->constrained();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('product_name');
            SchemaColumns::quantity($table, 'quantity');
            SchemaColumns::money($table, 'unit_price');
            SchemaColumns::money($table, 'line_total');
            $table->timestamps();
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};

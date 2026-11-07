<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deliveries: who takes which order or sale where, the fee by distance,
 * the courier's live position, and the chat between courier and customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('delivery_latitude', 10, 7)->nullable()->after('delivery_address');
            $table->decimal('delivery_longitude', 10, 7)->nullable()->after('delivery_latitude');
            $table->decimal('delivery_distance_km', 8, 2)->nullable()->after('delivery_longitude');
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('order_id')->nullable()->index();
            $table->foreignId('sale_id')->nullable()->index();
            $table->foreignId('customer_id')->nullable()->index();
            $table->string('customer_name', 150);
            $table->string('contact_phone', 30)->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();
            SchemaColumns::money($table, 'fee');
            SchemaColumns::money($table, 'discount');
            $table->string('discount_reason', 255)->nullable();
            $table->foreignId('discount_by')->nullable();
            $table->string('status', 20)->default('assigned')->index()->comment('assigned | on_the_way | delivered | received | failed | cancelled');
            $table->foreignId('assigned_to')->nullable()->index()->comment('the delivery person');
            $table->foreignId('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('failed_reason', 255)->nullable();
            $table->string('note', 500)->nullable();
            $table->decimal('courier_latitude', 10, 7)->nullable();
            $table->decimal('courier_longitude', 10, 7)->nullable();
            $table->unsignedInteger('courier_accuracy')->nullable()->comment('metres');
            $table->timestamp('courier_located_at')->nullable();
            $table->json('trail')->nullable()->comment('recent positions [[lat, lng, time], ...]');
            $table->string('public_token', 40)->unique()->comment('the customer tracking link');
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });

        Schema::create('delivery_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->string('sender', 10)->comment('customer | staff');
            $table->foreignId('user_id')->nullable();
            $table->string('body', 1000);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['delivery_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_messages');
        Schema::dropIfExists('deliveries');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['delivery_latitude', 'delivery_longitude', 'delivery_distance_km']));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude']));
    }
};

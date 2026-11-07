<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online payments by mobile money (Snippe): a USSD push to the customer's
 * phone, taken at the till or for an online-shop order. A payment is only
 * used for a sale once Snippe confirms it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('gateway', 20)->default('snippe');
            $table->string('purpose', 10)->comment('pos | order');
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->foreignId('sale_id')->nullable()->constrained()->comment('sale the money was used for');
            $table->foreignId('customer_id')->nullable()->constrained();
            SchemaColumns::money($table, 'amount');
            $table->string('phone', 20);
            $table->string('payer_name', 150)->nullable();
            $table->string('status', 15)->default('pending')->comment('pending | completed | failed | expired | voided | cancelled');
            $table->string('reference', 80)->nullable()->unique()->comment("the gateway's payment reference");
            $table->string('idempotency_key', 30)->unique();
            $table->string('external_reference', 80)->nullable()->comment("the network's transaction ID");
            $table->string('provider', 30)->nullable()->comment('mpesa, airtel, mixx, halotel ...');
            SchemaColumns::money($table, 'fee');
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->foreignId('requested_by')->nullable()->comment('staff user; empty when the customer paid from the shop');
            $table->timestamps();

            $table->index(['status', 'purpose']);
            $table->index(['branch_id', 'created_at']);
        });

        // Every webhook received, verified or not: duplicates are ignored by event ID.
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20)->default('snippe');
            $table->string('event_id', 80)->nullable()->unique();
            $table->string('type', 40)->nullable();
            $table->string('reference', 80)->nullable()->index();
            $table->boolean('verified')->default(false);
            $table->string('result', 255)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            SchemaColumns::money($table, 'paid_online');
            $table->timestamp('paid_online_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['paid_online', 'paid_online_at']);
        });
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('online_payments');
    }
};

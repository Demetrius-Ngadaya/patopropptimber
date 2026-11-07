<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase planning (orders to suppliers, received in one or more
 * deliveries) and payment reminders for purchases bought on credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_plans', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('supplier_id')->nullable()->constrained();
            $table->string('title', 150)->nullable();
            $table->string('status', 20)->default('draft')->comment('draft | pending | partially_received | received | cancelled | closed');
            $table->date('expected_date')->nullable();
            $table->string('payment_type', 10)->default('cash')->comment('cash | credit');
            $table->unsignedSmallInteger('credit_days')->nullable()->comment('days to pay after each delivery');
            $table->unsignedSmallInteger('reminder_days_before')->nullable();
            $table->json('reminder_channels')->nullable();
            SchemaColumns::money($table, 'estimated_total');
            SchemaColumns::money($table, 'received_total');
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('purchase_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            SchemaColumns::quantity($table, 'quantity');
            SchemaColumns::quantity($table, 'received_quantity')->default(0);
            SchemaColumns::money($table, 'unit_cost')->comment('expected cost per base unit');
            SchemaColumns::money($table, 'line_total');
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_plan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_plan_id')->constrained()->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('purchase_plan_id')->nullable()->after('supplier_id')->constrained();
            $table->date('remind_on')->nullable()->after('due_date')->comment('day to remind about paying');
            $table->json('reminder_channels')->nullable()->after('remind_on');
            $table->date('reminded_on')->nullable()->after('reminder_channels')->comment('last reminder sent');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_plan_id');
            $table->dropColumn(['remind_on', 'reminder_channels', 'reminded_on']);
        });
        Schema::dropIfExists('purchase_plan_events');
        Schema::dropIfExists('purchase_plan_items');
        Schema::dropIfExists('purchase_plans');
    }
};

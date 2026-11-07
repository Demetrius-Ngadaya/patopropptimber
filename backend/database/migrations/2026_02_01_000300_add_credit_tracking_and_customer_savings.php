<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->boolean('is_credit')->default(false)->after('payment_status')->index();
            SchemaColumns::money($table, 'credit_amount')->after('is_credit')->comment('Amount owed when the sale was made');
        });

        // Customer savings / deposits ("akiba").
        Schema::create('savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_no', 30)->unique();
            $table->foreignId('customer_id')->unique()->constrained();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete()->comment('Opened at');
            SchemaColumns::money($table, 'balance');
            SchemaColumns::money($table, 'total_deposited');
            SchemaColumns::money($table, 'total_spent');
            SchemaColumns::money($table, 'total_withdrawn');
            $table->string('status', 12)->default('active')->comment('active | frozen | closed');
            $table->timestamp('last_transaction_at')->nullable();
            $table->string('notes', 1000)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('savings_account_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16)->index()->comment('deposit | purchase | withdrawal | refund | debt_payment | reversal');
            SchemaColumns::money($table, 'amount')->comment('Always positive; type decides the direction');
            SchemaColumns::money($table, 'balance_before');
            SchemaColumns::money($table, 'balance_after');
            SchemaColumns::money($table, 'purchase_amount', true)->comment('Total of the purchase this deduction paid for');
            SchemaColumns::money($table, 'debt_amount', true)->comment('Part of the purchase left as debt');
            $table->foreignId('sale_id')->nullable()->constrained();
            $table->string('method', 30)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('transacted_at')->index();
            $table->foreignId('reversed_transaction_id')->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            SchemaColumns::money($table, 'balance_before', true)->after('amount')->comment('Sale balance before a debt payment');
            SchemaColumns::money($table, 'balance_after', true)->after('balance_before');
            $table->foreignId('savings_transaction_id')->nullable()->after('sale_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('savings_transaction_id');
            $table->dropColumn(['balance_before', 'balance_after']);
        });
        Schema::dropIfExists('savings_transactions');
        Schema::dropIfExists('savings_accounts');
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn(['is_credit', 'credit_amount']));
    }
};

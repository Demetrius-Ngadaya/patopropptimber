<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fixed asset register with straight-line depreciation.
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('name', 150);
            $table->string('category', 60)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('location', 150)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_account_id')->constrained('accounts');
            $table->foreignId('depreciation_account_id')->constrained('accounts')->comment('Expense account');
            $table->foreignId('accumulated_account_id')->constrained('accounts')->comment('Contra-asset account');
            $table->date('acquired_on');
            SchemaColumns::money($table, 'cost');
            SchemaColumns::money($table, 'salvage_value');
            $table->unsignedSmallInteger('useful_life_months');
            SchemaColumns::money($table, 'accumulated_depreciation');
            $table->date('depreciated_to')->nullable()->comment('Last month-end depreciated');
            $table->string('status', 12)->default('active')->comment('active | disposed | fully_depreciated');
            $table->date('disposed_on')->nullable();
            SchemaColumns::money($table, 'disposal_amount', true);
            $table->string('notes', 500)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained();
            $table->string('period', 7)->comment('YYYY-MM');
            SchemaColumns::money($table, 'amount');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['fixed_asset_id', 'period']);
        });

        // Bank / mobile money reconciliation against the provider statement.
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->date('statement_date');
            SchemaColumns::money($table, 'statement_balance');
            SchemaColumns::money($table, 'book_balance');
            SchemaColumns::money($table, 'cleared_balance');
            SchemaColumns::money($table, 'difference');
            $table->string('status', 12)->default('draft')->comment('draft | completed');
            $table->timestamp('completed_at')->nullable();
            $table->string('notes', 500)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignId('reconciliation_id')->nullable()->constrained('bank_reconciliations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('reconciliation_id'));
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('asset_depreciations');
        Schema::dropIfExists('fixed_assets');
    }
};

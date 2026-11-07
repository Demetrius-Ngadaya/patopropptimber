<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chart of accounts.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('type', 12)->index()->comment('asset | liability | equity | income | expense');
            $table->string('sub_type', 30)->nullable()->comment('cash, bank, mobile_money, receivable, inventory, payable, cost_of_sales, fixed_asset ...');
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('system_key', 40)->nullable()->unique()->comment('Used by automatic postings; cannot be deleted');
            $table->boolean('is_header')->default(false)->comment('Grouping only; no postings');
            $table->boolean('allow_manual')->default(true)->comment('Can be used in manual journals');
            $table->boolean('is_active')->default(true);
            $table->string('description', 500)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number', 60)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 10)->default('open')->comment('open | closed');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable();
            $table->unsignedBigInteger('closing_entry_id')->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        // Journals are immutable: corrections are made with a reversing entry.
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('entry_date')->index();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 500);
            $table->string('reference', 100)->nullable();
            $table->string('event', 40)->nullable()->index()->comment('sale, purchase, expense, payroll, manual ...');
            $table->nullableMorphs('source');
            $table->boolean('is_manual')->default(false);
            $table->string('status', 10)->default('posted')->index()->comment('posted | reversed');
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            SchemaColumns::money($table, 'total');
            SchemaColumns::userstamps($table);
            $table->timestamps();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('entry_date');
            SchemaColumns::money($table, 'debit');
            SchemaColumns::money($table, 'credit');
            $table->string('memo', 255)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->index(['account_id', 'entry_date']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('month')->comment('1-12');
            SchemaColumns::money($table, 'amount');
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'account_id', 'branch_id', 'month'], 'budgets_unique_period');
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('description')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', fn (Blueprint $table) => $table->dropConstrainedForeignId('account_id'));
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('fiscal_years');
        Schema::dropIfExists('accounts');
    }
};

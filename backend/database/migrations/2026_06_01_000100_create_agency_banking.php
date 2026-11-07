<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uwakala (mobile money and bank agency): the float accounts the business
 * runs, the morning opening and evening closing of each day (float per
 * account plus cash in hand), and the commissions the providers pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->comment('null = every branch');
            $table->string('name', 80)->comment('e.g. M-Pesa, Mixx by Yas, CRDB');
            $table->string('provider', 30)->comment('mpesa | mixx | airtel | halopesa | tpesa | azampesa | nmb | crdb | nbc | other');
            $table->string('kind', 20)->default('mobile_money')->comment('mobile_money | bank | other');
            $table->string('agent_number', 60)->nullable()->comment('agent / till / account number');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('notes', 255)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('agency_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->date('business_date');
            $table->string('status', 10)->default('open')->comment('open | closed');
            SchemaColumns::money($table, 'opening_cash');
            SchemaColumns::money($table, 'closing_cash', true);
            SchemaColumns::money($table, 'capital_added')->comment('owner money put in during the day');
            SchemaColumns::money($table, 'capital_withdrawn')->comment('money taken out during the day');
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable();
            $table->string('opening_note', 500)->nullable();
            $table->string('closing_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'business_date']);
        });

        Schema::create('agency_day_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_account_id')->constrained();
            SchemaColumns::money($table, 'opening_float');
            SchemaColumns::money($table, 'closing_float', true);
            $table->timestamps();
            $table->unique(['agency_day_id', 'agency_account_id']);
        });

        Schema::create('agency_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('agency_account_id')->constrained();
            $table->char('period', 7)->comment('month earned, YYYY-MM');
            SchemaColumns::money($table, 'amount');
            $table->date('received_on');
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['period', 'agency_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_commissions');
        Schema::dropIfExists('agency_day_balances');
        Schema::dropIfExists('agency_days');
        Schema::dropIfExists('agency_accounts');
    }
};

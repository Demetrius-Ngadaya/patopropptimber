<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete()
                ->comment('Online account, if the customer can log in');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete()->comment('Registered at');
            $table->string('name');
            $table->string('phone', 30)->nullable()->index();
            $table->string('alt_phone', 30)->nullable();
            $table->string('email')->nullable()->index();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('tin', 30)->nullable();
            $table->string('customer_type', 20)->default('retail')->comment('retail | wholesale | institution');
            SchemaColumns::money($table, 'credit_limit')->comment('0 = no limit');
            SchemaColumns::money($table, 'balance')->comment('Amount the customer owes us');
            $table->boolean('allow_credit')->default(false);
            $table->boolean('sms_opt_in')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 1000)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
            $table->index('name');
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 30)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('tin', 30)->nullable();
            SchemaColumns::money($table, 'balance')->comment('Amount we owe the supplier');
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 1000)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
    }
};

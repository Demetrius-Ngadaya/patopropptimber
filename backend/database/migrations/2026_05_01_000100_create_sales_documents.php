<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotations, proforma invoices and invoices share one table: they have the
 * same shape and convert into each other. An issued invoice (or a paid
 * proforma) is backed by a real Sale, which owns stock, debt and postings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('vrn', 40)->nullable()->after('tin')->comment('VAT registration number');
        });

        Schema::create('sales_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 12)->index()->comment('quotation | proforma | invoice');
            $table->string('number', 30)->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            // Snapshot of who the document is addressed to, as printed.
            $table->string('customer_name', 150);
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_email', 120)->nullable();
            $table->string('customer_address', 255)->nullable();
            $table->string('customer_tin', 40)->nullable();
            $table->string('customer_vrn', 40)->nullable();
            $table->string('status', 20)->index()->comment('stored lifecycle; see SalesDocument for the full list');
            $table->string('price_level', 20)->default('retail');
            $table->date('issue_date');
            $table->date('due_date')->nullable()->index()->comment('invoice: due date; quotation / proforma: valid until');
            SchemaColumns::money($table, 'subtotal');
            SchemaColumns::money($table, 'item_discount');
            SchemaColumns::money($table, 'discount');
            SchemaColumns::money($table, 'tax');
            SchemaColumns::money($table, 'total');
            $table->boolean('prices_include_tax')->default(true);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->foreignId('sale_id')->nullable()->index()->comment('the sale behind an issued invoice or a paid proforma');
            $table->foreignId('converted_from_id')->nullable()->index();
            $table->foreignId('converted_to_id')->nullable()->index();
            $table->string('public_token', 40)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['type', 'status']);
            $table->index(['branch_id', 'issue_date']);
        });

        Schema::create('sales_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_unit_id')->nullable();
            $table->string('description', 255)->comment('product name or own wording, as printed');
            $table->string('unit_name', 80)->nullable();
            SchemaColumns::quantity($table, 'unit_factor')->default(1);
            SchemaColumns::quantity($table, 'quantity');
            SchemaColumns::money($table, 'unit_price');
            SchemaColumns::money($table, 'discount');
            $table->decimal('tax_rate', 5, 2)->default(0);
            SchemaColumns::money($table, 'tax');
            SchemaColumns::money($table, 'line_total');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('sales_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40)->comment('created | updated | sent | viewed | accepted | declined | issued | payment | paid | converted | closed | cancelled | reopened');
            $table->string('note', 500)->nullable();
            $table->json('data')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['sales_document_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_events');
        Schema::dropIfExists('sales_document_items');
        Schema::dropIfExists('sales_documents');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('vrn'));
    }
};

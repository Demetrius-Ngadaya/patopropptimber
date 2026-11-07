<?php

use App\Support\SchemaColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Human resources: organisation, employees, documents, attendance, leave,
 * salary components, payroll and salary advances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 20)->nullable()->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('manager_id')->nullable()->comment('employees.id');
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('grade', 20)->nullable();
            SchemaColumns::money($table, 'min_salary', true);
            SchemaColumns::money($table, 'max_salary', true);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_no', 30)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete()->comment('Login for self-service');
            $table->string('first_name', 60);
            $table->string('middle_name', 60)->nullable();
            $table->string('last_name', 60);
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('marital_status', 20)->nullable();
            $table->text('national_id')->nullable()->comment('Encrypted');
            $table->string('phone', 30)->nullable()->index();
            $table->string('email', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->string('emergency_contact_relation', 40)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('employment_type', 20)->default('permanent')->comment('permanent | contract | casual | intern');
            $table->date('hire_date');
            $table->date('probation_end_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->string('status', 20)->default('active')->index()->comment('active | on_leave | suspended | terminated | resigned');
            $table->date('exit_date')->nullable();
            $table->string('exit_reason', 500)->nullable();
            SchemaColumns::money($table, 'basic_salary');
            $table->string('payment_method', 20)->default('bank')->comment('bank | mobile_money | cash');
            $table->string('bank_name', 100)->nullable();
            $table->text('bank_account_number')->nullable()->comment('Encrypted');
            $table->string('mobile_money_number', 30)->nullable();
            $table->text('tin')->nullable()->comment('Encrypted');
            $table->string('nssf_number', 40)->nullable();
            $table->string('nhif_number', 40)->nullable();
            $table->boolean('paye_exempt')->default(false);
            $table->boolean('nssf_exempt')->default(false);
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->string('title', 150);
            $table->string('type', 30)->default('other')->comment('contract | id | certificate | cv | letter | other');
            $table->string('file_path');
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->date('expires_on')->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->dateTime('clock_in')->nullable();
            $table->dateTime('clock_out')->nullable();
            $table->string('status', 20)->default('present')->comment('present | late | absent | half_day | on_leave | holiday | off');
            $table->decimal('hours_worked', 5, 2)->default(0);
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->string('source', 10)->default('manual')->comment('manual | self | device');
            $table->string('clock_in_ip', 45)->nullable();
            $table->string('notes', 500)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
            $table->index(['date', 'status']);
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('code', 20)->unique();
            $table->decimal('days_per_year', 5, 1)->default(0);
            $table->boolean('is_paid')->default(true);
            $table->decimal('max_carry_forward', 5, 1)->default(0);
            $table->boolean('requires_attachment')->default(false);
            $table->string('gender', 10)->nullable()->comment('Only for this gender (e.g. maternity)');
            $table->boolean('is_active')->default(true);
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('leave_type_id')->constrained();
            $table->unsignedSmallInteger('year');
            $table->decimal('entitled', 5, 1)->default(0);
            $table->decimal('carried_forward', 5, 1)->default(0);
            $table->decimal('adjustment', 5, 1)->default(0);
            $table->decimal('taken', 5, 1)->default(0);
            $table->timestamps();
            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('leave_type_id')->constrained();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 5, 1);
            $table->string('reason', 1000)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('status', 12)->default('pending')->index()->comment('pending | approved | rejected | cancelled');
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->date('date');
            $table->boolean('recurring')->default(false)->comment('Same day every year');
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('code', 20)->unique();
            $table->string('type', 12)->comment('earning | deduction');
            $table->string('calculation', 12)->default('fixed')->comment('fixed | percent (of basic)');
            $table->decimal('amount', 15, 2)->default(0)->comment('Default amount or percent');
            $table->boolean('is_taxable')->default(true)->comment('Earnings: counted for PAYE');
            $table->boolean('is_pensionable')->default(true)->comment('Earnings: counted for NSSF');
            $table->boolean('applies_to_all')->default(false);
            $table->boolean('is_active')->default(true);
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('employee_salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('salary_component_id')->constrained();
            $table->decimal('amount', 15, 2)->nullable()->comment('Overrides the component default');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'salary_component_id'], 'emp_salary_components_unique'); // default name exceeds MySQL's 64 characters
        });

        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('employee_id')->constrained();
            SchemaColumns::money($table, 'amount');
            $table->unsignedTinyInteger('installments')->default(1);
            SchemaColumns::money($table, 'monthly_deduction');
            SchemaColumns::money($table, 'recovered');
            $table->string('reason', 500)->nullable();
            $table->string('status', 12)->default('pending')->index()->comment('pending | approved | rejected | paid | recovered');
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference', 100)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('period', 7)->comment('YYYY-MM');
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12)->default('draft')->index()->comment('draft | approved | paid | cancelled');
            $table->unsignedInteger('employees')->default(0);
            foreach (['gross', 'paye', 'nssf_employee', 'nssf_employer', 'sdl', 'wcf', 'nhif_employee', 'nhif_employer', 'other_deductions', 'advance_recovery', 'net', 'employer_cost'] as $column) {
                SchemaColumns::money($table, $column);
            }
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('notes', 500)->nullable();
            SchemaColumns::userstamps($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained();
            $table->foreignId('employee_id')->constrained();
            $table->string('employee_name', 190);
            $table->string('position', 100)->nullable();
            $table->string('department', 100)->nullable();
            SchemaColumns::money($table, 'basic_salary');
            $table->decimal('days_payable', 5, 1)->default(0);
            $table->decimal('unpaid_days', 5, 1)->default(0);
            SchemaColumns::money($table, 'unpaid_deduction');
            $table->json('earnings')->nullable();
            $table->json('deductions')->nullable();
            foreach (['gross', 'taxable_income', 'pensionable', 'paye', 'nssf_employee', 'nssf_employer', 'sdl', 'wcf', 'nhif_employee', 'nhif_employer', 'other_deductions', 'advance_recovery', 'net'] as $column) {
                SchemaColumns::money($table, $column);
            }
            $table->string('payment_method', 20)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('account_hint', 30)->nullable()->comment('Last digits only');
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        foreach (['payslips', 'payroll_runs', 'salary_advances', 'employee_salary_components', 'salary_components', 'holidays', 'leave_requests', 'leave_balances', 'leave_types', 'attendances', 'employee_documents', 'employees', 'positions', 'departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

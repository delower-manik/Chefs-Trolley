<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People & Finance domain — architecture §5 "People & Finance". `employees`
 * is deliberately a separate roster from `users`: not every staff member
 * needs a login (a kitchen helper or cleaner has no reason to touch the
 * admin console), so an employee optionally *links to* a user account
 * rather than every employee requiring one -- same "don't duplicate an
 * existing module, extend it" reasoning as Delivery not re-deriving the
 * Order lifecycle in Phase 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('user_id')->nullable(); // set only if this employee also has a login
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('email')->nullable();
            $table->string('role_title'); // free text, e.g. "Head Chef", "Waiter" -- not the same as an RBAC Role
            $table->enum('employment_type', ['full_time', 'part_time', 'contract'])->default('full_time');
            $table->decimal('monthly_salary', 12, 2)->default(0);
            $table->date('hire_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->uuid('branch_id');
            $table->date('date');
            $table->dateTime('check_in_time')->nullable();
            $table->dateTime('check_out_time')->nullable();
            $table->enum('status', ['present', 'absent', 'on_leave', 'half_day'])->default('present');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['employee_id', 'date']); // one record per employee per day
        });

        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->date('period_start');
            $table->date('period_end');
            // draft = entries can be (re)generated; finalized = entries locked,
            // ready to pay; paid = payment recorded, an Expense was created.
            $table->enum('status', ['draft', 'finalized', 'paid'])->default('draft');
            $table->uuid('created_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['branch_id', 'period_start', 'period_end']);
        });

        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payroll_period_id');
            $table->uuid('employee_id');
            $table->decimal('base_salary', 12, 2);
            $table->unsignedInteger('absent_days')->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('bonuses', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2);
            $table->timestamps();

            $table->foreign('payroll_period_id')->references('id')->on('payroll_periods')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $table->unique(['payroll_period_id', 'employee_id']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->nullable();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('category_id');
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            $table->date('expense_date');
            $table->uuid('created_by')->nullable();
            $table->string('reference_type')->nullable(); // e.g. App\Models\PayrollPeriod, when auto-created
            $table->uuid('reference_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('expense_categories')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('payroll_entries');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('employees');
    }
};

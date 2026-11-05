<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('opened_by');
            $table->decimal('opening_float', 12, 2)->default(0);
            $table->timestamp('opened_at');
            $table->uuid('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();
            $table->string('notes')->nullable();
            $table->string('status', 10)->default('open'); // open | closed
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('opened_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['branch_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shift_id');
            $table->uuid('branch_id');
            $table->string('type', 3); // in | out
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('shift_id')->references('id')->on('cash_shifts')->cascadeOnDelete();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('branch_id');
            $table->uuid('shift_id')->nullable();
            $table->string('kind', 8)->default('payment'); // payment | refund
            $table->string('method', 20);
            $table->string('reference')->nullable();
            $table->decimal('amount', 12, 2);           // applied to the order (excludes tip)
            $table->decimal('tip', 12, 2)->default(0);
            $table->decimal('tendered', 12, 2)->nullable(); // cash handed over
            $table->decimal('change_given', 12, 2)->nullable();
            $table->uuid('refunds_payment_id')->nullable(); // set on refunds
            $table->string('reason')->nullable();
            $table->boolean('restocked')->default(false);
            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->string('void_reason')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('shift_id')->references('id')->on('cash_shifts')->nullOnDelete();
            $table->index(['order_id', 'kind']);
            $table->index(['shift_id']);
        });

        Schema::create('printers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('name');
            $table->string('connection', 10); // network | usb | browser
            $table->string('role', 10)->default('receipt'); // receipt | kitchen
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->default(9100);
            $table->uuid('kitchen_station_id')->nullable();
            $table->unsignedSmallInteger('paper_width')->default(80); // mm: 58 | 80
            $table->boolean('opens_drawer')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('kitchen_station_id')->references('id')->on('kitchen_stations')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('manual_discount_total', 12, 2)->default(0); // part of discount_total
            $table->string('discount_reason')->nullable();
            $table->uuid('discount_by')->nullable();
            $table->timestamp('stock_restocked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['manual_discount_total', 'discount_reason', 'discount_by', 'stock_restocked_at']);
        });
        Schema::dropIfExists('printers');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_shifts');
    }
};

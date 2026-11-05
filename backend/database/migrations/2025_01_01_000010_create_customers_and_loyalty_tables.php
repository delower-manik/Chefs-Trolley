<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers & Growth domain — architecture §5 "Customers & Growth".
 * Loyalty points follow the same ledger discipline as Inventory stock
 * (Skill: Inventory Engineer's rule generalizes well here too): a
 * customer's `loyalty_points` is a cached balance, but the only thing
 * allowed to write to it is LoyaltyLedgerEntry::record(), which row-locks
 * the customer before mutating -- so it can never drift from the ledger
 * even under concurrent orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->nullable(); // null = shared across branches (a chain-wide customer)
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->integer('loyalty_points')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });

        // orders.customer_id predates the Customers table (added in Phase 1
        // before Customers existed) -- add the FK now that the referenced
        // table exists, so an order can never point at a customer that was
        // never created.
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
        });

        Schema::create('loyalty_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->enum('type', ['earned', 'redeemed', 'adjusted']);
            $table->integer('points_change'); // signed: +earned/adjusted-up, -redeemed/adjusted-down
            $table->integer('balance_after');
            $table->string('reason')->nullable();
            $table->string('reference_type')->nullable(); // e.g. App\Models\Order
            $table->uuid('reference_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->timestamp('created_at');

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_ledger_entries');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });
        Schema::dropIfExists('customers');
    }
};

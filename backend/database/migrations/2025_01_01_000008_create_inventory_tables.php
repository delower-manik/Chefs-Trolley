<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory domain — architecture §5 "Inventory" and §10 "Inventory & Recipe
 * Engine". Stock is modeled as an append-only ledger (Skill: Inventory
 * Engineer -- "treat stock as a ledger, not merely a mutable number"),
 * tracked per warehouse via `ingredient_stocks`, with each
 * `stock_ledger_entries` row recording the balance at that warehouse right
 * after the movement. An ingredient's total stock is the sum of its
 * `ingredient_stocks` rows across all of a branch's warehouses -- there is
 * no separate cached total on the ingredient itself, so it can never drift
 * out of sync with the ledger.
 *
 * This schema has not been deployed anywhere yet, so warehouse support was
 * folded into this migration directly rather than shipped as a fragile
 * enum-altering follow-up migration (see README "Gaps closed").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('abbreviation')->unique();
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->nullable();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('unit_id');
            $table->string('name');
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->decimal('cost_per_unit', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('unit_id')->references('id')->on('units')->restrictOnDelete();
            $table->unique(['branch_id', 'name']);
            $table->index(['branch_id', 'is_active']);
        });

        // Per-warehouse running balance -- the thing StockLedgerEntry::record()
        // actually locks and mutates. An ingredient's total stock is the sum
        // of its rows here.
        Schema::create('ingredient_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ingredient_id');
            $table->uuid('warehouse_id');
            $table->decimal('quantity', 14, 3)->default(0);
            $table->timestamps();

            $table->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->unique(['ingredient_id', 'warehouse_id']);
        });

        Schema::create('stock_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ingredient_id');
            $table->uuid('warehouse_id');
            $table->uuid('branch_id');
            $table->enum('type', [
                'purchase', 'adjustment', 'waste', 'sale_consumption', 'correction',
                'transfer_in', 'transfer_out',
            ]);
            $table->decimal('quantity_change', 14, 3); // signed: +in, -out
            $table->decimal('balance_after', 14, 3); // balance at this warehouse after the move
            $table->string('reason')->nullable();
            $table->string('reference_type')->nullable(); // e.g. App\Models\Purchase, App\Models\Order
            $table->uuid('reference_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->timestamp('created_at');

            $table->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['ingredient_id', 'created_at']);
            $table->index(['warehouse_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('supplier_id');
            $table->uuid('warehouse_id'); // where this purchase will land / has landed
            // draft = PO placed, stock not yet received; received = stock landed;
            // cancelled = PO abandoned before receiving (see README "Gaps closed").
            $table->enum('status', ['draft', 'received', 'cancelled'])->default('draft');
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->timestamp('received_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_id');
            $table->uuid('ingredient_id');
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('total_cost', 14, 2);
            $table->timestamps();

            $table->foreign('purchase_id')->references('id')->on('purchases')->cascadeOnDelete();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->restrictOnDelete();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('from_warehouse_id');
            $table->uuid('to_warehouse_id');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('from_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('to_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('stock_transfer_id');
            $table->uuid('ingredient_id');
            $table->decimal('quantity', 14, 3);
            $table->timestamps();

            $table->foreign('stock_transfer_id')->references('id')->on('stock_transfers')->cascadeOnDelete();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('stock_ledger_entries');
        Schema::dropIfExists('ingredient_stocks');
        Schema::dropIfExists('ingredients');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('units');
    }
};

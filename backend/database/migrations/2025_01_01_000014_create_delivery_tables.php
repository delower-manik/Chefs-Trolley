<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery domain — architecture §5 "Delivery". The order lifecycle
 * (draft..pending..assigned..picked_up..delivered..completed/cancelled)
 * already lives on `orders.status` since Phase 1 -- this migration does
 * NOT duplicate that state machine. `delivery_assignments` only tracks
 * what's genuinely delivery-specific: which rider, which zone, the
 * timestamps for each handoff, and a delivery-only `failed` outcome that
 * the general Order lifecycle has no equivalent for (Non-Negotiable Rule
 * #2 -- don't duplicate a module that already exists elsewhere).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('name');
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('riders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('name');
            $table->string('phone')->unique();
            $table->enum('vehicle_type', ['bicycle', 'motorbike', 'car', 'on_foot'])->default('motorbike');
            // available = free for a new assignment, busy = currently out on
            // a delivery, offline = not working right now.
            $table->enum('status', ['available', 'busy', 'offline'])->default('offline');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });

        Schema::create('delivery_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->unique(); // one assignment per order
            $table->uuid('branch_id');
            $table->uuid('rider_id')->nullable();
            $table->uuid('delivery_zone_id')->nullable();
            $table->string('delivery_address')->nullable();
            $table->enum('status', ['pending', 'assigned', 'picked_up', 'delivered', 'failed'])->default('pending');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('recipient_name')->nullable();
            $table->text('proof_note')->nullable(); // e.g. "handed to customer", "left at gate"
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('rider_id')->references('id')->on('riders')->nullOnDelete();
            $table->foreign('delivery_zone_id')->references('id')->on('delivery_zones')->nullOnDelete();
            $table->index(['branch_id', 'status']);
            $table->index('rider_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_assignments');
        Schema::dropIfExists('riders');
        Schema::dropIfExists('delivery_zones');
    }
};

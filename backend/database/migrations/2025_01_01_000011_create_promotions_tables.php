<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions double as "Coupons" from architecture §5 -- a coupon is just
 * a code-activated promotion, so one model covers both rather than
 * duplicating near-identical entities. Redemptions are append-only (same
 * ledger discipline as everything else touching money or stock) so usage
 * limits can be enforced by counting rows, not by trusting a mutable
 * counter alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->nullable(); // null = valid at every branch
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('type', ['percentage', 'fixed']);
            $table->decimal('value', 12, 2); // percentage points (0-100) or a flat BDT amount
            $table->decimal('min_order_amount', 12, 2)->default(0);
            $table->decimal('max_discount_amount', 12, 2)->nullable(); // caps a percentage discount
            $table->unsignedInteger('usage_limit')->nullable(); // total redemptions allowed, null = unlimited
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });

        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('promotion_id');
            $table->uuid('order_id');
            $table->uuid('customer_id')->nullable();
            $table->decimal('discount_amount', 12, 2);
            $table->timestamp('created_at');

            $table->foreign('promotion_id')->references('id')->on('promotions')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->index(['promotion_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('promotions');
    }
};

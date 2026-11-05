<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique(); // e.g. CT-1048
            $table->enum('source', ['dine_in', 'takeaway', 'delivery', 'scheduled', 'pos', 'app_web']);
            $table->uuid('branch_id');
            $table->uuid('customer_id')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->enum('payment_state', ['unpaid', 'authorized', 'paid', 'partially_refunded', 'refunded', 'failed'])
                ->default('unpaid');
            $table->enum('kitchen_state', ['new', 'accepted', 'preparing', 'ready', 'served', 'completed'])
                ->default('new');
            $table->enum('status', [
                'draft', 'pending', 'confirmed', 'accepted', 'preparing', 'ready',
                'assigned', 'picked_up', 'delivered', 'completed', 'cancelled', 'refunded',
            ])->default('draft');
            $table->string('idempotency_key')->nullable()->unique();
            $table->uuid('created_by')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('food_item_id');
            $table->string('name'); // snapshot at time of order
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total', 12, 2);
            $table->json('modifiers')->nullable(); // snapshot: [{id,name,price}]
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};

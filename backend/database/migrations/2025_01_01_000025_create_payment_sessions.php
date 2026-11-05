<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per attempt to collect an order's money through an online gateway. The amount is fixed
        // server-side at creation; the order is only credited after the provider confirms it server-to-server.
        Schema::create('payment_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->string('gateway_key', 40);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('BDT');
            $table->enum('status', ['created', 'pending', 'paid', 'failed', 'cancelled', 'expired', 'review'])->default('created');
            $table->string('provider_ref', 191)->nullable(); // bKash paymentID, SSLCommerz tran_id, …
            $table->string('provider_trx', 191)->nullable(); // the final transaction id shown to the customer
            $table->text('redirect_url')->nullable();
            $table->text('qr_payload')->nullable();
            $table->json('payload')->nullable(); // non-secret provider data needed to verify later
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('note', 255)->nullable(); // failure reason or why it needs a human look
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['order_id', 'gateway_key']);
            $table->unique(['gateway_key', 'provider_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_sessions');
    }
};

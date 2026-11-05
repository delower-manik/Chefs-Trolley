<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('order_id')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->string('reviewer_name')->nullable(); // for a guest review with no linked customer
            $table->unsignedTinyInteger('rating'); // 1-5, enforced in validation (portable across DB drivers)
            $table->text('comment')->nullable();
            // Reviews start unpublished -- a manager decides what goes live,
            // rather than anything typed in appearing on the public site
            // immediately.
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->index(['branch_id', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};

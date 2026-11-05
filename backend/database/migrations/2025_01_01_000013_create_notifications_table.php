<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * This is an internal record of announcements/notifications, NOT a
 * delivery system -- there is no SMS/push/email provider wired up yet.
 * "Sending" one here just marks it sent and stamps `sent_at`; actually
 * dispatching it through Firebase/FCM or an SMS gateway is Integrations
 * work (architecture §5 "Integrations" / Phase 8). See README.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->nullable();
            $table->string('title');
            $table->text('body');
            $table->enum('audience', ['all_customers', 'specific_customer']);
            $table->uuid('customer_id')->nullable(); // set when audience = specific_customer
            $table->enum('status', ['draft', 'sent'])->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

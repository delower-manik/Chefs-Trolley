<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrations domain — architecture §5 "Integrations" and §6 "Wi-Fi Zone
 * Module". Every table here is *configuration*, not a live integration:
 * - payment_gateway_configs stores which gateways are enabled and their
 *   credentials (encrypted at rest), but no gateway SDK is wired up to
 *   actually process a payment.
 * - webhooks stores subscription config (url, event, signing secret), but
 *   events are not yet dispatched to them -- see README.
 * - wifi_zones/wifi_vouchers model the architecture's explicit warning
 *   (§6): this is voucher/access management, not real nearby-device
 *   discovery or a live RouterOS connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('gateway_key')->unique(); // e.g. "bkash", "nagad", "sslcommerz", "cash_on_delivery"
            $table->string('display_name');
            $table->boolean('is_enabled')->default(false);
            $table->text('config')->nullable(); // encrypted JSON: API keys/merchant IDs, never stored in plaintext
            $table->timestamps();
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('url');
            $table->string('event'); // e.g. "order.created", "order.delivered", "review.created"
            $table->string('secret'); // used to sign the (future) payload -- see README
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('wifi_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id'); // Wi-Fi hardware is physically per-branch
            $table->string('name');
            $table->string('ssid')->nullable();
            $table->enum('access_type', ['open', 'voucher', 'time_limited'])->default('open');
            $table->unsignedInteger('default_session_minutes')->nullable(); // used when access_type = time_limited
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('wifi_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wifi_zone_id');
            $table->string('code')->unique();
            $table->unsignedInteger('duration_minutes');
            $table->boolean('is_used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('wifi_zone_id')->references('id')->on('wifi_zones')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wifi_vouchers');
        Schema::dropIfExists('wifi_zones');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('payment_gateway_configs');
    }
};

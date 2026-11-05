<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Customer website (Phase 19). Customers sign in with a phone OTP; their sessions live in their OWN table, never in
| Sanctum's personal_access_tokens, so a customer token can never authenticate against a staff route.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique(); // sha256 of the bearer token; the token itself is never stored
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['customer_id', 'expires_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel', 10)->nullable(); // 'web' for orders placed on the customer website; null = staff-entered
            $table->string('delivery_phone', 20)->nullable();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->uuid('customer_id')->nullable();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['channel', 'delivery_phone']));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['phone_verified_at', 'last_login_at']));
        Schema::dropIfExists('customer_sessions');
    }
};

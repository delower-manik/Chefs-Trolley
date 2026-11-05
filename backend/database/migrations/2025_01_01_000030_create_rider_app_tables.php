<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Rider app (Phase 20). A rider signs in as a normal staff user (role "Rider"); riders.user_id links the login to the
| rider record. Cash collected on delivery is tracked per delivery until a manager settles it.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riders', function (Blueprint $table) {
            $table->foreignUuid('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->boolean('location_sharing')->default(false);
            $table->decimal('last_lat', 9, 6)->nullable();
            $table->decimal('last_lng', 9, 6)->nullable();
            $table->timestamp('last_located_at')->nullable();
        });

        Schema::create('rider_cash_collections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('rider_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('delivery_assignment_id')->unique()->constrained()->restrictOnDelete(); // one collection per delivery, ever
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('collected_at');
            $table->timestamp('settled_at')->nullable();
            $table->foreignUuid('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['rider_id', 'settled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_cash_collections');
        Schema::table('riders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['location_sharing', 'last_lat', 'last_lng', 'last_located_at']);
        });
    }
};

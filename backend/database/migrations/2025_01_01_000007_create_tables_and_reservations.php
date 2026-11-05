<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant Operations §5 — Floor Plans / Tables / Table Reservations.
 * Floor plan layout (x/y coordinates) is kept minimal here; the visual
 * floor-plan editor is a frontend-only concern on top of this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('label'); // e.g. "T-12"
            $table->unsignedInteger('capacity');
            $table->enum('status', ['available', 'occupied', 'reserved', 'out_of_service'])->default('available');
            $table->unsignedInteger('pos_x')->default(0);
            $table->unsignedInteger('pos_y')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'label']);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('dining_table_id')->nullable();
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->unsignedInteger('party_size');
            $table->timestamp('reserved_for');
            $table->unsignedInteger('duration_minutes')->default(90);
            $table->enum('status', ['pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show'])
                ->default('pending');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('dining_table_id')->references('id')->on('dining_tables')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'reserved_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('dining_tables');
    }
};

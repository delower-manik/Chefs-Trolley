<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Floor plans: a branch can have several rooms/levels; tables are placed on
 * one with x/y/size/shape. Also links an order to the table it was opened on
 * (needed for waiter tabs, adding rounds and table transfer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floor_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('name');
            $table->unsignedInteger('width')->default(1000);
            $table->unsignedInteger('height')->default(600);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'name']);
        });

        Schema::table('dining_tables', function (Blueprint $table) {
            $table->uuid('floor_plan_id')->nullable()->after('branch_id');
            $table->unsignedInteger('width')->default(80)->after('pos_y');
            $table->unsignedInteger('height')->default(80)->after('width');
            $table->enum('shape', ['rect', 'round'])->default('rect')->after('height');
            $table->foreign('floor_plan_id')->references('id')->on('floor_plans')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('dining_table_id')->nullable()->after('customer_id');
            $table->foreign('dining_table_id')->references('id')->on('dining_tables')->nullOnDelete();
            $table->index(['dining_table_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropForeign(['dining_table_id']);
            $t->dropIndex(['dining_table_id', 'status']);
            $t->dropColumn('dining_table_id');
        });
        Schema::table('dining_tables', function (Blueprint $t) {
            $t->dropForeign(['floor_plan_id']);
            $t->dropColumn(['floor_plan_id', 'width', 'height', 'shape']);
        });
        Schema::dropIfExists('floor_plans');
    }
};

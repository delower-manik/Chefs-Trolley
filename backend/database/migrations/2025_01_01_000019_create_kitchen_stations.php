<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kitchen stations (Grill, Fry, Bar, Dessert ...). A food item routes to one
 * station; the item's station and a per-item kitchen state are snapshotted on
 * the order line so each station advances only its own part of a ticket.
 * An order's kitchen_state is derived from its least-advanced line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'name']);
        });

        Schema::table('food_items', function (Blueprint $table) {
            $table->uuid('kitchen_station_id')->nullable()->after('tax_rate_id');
            $table->foreign('kitchen_station_id')->references('id')->on('kitchen_stations')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->uuid('kitchen_station_id')->nullable()->after('food_item_id');
            $table->enum('kitchen_state', ['new', 'accepted', 'preparing', 'ready', 'served', 'completed'])
                ->default('new')->after('kitchen_station_id');
            $table->index(['order_id', 'kitchen_station_id']);
        });

        // Existing lines inherit their order's current kitchen state.
        DB::statement('UPDATE order_items SET kitchen_state = (SELECT kitchen_state FROM orders WHERE orders.id = order_items.order_id)');
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropIndex(['order_id', 'kitchen_station_id']);
            $t->dropColumn(['kitchen_station_id', 'kitchen_state']);
        });
        Schema::table('food_items', function (Blueprint $t) {
            $t->dropForeign(['kitchen_station_id']);
            $t->dropColumn('kitchen_station_id');
        });
        Schema::dropIfExists('kitchen_stations');
    }
};

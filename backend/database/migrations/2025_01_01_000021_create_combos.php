<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Combos & Deals: a fixed-price bundle of food items, optionally only
 * orderable inside a start/end window (a "deal"). When ordered, a combo is
 * expanded into its component order lines with the combo price allocated
 * across them, so kitchen routing, stock consumption, tax and product reports
 * keep working per dish. combo_id/combo_name on the line mark its origin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('combo_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('combo_id');
            $table->uuid('food_item_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->foreign('combo_id')->references('id')->on('combos')->cascadeOnDelete();
            $table->foreign('food_item_id')->references('id')->on('food_items')->restrictOnDelete();
            $table->unique(['combo_id', 'food_item_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->uuid('combo_id')->nullable()->after('food_item_id');
            $table->string('combo_name')->nullable()->after('combo_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn(['combo_id', 'combo_name']));
        Schema::dropIfExists('combo_items');
        Schema::dropIfExists('combos');
    }
};

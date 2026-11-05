<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recipes tie the Menu domain to the Inventory domain: each line says
 * "selling one of this food item consumes this much of this ingredient."
 * Consumption is applied automatically when an order is created
 * (OrderController@store) -- see architecture §10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('food_item_id');
            $table->uuid('ingredient_id');
            $table->decimal('quantity_required', 14, 3);
            $table->timestamps();

            $table->foreign('food_item_id')->references('id')->on('food_items')->cascadeOnDelete();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->restrictOnDelete();
            $table->unique(['food_item_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredients');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu domain — architecture §5 "Menu" and §10 (recipes are introduced in
 * Phase 4 alongside Inventory since they consume ingredient stock; combos
 * are deferred to a later Phase 2 iteration to keep this migration focused).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->nullable(); // null = available to all branches
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'slug']);
        });

        Schema::create('food_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('category_id');
            $table->uuid('branch_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->decimal('base_price', 12, 2);
            $table->decimal('tax_rate', 5, 2)->default(0); // percentage
            $table->string('image_path')->nullable();
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->index(['category_id', 'is_available']);
        });

        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('food_item_id');
            $table->string('name'); // e.g. "Size", "Add-ons"
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('min_select')->default(0);
            $table->unsignedInteger('max_select')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('food_item_id')->references('id')->on('food_items')->cascadeOnDelete();
        });

        Schema::create('modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('modifier_group_id');
            $table->string('name'); // e.g. "Large", "Extra cheese"
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('modifier_group_id')->references('id')->on('modifier_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('food_items');
        Schema::dropIfExists('categories');
    }
};

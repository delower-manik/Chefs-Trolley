<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax configuration (architecture §5 "Settings/Tax"). Each branch defines its
 * own named rates; a food item may point at one, otherwise the branch default
 * applies. `orders.tax_inclusive_total` records how much of `tax_total` was
 * already inside the item prices (tax-inclusive rates) so grand_total can be
 * reconstructed exactly: subtotal + (tax_total - tax_inclusive_total) - discount + fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('name');
            $table->decimal('rate', 5, 2); // percentage, 0-100
            $table->boolean('is_inclusive')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->index(['branch_id', 'is_default']);
        });

        Schema::table('food_items', function (Blueprint $table) {
            $table->uuid('tax_rate_id')->nullable()->after('tax_rate');
            $table->foreign('tax_rate_id')->references('id')->on('tax_rates')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('tax_inclusive_total', 12, 2)->default(0)->after('tax_total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('tax_inclusive_total'));
        Schema::table('food_items', function (Blueprint $t) {
            $t->dropForeign(['tax_rate_id']);
            $t->dropColumn('tax_rate_id');
        });
        Schema::dropIfExists('tax_rates');
    }
};

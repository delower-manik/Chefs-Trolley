<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveRecipeRequest;
use App\Models\AuditLog;
use App\Models\FoodItem;
use Illuminate\Support\Facades\DB;

class RecipeController extends Controller
{
    /**
     * GET /food-items/{foodItem}/recipe
     */
    public function show(FoodItem $foodItem)
    {
        $foodItem->load('recipeIngredients.ingredient.unit');

        return response()->json(['data' => $this->serialize($foodItem)]);
    }

    /**
     * PUT /food-items/{foodItem}/recipe
     * Full replace, same pattern as FoodItemController's modifier-group
     * sync: delete the previous lines and recreate inside one transaction
     * so the recipe is never left half-written.
     */
    public function update(SaveRecipeRequest $request, FoodItem $foodItem)
    {
        DB::transaction(function () use ($request, $foodItem) {
            $foodItem->recipeIngredients()->delete();

            foreach ($request->validated('ingredients') as $line) {
                $foodItem->recipeIngredients()->create([
                    'ingredient_id' => $line['ingredient_id'],
                    'quantity_required' => $line['quantity_required'],
                ]);
            }
        });

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'recipe.updated',
            subjectType: FoodItem::class,
            subjectId: $foodItem->id,
            branchId: $foodItem->branch_id,
            changes: ['after' => ['ingredientCount' => count($request->validated('ingredients'))]],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($foodItem->fresh('recipeIngredients.ingredient.unit'))]);
    }

    private function serialize(FoodItem $foodItem): array
    {
        return [
            'foodItemId' => $foodItem->id,
            'foodItemName' => $foodItem->name,
            'ingredients' => $foodItem->recipeIngredients->map(fn ($line) => [
                'id' => $line->id,
                'ingredientId' => $line->ingredient_id,
                'ingredientName' => $line->ingredient?->name,
                'unitAbbreviation' => $line->ingredient?->unit?->abbreviation,
                'quantityRequired' => (float) $line->quantity_required,
            ]),
        ];
    }
}

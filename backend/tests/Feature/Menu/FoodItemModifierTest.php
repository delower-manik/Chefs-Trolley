<?php

namespace Tests\Feature\Menu;

use App\Models\Category;
use Tests\TestCase;

class FoodItemModifierTest extends TestCase
{
    public function test_creating_a_food_item_with_modifier_groups_persists_them_transactionally(): void
    {
        $this->actingAsUserWithPermissions(['menu.manage']);
        $category = Category::create(['name' => 'Pizza', 'slug' => 'pizza']);

        $response = $this->postJson('/api/v1/food-items', [
            'category_id' => $category->id,
            'name' => 'Margherita',
            'base_price' => 600,
            'modifier_groups' => [
                [
                    'name' => 'Size',
                    'is_required' => true,
                    'min_select' => 1,
                    'max_select' => 1,
                    'modifiers' => [
                        ['name' => 'Regular', 'price' => 0],
                        ['name' => 'Large', 'price' => 150],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.modifierGroups.0.name', 'Size')
            ->assertJsonCount(2, 'data.modifierGroups.0.modifiers');

        $this->assertDatabaseHas('modifier_groups', ['name' => 'Size']);
        $this->assertDatabaseHas('modifiers', ['name' => 'Large', 'price' => 150]);
    }

    public function test_updating_modifier_groups_replaces_the_previous_set(): void
    {
        $this->actingAsUserWithPermissions(['menu.manage']);
        $category = Category::create(['name' => 'Pizza', 'slug' => 'pizza-2']);

        $create = $this->postJson('/api/v1/food-items', [
            'category_id' => $category->id,
            'name' => 'Pepperoni',
            'base_price' => 700,
            'modifier_groups' => [
                ['name' => 'Size', 'modifiers' => [['name' => 'Regular', 'price' => 0]]],
            ],
        ]);
        $itemId = $create->json('data.id');

        $update = $this->putJson("/api/v1/food-items/{$itemId}", [
            'category_id' => $category->id,
            'name' => 'Pepperoni',
            'base_price' => 700,
            'modifier_groups' => [
                ['name' => 'Crust', 'modifiers' => [['name' => 'Thin', 'price' => 0], ['name' => 'Thick', 'price' => 50]]],
            ],
        ]);

        $update->assertStatus(200)
            ->assertJsonCount(1, 'data.modifierGroups')
            ->assertJsonPath('data.modifierGroups.0.name', 'Crust');
        $this->assertDatabaseMissing('modifier_groups', ['name' => 'Size', 'food_item_id' => $itemId]);
    }
}

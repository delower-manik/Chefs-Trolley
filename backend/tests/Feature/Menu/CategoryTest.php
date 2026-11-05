<?php

namespace Tests\Feature\Menu;

use App\Models\Category;
use App\Models\FoodItem;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    public function test_can_create_a_category(): void
    {
        $this->actingAsUserWithPermissions(['menu.manage']);

        $response = $this->postJson('/api/v1/categories', ['name' => 'Biryani']);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Biryani')->assertJsonPath('data.slug', 'biryani');
        $this->assertDatabaseHas('categories', ['name' => 'Biryani', 'slug' => 'biryani']);
    }

    public function test_cannot_delete_a_category_that_still_has_food_items(): void
    {
        $this->actingAsUserWithPermissions(['menu.manage']);
        $category = Category::create(['name' => 'Grill', 'slug' => 'grill']);
        FoodItem::create([
            'category_id' => $category->id, 'name' => 'Chicken Tikka', 'slug' => 'chicken-tikka', 'base_price' => 350,
        ]);

        $response = $this->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(422)->assertJsonPath('code', 'category_not_empty');
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_can_delete_an_empty_category(): void
    {
        $this->actingAsUserWithPermissions(['menu.manage']);
        $category = Category::create(['name' => 'Empty Category', 'slug' => 'empty-category']);

        $this->deleteJson("/api/v1/categories/{$category->id}")->assertStatus(204);
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }
}

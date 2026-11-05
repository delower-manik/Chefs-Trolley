<?php

namespace Tests\Feature\Restaurant;

use App\Models\DiningTable;
use App\Models\FloorPlan;
use Tests\TestCase;

class FloorPlanTest extends TestCase
{
    public function test_floor_plan_crud_with_per_branch_unique_names(): void
    {
        $this->actingAsUserWithPermissions(['tables.manage']);

        $id = $this->postJson('/api/v1/floor-plans', ['name' => 'Ground floor', 'width' => 1200, 'height' => 700])
            ->assertStatus(201)->assertJsonPath('data.width', 1200)->json('data.id');
        $this->postJson('/api/v1/floor-plans', ['name' => 'Ground floor'])->assertStatus(422);
        $this->putJson("/api/v1/floor-plans/{$id}", ['name' => 'Main hall'])->assertStatus(200)->assertJsonPath('data.name', 'Main hall');
        $this->postJson('/api/v1/floor-plans', ['name' => 'Tiny', 'width' => 10])->assertStatus(422);

        $this->deleteJson("/api/v1/floor-plans/{$id}")->assertStatus(204);
        $this->assertDatabaseHas('audit_logs', ['action' => 'floor_plan.deleted', 'subject_id' => $id]);
    }

    public function test_saving_a_layout_places_tables_with_position_size_and_shape(): void
    {
        $user = $this->actingAsUserWithPermissions(['tables.manage']);
        $plan = FloorPlan::create(['branch_id' => $user->active_branch_id, 'name' => 'Hall']);
        $t1 = DiningTable::create(['branch_id' => $user->active_branch_id, 'label' => 'T1', 'capacity' => 2]);
        $t2 = DiningTable::create(['branch_id' => $user->active_branch_id, 'label' => 'T2', 'capacity' => 4]);

        $this->putJson("/api/v1/floor-plans/{$plan->id}/layout", ['tables' => [
            ['id' => $t1->id, 'pos_x' => 100, 'pos_y' => 50, 'width' => 90, 'height' => 90, 'shape' => 'round'],
            ['id' => $t2->id, 'pos_x' => 300, 'pos_y' => 200],
        ]])->assertStatus(200)->assertJsonCount(2, 'data.tables');

        $t1->refresh();
        $this->assertSame($plan->id, $t1->floor_plan_id);
        $this->assertSame([100, 50, 90, 'round'], [$t1->pos_x, $t1->pos_y, $t1->width, $t1->shape]);
        $this->assertSame([80, 'rect'], [$t2->fresh()->width, $t2->fresh()->shape], 'unspecified size/shape keep their values');
    }

    public function test_layout_cannot_move_another_branchs_table(): void
    {
        $user = $this->actingAsUserWithPermissions(['tables.manage']);
        $plan = FloorPlan::create(['branch_id' => $user->active_branch_id, 'name' => 'Hall']);
        $foreign = DiningTable::create(['branch_id' => $this->createBranch()->id, 'label' => 'F1', 'capacity' => 2]);

        $this->putJson("/api/v1/floor-plans/{$plan->id}/layout", ['tables' => [
            ['id' => $foreign->id, 'pos_x' => 1, 'pos_y' => 1],
        ]])->assertStatus(422)->assertJsonPath('code', 'invalid_table');

        $this->assertNull($foreign->fresh()->floor_plan_id);
    }

    public function test_layout_validation_rejects_bad_coordinates_and_duplicates(): void
    {
        $user = $this->actingAsUserWithPermissions(['tables.manage']);
        $plan = FloorPlan::create(['branch_id' => $user->active_branch_id, 'name' => 'Hall']);
        $t = DiningTable::create(['branch_id' => $user->active_branch_id, 'label' => 'T1', 'capacity' => 2]);

        $this->putJson("/api/v1/floor-plans/{$plan->id}/layout", ['tables' => [['id' => $t->id, 'pos_x' => -5, 'pos_y' => 1]]])->assertStatus(422);
        $this->putJson("/api/v1/floor-plans/{$plan->id}/layout", ['tables' => [
            ['id' => $t->id, 'pos_x' => 1, 'pos_y' => 1], ['id' => $t->id, 'pos_x' => 2, 'pos_y' => 2],
        ]])->assertStatus(422);
        $this->putJson("/api/v1/floor-plans/{$plan->id}/layout", ['tables' => [['id' => $t->id, 'pos_x' => 1, 'pos_y' => 1, 'shape' => 'star']]])->assertStatus(422);
    }

    public function test_deleting_a_plan_keeps_its_tables_unplaced(): void
    {
        $user = $this->actingAsUserWithPermissions(['tables.manage']);
        $plan = FloorPlan::create(['branch_id' => $user->active_branch_id, 'name' => 'Hall']);
        $t = DiningTable::create(['branch_id' => $user->active_branch_id, 'label' => 'T1', 'capacity' => 2, 'floor_plan_id' => $plan->id]);

        $this->deleteJson("/api/v1/floor-plans/{$plan->id}")->assertStatus(204);

        $this->assertNotNull(DiningTable::find($t->id));
    }

    public function test_plans_of_other_branches_are_hidden_and_untouchable(): void
    {
        $user = $this->actingAsUserWithPermissions(['tables.manage']);
        $foreign = FloorPlan::create(['branch_id' => $this->createBranch()->id, 'name' => 'Elsewhere']);

        $this->getJson('/api/v1/floor-plans')->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/floor-plans/{$foreign->id}", ['name' => 'Hacked'])->assertStatus(404);
        $this->deleteJson("/api/v1/floor-plans/{$foreign->id}")->assertStatus(404);
        $this->putJson("/api/v1/floor-plans/{$foreign->id}/layout", ['tables' => []])->assertStatus(404);
    }

    public function test_a_table_can_be_assigned_a_plan_only_from_its_own_branch(): void
    {
        $user = $this->actingAsUserWithPermissions(['tables.manage']);
        $mine = FloorPlan::create(['branch_id' => $user->active_branch_id, 'name' => 'Mine']);
        $foreign = FloorPlan::create(['branch_id' => $this->createBranch()->id, 'name' => 'Foreign']);

        $this->postJson('/api/v1/tables', ['label' => 'A1', 'capacity' => 2, 'floor_plan_id' => $mine->id])
            ->assertStatus(201)->assertJsonPath('data.floorPlanId', $mine->id);
        $this->postJson('/api/v1/tables', ['label' => 'A2', 'capacity' => 2, 'floor_plan_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('floor_plan_id');
    }
}

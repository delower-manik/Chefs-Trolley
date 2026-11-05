<?php

namespace Tests\Concerns;

use App\Models\Order;

trait TakesPayments
{
    use BuildsMenu;

    protected array $posPerms = ['orders.view', 'orders.create', 'pos.access', 'cash.manage', 'discounts.apply'];

    /** Authenticated cashier with a placed order of 2 × 100 (no tax) */
    protected function cashierWithOrder(array $extraPerms = [], float $price = 100, int $qty = 2): array
    {
        $user = $this->actingAsUserWithPermissions(array_merge($this->posPerms, $extraPerms));
        $dish = $this->makeFoodItem($user->active_branch_id, $price);
        $id = $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'items' => [['food_item_id' => $dish->id, 'quantity' => $qty]],
        ])->assertStatus(201)->json('data.id');

        return [$user, Order::findOrFail($id), $dish];
    }

    protected function openShift(float $float = 500): string
    {
        return $this->postJson('/api/v1/cash/shifts/open', ['opening_float' => $float])->assertStatus(201)->json('data.id');
    }
}

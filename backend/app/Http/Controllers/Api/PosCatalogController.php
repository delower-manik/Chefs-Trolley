<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Combo;
use App\Models\DiningTable;
use App\Models\FloorPlan;
use App\Models\FoodItem;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Read-only bundle for order-taking screens (POS, waiter). Gated by
 * `orders.create` rather than `menu.manage`: the people who take orders
 * (Cashier, Waiter) must be able to load the menu without being allowed to
 * edit it.
 */
class PosCatalogController extends Controller
{
    public function show(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $inBranch = fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId));

        $categories = Category::query()->where('is_active', true)->where($inBranch)
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);

        $items = FoodItem::query()->with('modifierGroups.modifiers')
            ->where('is_available', true)->where($inBranch)
            ->orderBy('sort_order')->orderBy('name')->get();

        $combos = Combo::query()->with('items.foodItem')
            ->where('branch_id', $branchId)->where('is_active', true)->get()
            ->filter(fn (Combo $c) => $c->isOrderableNow())->values();

        $tables = DiningTable::query()->where('branch_id', $branchId)->orderBy('label')->get();
        $plans = FloorPlan::query()->where('branch_id', $branchId)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'width', 'height']);

        // One query for all open tabs instead of one per table.
        $openOrders = Order::query()->whereIn('dining_table_id', $tables->pluck('id'))
            ->whereNotIn('status', DiningTable::CLOSED_ORDER_STATUSES)
            ->get(['id', 'code', 'dining_table_id', 'grand_total'])->keyBy('dining_table_id');

        return response()->json(['data' => [
            'categories' => $categories,
            'items' => $items->map(fn (FoodItem $i) => [
                'id' => $i->id,
                'categoryId' => $i->category_id,
                'name' => $i->name,
                'description' => $i->description,
                'basePrice' => (float) $i->base_price,
                'imagePath' => $i->image_path,
                'modifierGroups' => $i->modifierGroups->map(fn ($g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'isRequired' => $g->is_required,
                    'minSelect' => $g->min_select,
                    'maxSelect' => $g->max_select,
                    'modifiers' => $g->modifiers->where('is_active', true)->values()->map(fn ($m) => [
                        'id' => $m->id, 'name' => $m->name, 'price' => (float) $m->price,
                    ]),
                ]),
            ]),
            'combos' => $combos->map(fn (Combo $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'price' => (float) $c->price,
                'items' => $c->items->map(fn ($ci) => ['name' => $ci->foodItem?->name, 'quantity' => $ci->quantity]),
            ]),
            'floorPlans' => $plans,
            'tables' => $tables->map(fn (DiningTable $t) => [
                'id' => $t->id,
                'label' => $t->label,
                'capacity' => $t->capacity,
                'status' => $t->status,
                'floorPlanId' => $t->floor_plan_id,
                'posX' => $t->pos_x,
                'posY' => $t->pos_y,
                'width' => $t->width,
                'height' => $t->height,
                'shape' => $t->shape,
                'openOrder' => ($o = $openOrders->get($t->id)) ? ['id' => $o->id, 'code' => $o->code, 'grandTotal' => (float) $o->grand_total] : null,
            ]),
        ]]);
    }
}

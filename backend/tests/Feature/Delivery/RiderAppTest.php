<?php

namespace Tests\Feature\Delivery;

use App\Models\Branch;
use App\Models\DeliveryAssignment;
use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Rider;
use App\Models\RiderCashCollection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsMenu;
use Tests\TestCase;

class RiderAppTest extends TestCase
{
    use BuildsMenu;

    private Branch $branch;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    }

    /** A dispatcher+cashier who places and assigns orders. */
    private function staff(): User
    {
        return $this->staff = $this->actingAsUserWithPermissions(['orders.create', 'orders.view', 'delivery.manage', 'cash.manage', 'pos.access'], $this->branch);
    }

    /** A rider login linked to a rider record, in the same branch. */
    private function riderUser(string $name = 'Karim'): array
    {
        $role = Role::firstOrCreate(['name' => 'Rider']);
        $role->permissions()->sync([Permission::firstOrCreate(['name' => 'rider.app'])->id]);
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@x.test', 'password' => bcrypt('x'), 'active_branch_id' => $this->branch->id]);
        $user->branches()->attach($this->branch->id);
        $user->roles()->attach($role->id);
        $rider = Rider::create(['branch_id' => $this->branch->id, 'name' => $name, 'phone' => '017'.random_int(10000000, 99999999), 'user_id' => $user->id]);

        return [$user, $rider];
    }

    private function deliveryOrder(float $price = 200): Order
    {
        $dish = $this->makeFoodItem($this->branch->id, $price);
        $id = $this->postJson('/api/v1/orders', ['source' => 'delivery', 'delivery_address' => 'House 5, Road 3, Gulshan', 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]])->assertStatus(201)->json('data.id');

        return Order::findOrFail($id);
    }

    private function assign(Order $o, Rider $r): void
    {
        $this->postJson("/api/v1/deliveries/{$o->id}/assign", ['rider_id' => $r->id])->assertOk();
    }

    public function test_a_rider_sees_only_their_own_open_deliveries_and_cannot_touch_anothers(): void
    {
        $this->staff();
        [$karim, $kr] = $this->riderUser('Karim');
        [$jamal, $jr] = $this->riderUser('Jamal');
        $mine = $this->deliveryOrder();
        $theirs = $this->deliveryOrder();
        $this->assign($mine, $kr);
        $this->assign($theirs, $jr);

        $this->actingAs($karim, 'sanctum');
        $list = $this->getJson('/api/v1/rider/deliveries')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame($mine->code, $list[0]['orderCode']);
        $this->assertStringContainsString('maps/search', $list[0]['mapUrl']);

        $other = DeliveryAssignment::where('order_id', $theirs->id)->value('id');
        $this->getJson("/api/v1/rider/deliveries/{$other}")->assertStatus(404);
        $this->postJson("/api/v1/rider/deliveries/{$other}/pickup")->assertStatus(404);
        $this->postJson("/api/v1/rider/deliveries/{$other}/deliver")->assertStatus(404);
        $this->postJson("/api/v1/rider/deliveries/{$other}/fail", ['reason' => 'nope'])->assertStatus(404);
        $this->assertSame('assigned', DeliveryAssignment::find($other)->status);
    }

    public function test_the_rider_role_cannot_use_dispatcher_endpoints_and_non_riders_cannot_use_the_app(): void
    {
        $this->staff();
        [$karim] = $this->riderUser();
        $this->actingAs($karim, 'sanctum');
        $this->getJson('/api/v1/deliveries')->assertStatus(403);
        $this->getJson('/api/v1/riders')->assertStatus(403);
        $this->getJson('/api/v1/orders')->assertStatus(403);

        $this->staff(); // has delivery.manage but not rider.app, and no rider record
        $this->getJson('/api/v1/rider/me')->assertStatus(403);

        // a rider-permission user whose rider record was switched off is locked out too
        [$off, $offRider] = $this->riderUser('Off');
        $offRider->update(['is_active' => false]);
        $this->actingAs($off, 'sanctum');
        $this->getJson('/api/v1/rider/me')->assertStatus(403)->assertJsonPath('code', 'not_a_rider');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/rider/me')->assertStatus(401);
    }

    public function test_full_cod_flow_records_cash_held_by_the_rider_then_a_manager_settles_it_into_their_shift(): void
    {
        $manager = $this->staff();
        $shift = $this->postJson('/api/v1/cash/shifts/open', ['opening_float' => 500])->assertStatus(201)->json('data.id');
        [$karim, $kr] = $this->riderUser();
        $order = $this->deliveryOrder(250);
        $this->assign($order, $kr);
        $aid = DeliveryAssignment::where('order_id', $order->id)->value('id');

        $this->actingAs($karim, 'sanctum');
        $this->postJson("/api/v1/rider/deliveries/{$aid}/deliver", ['cash_collected' => 250])->assertStatus(422)->assertJsonPath('code', 'invalid_delivery_state'); // not picked up yet
        $this->postJson("/api/v1/rider/deliveries/{$aid}/pickup")->assertOk()->assertJsonPath('data.status', 'picked_up');
        $row = $this->getJson("/api/v1/rider/deliveries/{$aid}")->json('data');
        $due = $row['cashToCollect'];
        $this->assertGreaterThan(0, $due);

        // The server's number wins: a wrong or missing figure is refused and nothing is recorded.
        $this->postJson("/api/v1/rider/deliveries/{$aid}/deliver", ['cash_collected' => $due - 10])->assertStatus(422)->assertJsonPath('code', 'cash_mismatch')->assertJsonPath('errors.expected', $due);
        $this->postJson("/api/v1/rider/deliveries/{$aid}/deliver", [])->assertStatus(422)->assertJsonPath('code', 'cash_mismatch');
        $this->assertSame(0, RiderCashCollection::count());

        $this->postJson("/api/v1/rider/deliveries/{$aid}/deliver", ['cash_collected' => $due, 'recipient_name' => 'Mrs Rahman'])->assertOk()->assertJsonPath('data.status', 'delivered');
        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame('paid', $order->payment_state);
        $this->assertSame('available', $kr->fresh()->status);
        $this->assertEquals($due, RiderCashCollection::first()->amount);
        $this->assertEquals($due, $this->getJson('/api/v1/rider/cash')->json('data.holding'));

        // Double submit can't collect twice.
        $this->postJson("/api/v1/rider/deliveries/{$aid}/deliver", ['cash_collected' => $due])->assertStatus(422);
        $this->assertSame(1, RiderCashCollection::count());

        // Manager side.
        $this->actingAs($manager, 'sanctum');
        $this->assertEquals($due, $this->getJson('/api/v1/cash/rider-holdings')->json('data.0.holding'));
        $this->assertSame([], $this->getJson("/api/v1/cash/shifts/{$shift}/report")->json('data.byMethod')); // not in the drawer yet
        $this->postJson("/api/v1/cash/rider-holdings/{$kr->id}/settle")->assertOk()->assertJsonPath('data.settled', $due);
        $this->assertNotNull(RiderCashCollection::first()->settled_at);
        $this->assertSame($shift, Payment::find(RiderCashCollection::first()->payment_id)->shift_id);
        $this->postJson("/api/v1/cash/rider-holdings/{$kr->id}/settle")->assertStatus(422)->assertJsonPath('code', 'nothing_to_settle');
        $cash = collect($this->getJson("/api/v1/cash/shifts/{$shift}/report")->json('data.byMethod'))->firstWhere('method', 'cash');
        $this->assertEquals($due, $cash['payments']); // now counted in this shift's cash
    }

    public function test_settling_needs_an_open_shift_and_the_right_branch(): void
    {
        $this->staff();
        [$karim, $kr] = $this->riderUser();
        $this->postJson('/api/v1/cash/shifts/open', ['opening_float' => 0])->assertStatus(201);
        $order = $this->deliveryOrder();
        $this->assign($order, $kr);
        $aid = DeliveryAssignment::where('order_id', $order->id)->value('id');
        $this->actingAs($karim, 'sanctum');
        $this->postJson("/api/v1/rider/deliveries/{$aid}/pickup")->assertOk();
        $due = $this->getJson("/api/v1/rider/deliveries/{$aid}")->json('data.cashToCollect');
        $this->postJson("/api/v1/rider/deliveries/{$aid}/deliver", ['cash_collected' => $due])->assertOk();

        $this->actingAsUserWithPermissions(['cash.manage'], $this->branch);
        $this->postJson("/api/v1/cash/rider-holdings/{$kr->id}/settle")->assertStatus(422)->assertJsonPath('code', 'no_open_shift');

        $other = Branch::create(['name' => 'Other', 'code' => 'OTH']);
        $this->actingAsUserWithPermissions(['cash.manage'], $other);
        $this->postJson("/api/v1/cash/rider-holdings/{$kr->id}/settle")->assertStatus(404);
        $this->assertNull(RiderCashCollection::first()->settled_at);
    }

    public function test_an_already_paid_order_collects_no_cash_and_a_cancelled_order_cannot_be_handed_over(): void
    {
        $this->staff();
        $this->postJson('/api/v1/cash/shifts/open', ['opening_float' => 0])->assertStatus(201);
        [$karim, $kr] = $this->riderUser();
        $paid = $this->deliveryOrder();
        $this->postJson("/api/v1/orders/{$paid->id}/payments", ['method' => 'cash'])->assertStatus(201);
        $cancelled = $this->deliveryOrder();
        $this->assign($paid, $kr);
        $this->assign($cancelled, $kr);
        $a1 = DeliveryAssignment::where('order_id', $paid->id)->value('id');
        $a2 = DeliveryAssignment::where('order_id', $cancelled->id)->value('id');
        Order::whereKey($cancelled->id)->update(['status' => 'cancelled']);

        $this->actingAs($karim, 'sanctum');
        $this->assertCount(1, $this->getJson('/api/v1/rider/deliveries')->json('data')); // the cancelled one drops off the list
        $this->postJson("/api/v1/rider/deliveries/{$a2}/pickup")->assertStatus(422)->assertJsonPath('code', 'order_cancelled');

        $this->postJson("/api/v1/rider/deliveries/{$a1}/pickup")->assertOk();
        $this->postJson("/api/v1/rider/deliveries/{$a1}/deliver", ['cash_collected' => 100])->assertStatus(422)->assertJsonPath('code', 'nothing_to_collect');
        $this->postJson("/api/v1/rider/deliveries/{$a1}/deliver")->assertOk();
        $this->assertSame(0, RiderCashCollection::count());
    }

    public function test_failing_a_delivery_frees_the_rider_and_closes_location_sharing(): void
    {
        $this->staff();
        [$karim, $kr] = $this->riderUser();
        $order = $this->deliveryOrder();
        $this->assign($order, $kr);
        $aid = DeliveryAssignment::where('order_id', $order->id)->value('id');

        $this->actingAs($karim, 'sanctum');
        $this->postJson("/api/v1/rider/deliveries/{$aid}/fail", ['reason' => 'x'])->assertStatus(422); // too short
        $this->postJson('/api/v1/rider/sharing', ['on' => true])->assertOk()->assertJsonPath('data.locationSharing', true);
        $this->postJson('/api/v1/rider/location', ['lat' => 23.7925, 'lng' => 90.4078])->assertOk();
        $this->assertNotNull($kr->fresh()->last_lat);

        $this->postJson("/api/v1/rider/deliveries/{$aid}/fail", ['reason' => 'Customer not answering'])->assertOk()->assertJsonPath('data.status', 'failed');
        $kr->refresh();
        $this->assertSame('available', $kr->status);
        $this->assertFalse($kr->location_sharing);
        $this->assertNull($kr->last_lat); // we stop following the rider the moment they're free
        $this->postJson('/api/v1/rider/location', ['lat' => 23.7, 'lng' => 90.4])->assertStatus(409);
        $this->postJson('/api/v1/rider/sharing', ['on' => true])->assertStatus(422)->assertJsonPath('code', 'no_open_delivery');
        $this->assertSame('Customer not answering', DeliveryAssignment::find($aid)->failure_reason);
    }

    public function test_location_is_validated_and_shown_to_dispatch_only_while_fresh(): void
    {
        $manager = $this->staff();
        [$karim, $kr] = $this->riderUser();
        $order = $this->deliveryOrder();
        $this->assign($order, $kr);

        $this->actingAs($karim, 'sanctum');
        $this->postJson('/api/v1/rider/location', ['lat' => 23.7, 'lng' => 90.4])->assertStatus(409); // not sharing yet
        $this->postJson('/api/v1/rider/sharing', ['on' => true])->assertOk();
        $this->postJson('/api/v1/rider/location', ['lat' => 123, 'lng' => 90.4])->assertStatus(422);
        $this->postJson('/api/v1/rider/location', ['lat' => 23.7925, 'lng' => 90.4078])->assertOk();

        $this->actingAs($manager, 'sanctum');
        $this->assertEqualsWithDelta(23.7925, $this->getJson('/api/v1/riders')->json('data.0.location.lat'), 0.0001);
        $kr->update(['last_located_at' => now()->subMinutes(20)]);
        $this->assertNull($this->getJson('/api/v1/riders')->json('data.0.location'));

        $this->actingAs($karim, 'sanctum');
        $this->postJson('/api/v1/rider/sharing', ['on' => false])->assertOk();
        $this->assertNull($kr->fresh()->last_lng);
    }

    public function test_status_toggle_is_blocked_while_carrying_an_order(): void
    {
        $this->staff();
        [$karim, $kr] = $this->riderUser();
        $this->actingAs($karim, 'sanctum');
        $this->putJson('/api/v1/rider/status', ['status' => 'available'])->assertOk()->assertJsonPath('data.status', 'available');
        $this->putJson('/api/v1/rider/status', ['status' => 'busy'])->assertStatus(422);

        $this->staff();
        $this->assign($this->deliveryOrder(), $kr);
        $this->actingAs($karim, 'sanctum');
        $this->putJson('/api/v1/rider/status', ['status' => 'offline'])->assertStatus(422)->assertJsonPath('code', 'has_open_deliveries');
    }

    public function test_assigning_a_delivery_pushes_to_the_riders_registered_phone_only(): void
    {
        Queue::fake();
        $this->staff();
        [$karim, $kr] = $this->riderUser('Karim');
        [$jamal] = $this->riderUser('Jamal');
        DeviceToken::create(['owner_type' => User::class, 'owner_id' => $karim->id, 'token' => 'tok-karim', 'platform' => 'web']);
        DeviceToken::create(['owner_type' => User::class, 'owner_id' => $jamal->id, 'token' => 'tok-jamal', 'platform' => 'web']);

        $this->assign($this->deliveryOrder(), $kr);

        $rows = NotificationDelivery::where('purpose', 'delivery_assigned')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('tok-karim', $rows[0]->recipient);
        $this->assertSame('push', $rows[0]->channel);
        $this->assertStringContainsString('House 5', $rows[0]->body);
    }

    public function test_admin_can_link_a_rider_to_a_login_but_not_one_already_in_use(): void
    {
        $this->staff();
        [$karim, $kr] = $this->riderUser('Karim');
        $free = User::create(['name' => 'New', 'email' => 'new@x.test', 'password' => bcrypt('x')]);
        $other = Rider::create(['branch_id' => $this->branch->id, 'name' => 'Other', 'phone' => '01799990000']);

        $this->putJson("/api/v1/riders/{$other->id}", ['user_id' => $karim->id])->assertStatus(422);
        $this->putJson("/api/v1/riders/{$other->id}", ['user_id' => $free->id])->assertOk()->assertJsonPath('data.userId', $free->id);
        $this->assertSame($free->id, $other->fresh()->user_id);
    }
}

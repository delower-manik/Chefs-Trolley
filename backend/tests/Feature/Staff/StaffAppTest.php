<?php

namespace Tests\Feature\Staff;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\DeviceToken;
use App\Models\Employee;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsMenu;
use Tests\TestCase;

class StaffAppTest extends TestCase
{
    use BuildsMenu;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    }

    /** A staff login with the given permissions, linked to an employee record. */
    private function staffUser(string $name, array $perms, bool $employee = true, ?Branch $branch = null): array
    {
        $branch ??= $this->branch;
        $role = Role::firstOrCreate(['name' => 'R-'.$name]);
        $role->permissions()->sync(collect($perms)->map(fn ($p) => Permission::firstOrCreate(['name' => $p])->id));
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@x.test', 'password' => bcrypt('x'), 'active_branch_id' => $branch->id]);
        $user->branches()->attach($branch->id);
        $user->roles()->attach($role->id);
        $emp = $employee ? Employee::create(['branch_id' => $branch->id, 'user_id' => $user->id, 'name' => $name, 'role_title' => 'Staff', 'phone' => '017'.random_int(10000000, 99999999)]) : null;

        return [$user, $emp];
    }

    public function test_clock_in_and_out_use_server_time_and_cannot_be_repeated(): void
    {
        [$u, $e] = $this->staffUser('Anis', ['staff.app']);
        $this->actingAs($u, 'sanctum');

        $this->getJson('/api/v1/staff/me')->assertOk()->assertJsonPath('data.clockedIn', false);
        $this->postJson('/api/v1/staff/clock-out')->assertStatus(422)->assertJsonPath('code', 'not_clocked_in');
        // a client-supplied time is ignored
        $this->postJson('/api/v1/staff/clock-in', ['check_in_time' => '2000-01-01 00:00:00'])->assertOk()->assertJsonPath('data.clockedIn', true);
        $rec = AttendanceRecord::where('employee_id', $e->id)->firstOrFail();
        $this->assertTrue($rec->check_in_time->isToday());
        $this->postJson('/api/v1/staff/clock-in')->assertStatus(422)->assertJsonPath('code', 'already_clocked_in');
        $this->postJson('/api/v1/staff/clock-out')->assertOk()->assertJsonPath('data.clockedIn', false);
        $this->postJson('/api/v1/staff/clock-out')->assertStatus(422)->assertJsonPath('code', 'already_clocked_out');
        $this->assertSame(1, AttendanceRecord::where('employee_id', $e->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.clock_in']);
    }

    public function test_a_day_the_manager_marked_as_leave_cannot_be_overridden_by_clocking_in(): void
    {
        [$u, $e] = $this->staffUser('Anis', ['staff.app']);
        AttendanceRecord::create(['employee_id' => $e->id, 'branch_id' => $this->branch->id, 'date' => now()->toDateString(), 'status' => 'on_leave']);
        $this->actingAs($u, 'sanctum');
        $this->postJson('/api/v1/staff/clock-in')->assertStatus(422)->assertJsonPath('code', 'day_marked');
    }

    public function test_permission_and_employee_link_are_both_required(): void
    {
        [$noPerm] = $this->staffUser('A', ['orders.view']);
        $this->actingAs($noPerm, 'sanctum');
        $this->getJson('/api/v1/staff/me')->assertStatus(403);

        [$noEmp] = $this->staffUser('B', ['staff.app'], employee: false);
        $this->actingAs($noEmp, 'sanctum');
        $this->getJson('/api/v1/staff/me')->assertStatus(403)->assertJsonPath('code', 'not_an_employee');

        [$off, $e] = $this->staffUser('C', ['staff.app']);
        $e->update(['is_active' => false]);
        $this->actingAs($off, 'sanctum');
        $this->getJson('/api/v1/staff/me')->assertStatus(403);
    }

    private function readyOrder(User $waiter): Order
    {
        $dish = $this->makeFoodItem($this->branch->id, 200);
        $this->actingAs($waiter, 'sanctum');
        $id = $this->postJson('/api/v1/orders', ['source' => 'dine_in', 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]])->assertStatus(201)->json('data.id');

        return Order::findOrFail($id);
    }

    public function test_ready_order_pushes_to_the_waiter_who_took_it_and_can_then_be_served(): void
    {
        Queue::fake();
        [$waiter] = $this->staffUser('Waiter', ['staff.app', 'orders.create', 'orders.view']);
        [$other] = $this->staffUser('Other', ['staff.app', 'orders.create', 'orders.view']);
        [$chef] = $this->staffUser('Chef', ['staff.app', 'kds.access']);
        DeviceToken::create(['owner_type' => User::class, 'owner_id' => $waiter->id, 'token' => 'tok-w', 'platform' => 'web']);
        DeviceToken::create(['owner_type' => User::class, 'owner_id' => $other->id, 'token' => 'tok-o', 'platform' => 'web']);
        $order = $this->readyOrder($waiter);

        $this->actingAs($waiter, 'sanctum');
        $this->postJson("/api/v1/staff/orders/{$order->id}/served")->assertStatus(422)->assertJsonPath('code', 'not_ready');

        $this->actingAs($chef, 'sanctum');
        foreach (['accepted', 'preparing'] as $_) {
            $this->postJson("/api/v1/kitchen/tickets/{$order->id}/bump")->assertOk();
            $this->assertSame(0, NotificationDelivery::where('purpose', 'order_ready')->count());
        }
        $this->postJson("/api/v1/kitchen/tickets/{$order->id}/bump")->assertOk(); // ready
        $rows = NotificationDelivery::where('purpose', 'order_ready')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('tok-w', $rows[0]->recipient);

        $this->actingAs($waiter, 'sanctum');
        $this->postJson("/api/v1/staff/orders/{$order->id}/served")->assertOk()->assertJsonPath('data.kitchenState', 'served');
        $this->assertSame('served', $order->fresh()->kitchen_state);
        $this->postJson("/api/v1/staff/orders/{$order->id}/served")->assertStatus(422)->assertJsonPath('code', 'not_ready');
    }

    public function test_serving_is_limited_to_the_employees_own_branch(): void
    {
        [$waiter] = $this->staffUser('Waiter', ['staff.app', 'orders.create', 'orders.view']);
        $order = $this->readyOrder($waiter);
        $order->items()->update(['kitchen_state' => 'ready']);

        $b2 = Branch::create(['name' => 'Second', 'code' => 'SEC']);
        [$far] = $this->staffUser('Far', ['staff.app'], branch: $b2);
        $this->actingAs($far, 'sanctum');
        $this->postJson("/api/v1/staff/orders/{$order->id}/served")->assertStatus(404);
        $this->assertSame('ready', $order->items()->first()->kitchen_state);
    }

    public function test_one_login_cannot_be_linked_to_two_employees(): void
    {
        [$u, $e] = $this->staffUser('Anis', ['staff.app']);
        $admin = $this->actingAsUserWithPermissions(['hr.manage'], $this->branch);
        $other = Employee::create(['branch_id' => $this->branch->id, 'name' => 'Other', 'role_title' => 'Cook', 'phone' => '01766660000']);
        $this->putJson("/api/v1/employees/{$other->id}", ['user_id' => $u->id])->assertStatus(422);
        $this->putJson("/api/v1/employees/{$e->id}", ['user_id' => $u->id])->assertOk(); // re-saving the same link is fine
        $this->assertNotNull($admin);
    }
}

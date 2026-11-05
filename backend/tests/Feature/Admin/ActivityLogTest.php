<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    private function actingAsSuperAdmin(?Branch $branch = null): User
    {
        $user = $this->actingAsUserWithPermissions(['audit.view'], $branch);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'Super Admin'])->id);

        return $user;
    }

    private function log(string $action, ?Branch $branch = null, array $extra = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'actor_id' => null,
            'actor_name' => 'Someone',
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => (string) Str::uuid(),
            'branch_id' => $branch?->id,
            'created_at' => now(),
        ], $extra));
    }

    private function at(string $dhakaLocal): Carbon
    {
        return Carbon::parse($dhakaLocal, 'Asia/Dhaka')->setTimezone(config('app.timezone', 'UTC'));
    }

    public function test_a_super_admin_sees_every_entry_newest_first_with_pagination(): void
    {
        $branch = $this->createBranch();
        $this->actingAsSuperAdmin($branch);
        $this->log('order.cancelled', $branch, ['created_at' => $this->at('2026-03-10 10:00:00')]);
        $this->log('user.created', null, ['created_at' => $this->at('2026-03-11 10:00:00')]);

        $response = $this->getJson('/api/v1/activity-logs?per_page=1')->assertStatus(200);

        $response->assertJsonPath('data.0.action', 'user.created')
            ->assertJsonPath('data.0.subjectType', 'User')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.lastPage', 2);
    }

    public function test_a_limited_user_only_sees_entries_for_their_active_branch(): void
    {
        $mine = $this->createBranch();
        $other = $this->createBranch();
        $this->actingAsUserWithPermissions(['audit.view'], $mine);
        $this->log('order.cancelled', $mine);
        $this->log('order.cancelled', $other);
        $this->log('user.created', null); // account administration carries no branch

        $response = $this->getJson('/api/v1/activity-logs')->assertStatus(200);

        $response->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->assertEquals($mine->id, $response->json('data.0.branchId'));
        // They also can't widen the view with a branch filter.
        $this->getJson('/api/v1/activity-logs?branch_id='.$other->id)->assertJsonCount(1, 'data');
    }

    public function test_entries_can_be_filtered_by_action_category_actor_and_search(): void
    {
        $branch = $this->createBranch();
        $this->actingAsSuperAdmin($branch);
        $actorId = (string) Str::uuid();
        $this->log('order.cancelled', $branch, ['actor_name' => 'Karim', 'actor_id' => null]);
        $this->log('order.created', $branch, ['actor_name' => 'Rahim']);
        $this->log('user.created', $branch, ['actor_name' => 'Karim']);

        $this->getJson('/api/v1/activity-logs?action=order.cancelled')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/activity-logs?category=order')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/activity-logs?subject_type=User')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/activity-logs?q=karim')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/activity-logs?actor_id='.$actorId)->assertJsonPath('meta.total', 0);
        // LIKE wildcards in a search are literal text, not patterns.
        $this->getJson('/api/v1/activity-logs?q=%25')->assertJsonPath('meta.total', 0);
    }

    public function test_the_date_filter_follows_the_branch_timezone(): void
    {
        $branch = $this->createBranch(); // defaults to Asia/Dhaka
        $this->actingAsSuperAdmin($branch);
        // 02:00 on 11 March in Dhaka is still 10 March in UTC.
        $this->log('order.cancelled', $branch, ['created_at' => $this->at('2026-03-11 02:00:00')]);

        $this->getJson('/api/v1/activity-logs?from=2026-03-11&to=2026-03-11')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/activity-logs?from=2026-03-10&to=2026-03-10')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/activity-logs?from=2026-03-12&to=2026-03-11')->assertStatus(422);
    }

    public function test_the_actions_endpoint_lists_distinct_actions_the_actor_can_see(): void
    {
        $branch = $this->createBranch();
        $this->actingAsSuperAdmin($branch);
        $this->log('order.cancelled', $branch);
        $this->log('order.cancelled', $branch);
        $this->log('user.created', null);

        $this->getJson('/api/v1/activity-logs/actions')->assertStatus(200)
            ->assertExactJson(['data' => ['order.cancelled', 'user.created']]);
    }

    public function test_the_audit_trail_is_append_only(): void
    {
        $entry = $this->log('order.cancelled');

        try {
            $entry->update(['action' => 'order.created']);
            $this->fail('An audit log entry was modified.');
        } catch (\LogicException) {
            // expected
        }
        try {
            $entry->delete();
            $this->fail('An audit log entry was deleted.');
        } catch (\LogicException) {
            // expected
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $entry->id, 'action' => 'order.cancelled']);
    }

    public function test_viewing_the_activity_log_requires_audit_view(): void
    {
        $this->actingAsUserWithPermissions(['orders.view']);

        $this->getJson('/api/v1/activity-logs')->assertStatus(403);
        $this->getJson('/api/v1/activity-logs/actions')->assertStatus(403);
    }
}

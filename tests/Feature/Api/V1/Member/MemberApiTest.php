<?php

namespace Tests\Feature\Api\V1\Member;

use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The member API at its authorization boundary (Phase 18, P18-B6 / P18-D2).
 *
 * The subscription and points behaviour is already pinned through the shared
 * Actions in `Tests\Feature\Member\MemberSubscriptionTest`; what this file owns
 * is the channel itself: that each route is gated on the permission the
 * operation needs (not a sibling from the same module), that the flag answers
 * 403 here exactly as it does in the browser (D-1), and that the JSON carries
 * the same facts the table renders.
 */
class MemberApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userWith(string ...$permissions): User
    {
        $role = Role::create(['name' => 'api-member-'.uniqid(), 'guard_name' => RoleLookup::guard()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->syncRoles([$role->name]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function tier(string $name = 'Gold', string $roleName = 'gold_member'): MemberTier
    {
        Role::create(['name' => $roleName, 'guard_name' => RoleLookup::guard()]);

        return MemberTier::factory()->create([
            'name' => $name,
            'role_name' => $roleName,
            'price' => 100000,
            'duration_days' => 100,
            'point_multiplier' => 1,
            'sort_order' => 3,
        ]);
    }

    public function test_a_viewer_reads_the_list_with_the_facts_the_table_renders(): void
    {
        $member = Member::factory()->create(['member_number' => 'MBR-000001', 'points_balance' => 2500]);

        Sanctum::actingAs($this->userWith('members.view'));

        $this->getJson(route('api.v1.members.index'))
            ->assertOk()
            ->assertJsonPath('data.members.0.member_number', 'MBR-000001')
            ->assertJsonPath('data.members.0.points_balance', 2500)
            ->assertJsonPath('data.pagination.total', 1);

        $this->getJson(route('api.v1.members.show', $member))
            ->assertOk()
            ->assertJsonPath('data.member.id', $member->id)
            ->assertJsonStructure(['data' => ['member', 'subscriptions', 'points']]);
    }

    public function test_a_reader_cannot_write_anything(): void
    {
        $member = Member::factory()->create();
        $tier = $this->tier();

        Sanctum::actingAs($this->userWith('members.view'));

        $this->postJson(route('api.v1.members.store'), ['name' => 'New', 'username' => 'new', 'email' => 'new@example.com'])->assertForbidden();
        $this->postJson(route('api.v1.members.points', $member), ['delta' => 10, 'reason' => 'x'])->assertForbidden();
        $this->postJson(route('api.v1.members.subscribe', $member), ['member_tier_id' => $tier->id])->assertForbidden();
        $this->deleteJson(route('api.v1.members.destroy', $member))->assertForbidden();

        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('member_subscriptions', 0);
    }

    public function test_a_holder_of_members_update_issues_a_period_and_grants_the_role(): void
    {
        $member = Member::factory()->create();
        $member->user->syncRoles(['member']);
        $tier = $this->tier();

        Sanctum::actingAs($this->userWith('members.update'));

        $this->postJson(route('api.v1.members.subscribe', $member), ['member_tier_id' => $tier->id])
            ->assertCreated()
            ->assertJsonPath('data.subscription.role_name', 'gold_member')
            ->assertJsonPath('data.subscription.status', MemberSubscription::STATUS_ACTIVE);

        $this->assertContains('gold_member', $member->user->fresh()->roles->pluck('name')->all());
    }

    public function test_a_second_live_period_is_refused_the_same_way_as_in_the_browser(): void
    {
        $member = Member::factory()->create();
        $tier = $this->tier();

        Sanctum::actingAs($this->userWith('members.update'));

        $this->postJson(route('api.v1.members.subscribe', $member), ['member_tier_id' => $tier->id])->assertCreated();

        $this->postJson(route('api.v1.members.subscribe', $member), ['member_tier_id' => $tier->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_the_tier_id_is_validated_before_the_action_runs(): void
    {
        $member = Member::factory()->create();

        Sanctum::actingAs($this->userWith('members.update'));

        $this->postJson(route('api.v1.members.subscribe', $member), ['member_tier_id' => 9999])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['member_tier_id'], 'code', 'meta']);
    }

    public function test_cancel_ends_the_period_and_revokes_the_role_it_applied(): void
    {
        $member = Member::factory()->create();
        $member->user->syncRoles(['member']);

        $subscription = MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'status' => MemberSubscription::STATUS_ACTIVE,
            'role_name' => 'gold_member',
            'expires_at' => now()->addDays(30),
        ]);

        Sanctum::actingAs($this->userWith('members.update'));

        $this->postJson(route('api.v1.members.subscriptions.cancel', $subscription))->assertOk();

        $this->assertSame(MemberSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    public function test_points_adjust_returns_the_resulting_balance(): void
    {
        $member = Member::factory()->create(['points_balance' => 100]);

        Sanctum::actingAs($this->userWith('members.update'));

        $this->postJson(route('api.v1.members.points', $member), ['delta' => -40, 'reason' => 'Redemption'])
            ->assertOk()
            ->assertJsonPath('data.points_balance', 60);
    }

    public function test_over_redemption_is_refused_before_any_ledger_row(): void
    {
        $member = Member::factory()->create(['points_balance' => 10]);

        Sanctum::actingAs($this->userWith('members.update'));

        $this->postJson(route('api.v1.members.points', $member), ['delta' => -50, 'reason' => 'Too much'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('member_point_histories', ['member_id' => $member->id]);
    }

    public function test_the_trash_two_step_holds_on_the_api_too(): void
    {
        $member = Member::factory()->create();

        Sanctum::actingAs($this->userWith('members.delete', 'members.restore'));

        // A live profile cannot be restored — that is the trash's way back, not
        // a repeat of an action already taken.
        $this->postJson(route('api.v1.members.restore', $member))->assertNotFound();

        $this->deleteJson(route('api.v1.members.destroy', $member))->assertOk();

        $this->postJson(route('api.v1.members.restore', $member))
            ->assertOk()
            ->assertJsonPath('data.member.id', $member->id);
    }

    public function test_the_bulk_permission_depends_on_the_action_requested(): void
    {
        $member = Member::factory()->create();

        Sanctum::actingAs($this->userWith('members.delete'));

        $this->postJson(route('api.v1.members.bulk-action'), ['action' => 'restore', 'member_ids' => [$member->id]])
            ->assertForbidden();

        $this->postJson(route('api.v1.members.bulk-action'), ['action' => 'delete', 'member_ids' => [$member->id]])
            ->assertOk();

        $this->assertTrue(Member::withTrashed()->find($member->id)->trashed());
    }

    public function test_a_caller_with_no_member_permission_is_refused_on_every_route(): void
    {
        Sanctum::actingAs($this->userWith('users.view'));

        foreach ($this->memberRoutes() as [$method, $name]) {
            $this->json($method, route($name, $this->addresses()))
                ->assertForbidden();
        }
    }

    /**
     * The flag-off half of P18-D2: a switched-off module answers 403 here, not
     * the 404 the brief asked for (D-1), on every member route.
     */
    public function test_a_disabled_flag_is_refused_on_every_route_including_for_a_superadmin(): void
    {
        $superadmin = User::factory()->create(['email_verified_at' => now()]);
        $superadmin->assignRole(SystemRole::SUPERADMIN);

        Sanctum::actingAs($superadmin);

        Feature::deactivate('member_loyalty');
        Feature::flushCache();

        foreach ($this->memberRoutes() as [$method, $name]) {
            $this->json($method, route($name, $this->addresses()))
                ->assertForbidden();
        }
    }

    /**
     * Real rows for the parameterised routes: a missing id answers 404 from the
     * binding before the gate is consulted, which would prove nothing.
     *
     * @return array<string, int>
     */
    private function addresses(): array
    {
        $member = Member::factory()->create();
        $tier = MemberTier::factory()->create();

        return [
            'member' => $member->id,
            'subscription' => MemberSubscription::factory()->create(['member_id' => $member->id, 'member_tier_id' => $tier->id])->id,
            'tier' => $tier->id,
        ];
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function memberRoutes(): array
    {
        return [
            ['GET', 'api.v1.members.index'],
            ['GET', 'api.v1.members.show'],
            ['POST', 'api.v1.members.store'],
            ['POST', 'api.v1.members.points'],
            ['POST', 'api.v1.members.subscribe'],
            ['POST', 'api.v1.members.subscriptions.cancel'],
            ['DELETE', 'api.v1.members.destroy'],
            ['POST', 'api.v1.members.restore'],
            ['DELETE', 'api.v1.members.force-delete'],
            ['POST', 'api.v1.members.activate'],
            ['POST', 'api.v1.members.deactivate'],
            ['POST', 'api.v1.members.bulk-action'],
        ];
    }
}

<?php

namespace Tests\Feature\Api\V1\Member;

use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The tier API at its authorization boundary (Phase 18, P18-B6 / P18-D2).
 *
 * Tier pricing authorizes separately from member profiles on both channels —
 * whoever sets a price should not thereby be able to trash a person, which is
 * the reason the two permission sets exist. The rules a payload must satisfy
 * (slug uniqueness, the assignable-role list, the column bounds) are pinned in
 * `Tests\Feature\Member\MemberTierTest`; this file pins that the API asks the
 * same questions.
 */
class MemberTierApiTest extends TestCase
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
        $role = Role::create(['name' => 'api-tier-'.uniqid(), 'guard_name' => RoleLookup::guard()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->syncRoles([$role->name]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** A tier payload, with the slug the Form Request derives itself. */
    private function tierPayload(string $name = 'Platinum'): array
    {
        return [
            'name' => $name,
            'role_name' => null,
            'price' => 150000,
            'duration_days' => 90,
            'point_multiplier' => 2,
            'sort_order' => 4,
            'is_active' => 1,
        ];
    }

    public function test_a_viewer_reads_tiers_with_the_raw_amount(): void
    {
        $tier = MemberTier::factory()->create(['name' => 'Gold', 'price' => 100000, 'sort_order' => 3]);

        Sanctum::actingAs($this->userWith('member_tiers.view'));

        $this->getJson(route('api.v1.members.tiers.index'))
            ->assertOk()
            ->assertJsonPath('data.tiers.0.name', 'Gold')
            // The amount, not the web list's formatted string: a client computes,
            // a browser reads.
            ->assertJsonPath('data.tiers.0.price', '100000.00')
            ->assertJsonPath('data.tiers.0.id', $tier->id);
    }

    public function test_a_tier_reader_cannot_change_the_price_list(): void
    {
        $tier = MemberTier::factory()->create();

        Sanctum::actingAs($this->userWith('member_tiers.view'));

        $this->postJson(route('api.v1.members.tiers.store'), $this->tierPayload())->assertForbidden();
        $this->putJson(route('api.v1.members.tiers.update', $tier), $this->tierPayload('Renamed'))->assertForbidden();
        $this->deleteJson(route('api.v1.members.tiers.destroy', $tier))->assertForbidden();

        $this->assertDatabaseMissing('member_tiers', ['name' => 'Platinum']);
    }

    public function test_a_member_writer_cannot_change_the_price_list(): void
    {
        // The other direction: `members.update` moves profiles, not pricing.
        $tier = MemberTier::factory()->create();

        Sanctum::actingAs($this->userWith('members.update'));

        $this->postJson(route('api.v1.members.tiers.store'), $this->tierPayload())->assertForbidden();
        $this->deleteJson(route('api.v1.members.tiers.destroy', $tier))->assertForbidden();
    }

    public function test_a_holder_of_tiers_create_can_add_and_edit_a_tier(): void
    {
        Sanctum::actingAs($this->userWith('member_tiers.create', 'member_tiers.update'));

        $response = $this->postJson(route('api.v1.members.tiers.store'), $this->tierPayload())
            ->assertCreated()
            ->assertJsonPath('data.tier.slug', 'platinum')
            ->json('data.tier');

        $this->putJson(route('api.v1.members.tiers.update', $response['id']), $this->tierPayload('Platinum Plus'))
            ->assertOk()
            ->assertJsonPath('data.tier.name', 'Platinum Plus');
    }

    /**
     * The API is the channel the bypass was found on: PUT carries the whole tier,
     * `is_active` included, and `member_tiers.update` alone used to switch a tier
     * off — the permission the Activate / Deactivate routes exist to guard.
     */
    public function test_saving_a_tier_over_the_api_cannot_flip_its_active_state_without_the_deactivate_permission(): void
    {
        $tier = MemberTier::factory()->create(['is_active' => true]);

        Sanctum::actingAs($this->userWith('member_tiers.update'));

        $this->putJson(route('api.v1.members.tiers.update', $tier), array_merge($this->tierPayload(), ['is_active' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');

        $this->assertDatabaseHas('member_tiers', ['id' => $tier->id, 'is_active' => true]);

        // Editing the rest of the same row still works for the same caller.
        $this->putJson(route('api.v1.members.tiers.update', $tier), $this->tierPayload('Gold Two'))
            ->assertOk();

        Sanctum::actingAs($this->userWith('member_tiers.update', 'member_tiers.deactivate'));

        $this->putJson(route('api.v1.members.tiers.update', $tier), array_merge($this->tierPayload('Gold Two'), ['is_active' => 0]))
            ->assertOk();

        $this->assertDatabaseHas('member_tiers', ['id' => $tier->id, 'is_active' => false]);
    }

    public function test_superadmin_cannot_be_posted_as_the_tier_role(): void
    {
        Sanctum::actingAs($this->userWith('member_tiers.create'));

        $this->postJson(route('api.v1.members.tiers.store'), ['role_name' => 'superadmin'] + $this->tierPayload())
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['role_name'], 'code', 'meta']);

        $this->assertDatabaseMissing('member_tiers', ['role_name' => 'superadmin']);
    }

    public function test_a_tier_still_in_use_refuses_the_trash_step(): void
    {
        $member = Member::factory()->create();
        $tier = MemberTier::factory()->create(['name' => 'Gold']);

        MemberSubscription::factory()->create(['member_id' => $member->id, 'member_tier_id' => $tier->id]);

        Sanctum::actingAs($this->userWith('member_tiers.delete'));

        $this->deleteJson(route('api.v1.members.tiers.destroy', $tier))->assertStatus(422);

        $this->assertDatabaseHas('member_tiers', ['id' => $tier->id, 'deleted_at' => null]);
    }

    public function test_the_trash_two_step_holds_on_the_api_too(): void
    {
        $tier = MemberTier::factory()->create();

        Sanctum::actingAs($this->userWith('member_tiers.delete', 'member_tiers.restore', 'member_tiers.force_delete'));

        // The raw-id routes only accept a row already in the trash.
        $this->postJson(route('api.v1.members.tiers.restore', $tier))->assertNotFound();
        $this->deleteJson(route('api.v1.members.tiers.force-delete', $tier))->assertNotFound();

        $this->deleteJson(route('api.v1.members.tiers.destroy', $tier))->assertOk();

        $this->postJson(route('api.v1.members.tiers.restore', $tier))
            ->assertOk()
            ->assertJsonPath('data.tier.id', $tier->id);
    }

    public function test_a_tier_can_be_switched_off_and_back_on(): void
    {
        $tier = MemberTier::factory()->create(['is_active' => true]);

        Sanctum::actingAs($this->userWith('member_tiers.deactivate', 'member_tiers.activate'));

        $this->postJson(route('api.v1.members.tiers.deactivate', $tier))->assertOk();
        $this->assertDatabaseHas('member_tiers', ['id' => $tier->id, 'is_active' => false]);

        $this->postJson(route('api.v1.members.tiers.activate', $tier))->assertOk();
        $this->assertDatabaseHas('member_tiers', ['id' => $tier->id, 'is_active' => true]);
    }

    public function test_the_bulk_permission_depends_on_the_action_requested(): void
    {
        $tier = MemberTier::factory()->create();

        Sanctum::actingAs($this->userWith('member_tiers.delete'));

        $this->postJson(route('api.v1.members.tiers.bulk-action'), ['action' => 'restore', 'tier_ids' => [$tier->id]])
            ->assertForbidden();

        $this->postJson(route('api.v1.members.tiers.bulk-action'), ['action' => 'delete', 'tier_ids' => [$tier->id]])
            ->assertOk();

        $this->assertTrue(MemberTier::onlyTrashed()->find($tier->id)->trashed());
    }

    /**
     * Every tier route with the verb it is declared under — a GET against a
     * POST-only address answers 405, which would prove nothing about the gate.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function tierRoutes(): array
    {
        return [
            ['GET', 'api.v1.members.tiers.index'],
            ['POST', 'api.v1.members.tiers.store'],
            ['PUT', 'api.v1.members.tiers.update'],
            ['DELETE', 'api.v1.members.tiers.destroy'],
            ['POST', 'api.v1.members.tiers.restore'],
            ['DELETE', 'api.v1.members.tiers.force-delete'],
            ['POST', 'api.v1.members.tiers.activate'],
            ['POST', 'api.v1.members.tiers.deactivate'],
            ['POST', 'api.v1.members.tiers.bulk-action'],
        ];
    }

    public function test_a_disabled_flag_is_refused_on_every_tier_route_including_for_a_superadmin(): void
    {
        $superadmin = User::factory()->create(['email_verified_at' => now()]);
        $superadmin->syncRoles(['superadmin']);
        $tier = MemberTier::factory()->create();

        Sanctum::actingAs($superadmin);

        Feature::deactivate('member_loyalty');
        Feature::flushCache();

        foreach ($this->tierRoutes() as [$method, $name]) {
            $this->json($method, route($name, $tier))->assertForbidden();
        }
    }

    public function test_a_caller_with_no_tier_permission_is_refused_on_every_tier_route(): void
    {
        Sanctum::actingAs($this->userWith('members.view'));
        $tier = MemberTier::factory()->create();

        foreach ($this->tierRoutes() as [$method, $name]) {
            $this->json($method, route($name, $tier))->assertForbidden();
        }
    }
}

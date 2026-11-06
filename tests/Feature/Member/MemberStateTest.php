<?php

namespace Tests\Feature\Member;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The Activate / Deactivate pair on the member and tier lists (Phase 18 revision).
 *
 * Two different switches, which is the part worth pinning down:
 *
 * - a tier is a price list. Switching it off stops NEW subscriptions and takes
 *   nothing from anybody already on it.
 * - a member is a person in the programme. Switching them off either pauses the
 *   period they paid for (an administrative break) or forfeits it (a sanction),
 *   decided by the violation flag on the modal, and the answer has to survive in
 *   the audit row: last tier, points, days outstanding, reason.
 *
 * `members.activate` / `members.deactivate` are asserted as permissions of their
 * own: holding `members.update` edits a row, it does not take a person out.
 */
class MemberStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    private function actingAsHolderOf(array $permissions): User
    {
        $role = Role::create(['name' => 'state_clerk', 'guard_name' => RoleLookup::guard()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles([$role->name]);

        $this->actingAs($user);

        return $user;
    }

    /** A member holding a subscription with 30 days still on the clock. */
    private function memberWithDaysLeft(string $number, int $points = 400): Member
    {
        $member = Member::factory()->create(['member_number' => $number, 'points_balance' => $points]);
        MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'member_tier_id' => MemberTier::factory()->create(['name' => 'Gold', 'is_active' => true])->id,
            'starts_at' => now()->subDays(335),
            'expires_at' => now()->addDays(30),
        ]);

        return $member;
    }

    public function test_deactivating_a_tier_switches_it_off_and_records_one_audit_row(): void
    {
        $this->actingAsHolderOf(['member_tiers.deactivate']);

        $tier = MemberTier::factory()->create(['name' => 'Silver', 'is_active' => true]);

        $this->post(route('members.tiers.deactivate', $tier))->assertRedirect(route('members.tiers', ['status' => 'inactive']));

        $this->assertFalse($tier->fresh()->is_active);
        $this->assertSame(1, Activity::where('event', 'member_tier.deactivated')->count());
    }

    public function test_activating_a_tier_switches_it_back_on(): void
    {
        $this->actingAsHolderOf(['member_tiers.activate']);

        $tier = MemberTier::factory()->create(['name' => 'Silver', 'is_active' => false]);

        $this->post(route('members.tiers.activate', $tier))->assertRedirect(route('members.tiers', ['status' => 'active']));

        $this->assertTrue($tier->fresh()->is_active);
        $this->assertTrue(Activity::where('event', 'member_tier.activated')->exists());
    }

    /**
     * The tier row is reachable through the default binding, so a trashed tier
     * 404s rather than being switched back on without a Restore.
     */
    public function test_a_trashed_tier_is_not_available_to_the_state_routes(): void
    {
        $this->actingAsHolderOf(['member_tiers.activate']);

        $tier = MemberTier::factory()->create(['is_active' => false]);
        $tier->delete();

        $this->post(route('members.tiers.activate', $tier))->assertNotFound();
        $this->assertFalse(MemberTier::withTrashed()->find($tier->id)->is_active);
    }

    public function test_a_caller_holding_only_update_is_refused_every_state_route(): void
    {
        $this->actingAsHolderOf(['members.update', 'member_tiers.update']);

        $member = $this->memberWithDaysLeft('MBR-000500');
        $tier = MemberTier::factory()->create();

        $this->post(route('members.deactivate', $member), ['violation' => 0])->assertForbidden();
        $this->post(route('members.activate', $member))->assertForbidden();
        $this->post(route('members.tiers.deactivate', $tier))->assertForbidden();
        $this->post(route('members.tiers.activate', $tier))->assertForbidden();

        $this->assertTrue($member->fresh()->is_active);
    }

    public function test_deactivating_a_member_without_a_violation_pauses_the_period_they_paid_for(): void
    {
        $this->actingAsHolderOf(['members.deactivate']);

        $member = $this->memberWithDaysLeft('MBR-000501');
        $expiresAt = $member->subscriptions()->firstOrFail()->expires_at;

        $this->post(route('members.deactivate', $member), ['violation' => 0])->assertSessionHasNoErrors();

        $subscription = $member->subscriptions()->firstOrFail();

        $this->assertFalse($member->fresh()->is_active);
        $this->assertNotNull($subscription->paused_at);
        $this->assertSame(MemberSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertTrue($subscription->expires_at->equalTo($expiresAt), 'the window moved on a pause');
    }

    public function test_deactivating_a_member_for_a_violation_forfeits_the_period_and_keeps_the_reason(): void
    {
        $this->actingAsHolderOf(['members.deactivate']);

        $member = $this->memberWithDaysLeft('MBR-000502');

        $this->post(route('members.deactivate', $member), [
            'violation' => 1,
            'reason' => 'Chargebacks on three redemptions',
        ])->assertSessionHasNoErrors();

        $subscription = $member->subscriptions()->firstOrFail();

        $this->assertSame(MemberSubscription::STATUS_CANCELLED, $subscription->status);
        $this->assertNull($subscription->paused_at);

        $properties = Activity::where('event', 'member.deactivated')->firstOrFail()->properties->toArray();

        $this->assertTrue($properties['violation']);
        $this->assertSame('Chargebacks on three redemptions', $properties['reason']);
        // What the operator needs on the way back in: the tier they last held,
        // the balance that stayed, and the days that were forfeited.
        $this->assertSame('Gold', $properties['last_tier']);
        $this->assertSame(400, $properties['points_balance']);
        $this->assertSame(30, $properties['remaining_days']);
    }

    public function test_a_violation_without_a_reason_is_refused_and_changes_nothing(): void
    {
        $this->actingAsHolderOf(['members.deactivate']);

        $member = $this->memberWithDaysLeft('MBR-000503');

        $this->post(route('members.deactivate', $member), ['violation' => 1])
            ->assertSessionHasErrors('reason');

        $this->assertTrue($member->fresh()->is_active);
        $this->assertFalse(Activity::where('event', 'member.deactivated')->exists());
    }

    public function test_activating_a_member_hands_the_paused_days_back(): void
    {
        $this->actingAsHolderOf(['members.deactivate', 'members.activate']);

        $member = $this->memberWithDaysLeft('MBR-000504');
        $expiresAt = $member->subscriptions()->firstOrFail()->expires_at;

        $this->post(route('members.deactivate', $member), ['violation' => 0]);

        // Ten days out of the programme must not cost ten days of the tier.
        $this->travel(10)->days();

        $this->post(route('members.activate', $member))->assertSessionHasNoErrors();

        $subscription = $member->subscriptions()->firstOrFail();

        $this->assertTrue($member->fresh()->is_active);
        $this->assertNull($subscription->paused_at);
        $this->assertTrue($subscription->expires_at->equalTo($expiresAt->copy()->addDays(10)));
    }

    public function test_a_deactivated_member_leaves_the_active_tab_and_shows_inactive_on_the_other(): void
    {
        $this->actingAsHolderOf(['members.view', 'members.deactivate', 'members.activate']);

        $member = $this->memberWithDaysLeft('MBR-000505');
        $this->post(route('members.deactivate', $member), ['violation' => 0]);

        $this->get(route('members.index', ['status' => 'active']))
            ->assertDontSee('MBR-000505', false);

        $this->get(route('members.index', ['status' => 'inactive']))
            ->assertSee('MBR-000505', false)
            ->assertSee('INACTIVE')
            ->assertSee(route('members.activate', $member->id), false);
    }

    public function test_a_lapsed_but_undeactivated_profile_stays_on_the_active_tab_with_its_deactivate_button(): void
    {
        $this->actingAsHolderOf(['members.view', 'members.deactivate', 'members.activate']);

        $lapsed = Member::factory()->create(['member_number' => 'MBR-000509', 'is_active' => true]);
        MemberSubscription::factory()->expired()->create([
            'member_id' => $lapsed->id,
            'member_tier_id' => MemberTier::factory()->create(['name' => 'Bronze', 'is_active' => true])->id,
        ]);

        // The tabs follow the profile flag, so the row sits where its one state
        // button points: Active tab, Deactivate button, Deactivate modal.
        $this->get(route('members.index', ['status' => 'active']))
            ->assertSee('MBR-000509', false)
            ->assertSee(route('members.deactivate', $lapsed->id), false);

        $this->get(route('members.index', ['status' => 'inactive']))
            ->assertDontSee('MBR-000509', false);
    }

    public function test_activating_a_member_moves_it_out_of_the_inactive_tab(): void
    {
        $this->actingAsHolderOf(['members.view', 'members.deactivate', 'members.activate']);

        $member = $this->memberWithDaysLeft('MBR-000510');
        $this->post(route('members.deactivate', $member), ['violation' => 1, 'reason' => 'Sanctioned']);

        $this->get(route('members.index', ['status' => 'inactive']))
            ->assertSee(route('members.activate', $member->id), false);

        $this->post(route('members.activate', $member));

        $this->get(route('members.index', ['status' => 'active']))
            ->assertSee('MBR-000510', false);

        $this->get(route('members.index', ['status' => 'inactive']))
            ->assertDontSee('MBR-000510', false);
    }

    public function test_the_lists_render_the_state_buttons_as_icons_with_a_name_on_hover(): void
    {
        $this->actingAsHolderOf(['members.view', 'member_tiers.view', 'members.activate', 'members.deactivate',
            'member_tiers.activate', 'member_tiers.deactivate', 'member_tiers.update']);

        $member = $this->memberWithDaysLeft('MBR-000506');
        $tier = MemberTier::factory()->create(['name' => 'Silver', 'is_active' => true]);

        $this->get(route('members.index', ['status' => 'active']))
            ->assertSee('<i class="fas fa-user-slash"></i>', false)
            ->assertSee('title="Deactivate"', false);

        $this->get(route('members.tiers', ['status' => 'active']))
            ->assertSee('<i class="fas fa-toggle-off"></i>', false)
            ->assertSee('title="Deactivate"', false)
            ->assertSee('title="Edit"', false)
            ->assertDontSee('<i class="bi bi-pencil"></i> Edit', false);

        // The Activate modal must read green like every other activate path:
        // without data-variant the driver falls back to danger.
        MemberTier::factory()->create(['name' => 'Platinum', 'is_active' => false]);
        $this->get(route('members.tiers', ['status' => 'inactive']))
            ->assertSee('title="Activate"', false)
            ->assertSee('data-variant="success"', false);
    }

    public function test_a_deactivated_member_cannot_be_deactivated_twice(): void
    {
        $this->actingAsHolderOf(['members.deactivate']);

        $member = $this->memberWithDaysLeft('MBR-000507');
        $this->post(route('members.deactivate', $member), ['violation' => 0]);

        $this->post(route('members.deactivate', $member), ['violation' => 0]);

        $this->assertSame(1, Activity::where('event', 'member.deactivated')->count());
    }
}

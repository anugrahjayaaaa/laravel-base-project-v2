<?php

namespace Tests\Feature\Member;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Member;
use App\Models\MemberPointHistory;
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
 * The trash tabs, the two-step write routes, and the bulk bar (Phase 18).
 *
 * The bug this starts from: a tier moved to the trash vanished. The list read the
 * default scope, so the row was unreachable — no Restore, no Permanent Delete, no
 * way back. The tabs are what makes a soft delete reversible from the UI, so every
 * tab, every single-row trash action, and the bulk path that loops them is here.
 *
 * `members.delete` / `.restore` / `.force_delete` are asserted as separate
 * permissions: a caller holding `members.manage` edits, and does not remove.
 */
class MemberTrashTest extends TestCase
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
        $role = Role::create(['name' => 'trash_clerk', 'guard_name' => RoleLookup::guard()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles([$role->name]);

        $this->actingAs($user);

        return $user;
    }

    /** A member with a subscription that has not lapsed. */
    private function liveMember(string $number): Member
    {
        $member = Member::factory()->create(['member_number' => $number]);
        MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'member_tier_id' => MemberTier::factory()->create(['is_active' => true])->id,
        ]);

        return $member;
    }

    /** A member whose only subscription has run out. */
    private function lapsedMember(string $number): Member
    {
        $member = Member::factory()->create(['member_number' => $number]);
        MemberSubscription::factory()->expired()->create([
            'member_id' => $member->id,
            'member_tier_id' => MemberTier::factory()->create(['is_active' => true])->id,
        ]);

        return $member;
    }

    public function test_the_trash_tab_shows_a_trashed_profile_and_the_active_tab_hides_it(): void
    {
        $this->actingAsHolderOf(['members.view', 'members.restore']);

        $member = $this->liveMember('MBR-000400');
        $member->delete();

        $this->get(route('members.index', ['status' => 'trashed']))
            ->assertOk()
            ->assertSee('MBR-000400');

        $this->get(route('members.index'))
            ->assertOk()
            ->assertDontSee('MBR-000400', false);
    }

    public function test_the_active_and_inactive_tabs_split_members_by_the_profile_flag(): void
    {
        $this->actingAsHolderOf(['members.view']);

        $this->liveMember('MBR-000401');
        // A lapsed subscription is still an undeactivated profile: it belongs to
        // the Active tab, where its badge (EXPIRED) says what the subscription did.
        $this->lapsedMember('MBR-000402');
        Member::factory()->create(['member_number' => 'MBR-000403', 'is_active' => false]);

        $this->get(route('members.index', ['status' => 'active']))
            ->assertOk()
            ->assertSee('MBR-000401')
            ->assertSee('MBR-000402')
            ->assertDontSee('MBR-000403', false);

        $this->get(route('members.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('MBR-000403')
            ->assertDontSee('MBR-000401', false);
    }

    public function test_the_tier_trash_tab_shows_a_trashed_tier(): void
    {
        $this->actingAsHolderOf(['members.view', 'members.restore', 'members.force_delete']);

        $tier = MemberTier::factory()->create(['name' => 'Bronze', 'slug' => 'bronze']);
        $tier->delete();

        $this->get(route('members.tiers', ['status' => 'trashed']))
            ->assertOk()
            // The row is not merely present, it is reachable: the two trash
            // actions are on it, which is what the tab exists to offer.
            ->assertSee('Bronze')
            ->assertSee('data-action="'.route('members.tiers.restore', $tier->id).'"', escape: false)
            ->assertSee('data-action="'.route('members.tiers.force-delete', $tier->id).'"', escape: false);

        $this->get(route('members.tiers'))
            ->assertOk()
            ->assertDontSee('Bronze', false);
    }

    public function test_a_status_that_is_not_a_tab_is_refused(): void
    {
        $this->actingAsHolderOf(['members.view']);

        // A typo in a bookmarked tab is a redirect back with the error, not a
        // silently unfiltered list.
        $this->get(route('members.index', ['status' => 'everything']))
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->get(route('members.tiers', ['status' => 'everything']))
            ->assertRedirect()
            ->assertSessionHasErrors('status');
    }

    public function test_the_restore_permission_brings_a_trashed_profile_back(): void
    {
        $this->actingAsHolderOf(['members.restore']);

        $member = $this->liveMember('MBR-000403');
        $member->delete();

        $this->post(route('members.restore', $member->id))
            ->assertRedirect(route('members.index', ['status' => 'active']));

        $this->assertFalse(Member::withTrashed()->findOrFail($member->id)->trashed());
        $this->assertTrue(Activity::where('event', 'member.restored')->exists());
    }

    public function test_a_live_profile_cannot_be_restored_or_force_deleted_in_one_click(): void
    {
        $this->actingAsHolderOf(['members.restore', 'members.force_delete']);

        $member = $this->liveMember('MBR-000404');

        $this->post(route('members.restore', $member->id))->assertNotFound();
        $this->delete(route('members.force-delete', $member->id))->assertNotFound();

        $this->assertNotNull(Member::find($member->id), 'a live profile was destroyed from the trash route');
    }

    public function test_the_force_delete_permission_destroys_the_profile_and_leaves_the_account(): void
    {
        $this->actingAsHolderOf(['members.force_delete']);

        $member = $this->liveMember('MBR-000405');
        MemberPointHistory::factory()->create([
            'member_id' => $member->id,
            'delta' => 100,
            'balance_after' => 100,
            'reason' => 'Welcome bonus',
        ]);
        $member->delete();

        $this->delete(route('members.force-delete', $member->id))
            ->assertRedirect(route('members.index', ['status' => 'trashed']));

        $this->assertNull(Member::withTrashed()->find($member->id));
        $this->assertNotNull(User::find($member->user_id), 'the login account is not this screen\'s to delete');
        $this->assertSame(0, MemberSubscription::where('member_id', $member->id)->count(), 'its subscriptions went with it');
        $this->assertSame(0, MemberPointHistory::where('member_id', $member->id)->count(), 'its points history went with it');

        $this->assertTrue(Activity::where('event', 'member.force_deleted')->exists());
    }

    public function test_a_tier_with_subscriptions_on_it_refuses_to_be_force_deleted(): void
    {
        $this->actingAsHolderOf(['members.force_delete']);

        $tier = MemberTier::factory()->create(['name' => 'Gold', 'slug' => 'gold']);
        $this->liveMember('MBR-000406');
        $subscription = MemberSubscription::first();
        $subscription->update(['member_tier_id' => $tier->id]);

        $tier->delete();

        $this->from(route('members.tiers', ['status' => 'trashed']))
            ->delete(route('members.tiers.force-delete', $tier->id))
            ->assertSessionHasErrors('name');

        $this->assertNotNull(MemberTier::withTrashed()->find($tier->id), 'a tier in use was destroyed');
    }

    public function test_manage_alone_is_refused_on_every_trash_route(): void
    {
        $this->actingAsHolderOf(['members.manage']);

        $member = $this->liveMember('MBR-000407');
        $member->delete();

        $this->delete(route('members.destroy', $member))->assertForbidden();
        $this->post(route('members.restore', $member->id))->assertForbidden();
        $this->delete(route('members.force-delete', $member->id))->assertForbidden();
        $this->post(route('members.bulk-action'), ['action' => 'restore', 'member_ids' => [$member->id]])
            ->assertForbidden();

        $this->assertTrue(Member::withTrashed()->findOrFail($member->id)->trashed());
    }

    public function test_bulk_delete_trashes_every_live_profile_in_the_selection(): void
    {
        $this->actingAsHolderOf(['members.delete']);

        $first = $this->liveMember('MBR-000408');
        $second = $this->liveMember('MBR-000409');

        $this->post(route('members.bulk-action'), [
            'action' => 'delete',
            'member_ids' => [$first->id, $second->id],
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertTrue(Member::withTrashed()->findOrFail($first->id)->trashed());
        $this->assertTrue(Member::withTrashed()->findOrFail($second->id)->trashed());
        $this->assertSame(2, Activity::where('event', 'member.deleted')->count(), 'one audit row per subject, not one per batch');
    }

    public function test_bulk_restore_only_touches_the_trashed_rows_in_a_mixed_selection(): void
    {
        $this->actingAsHolderOf(['members.restore']);

        $trashed = $this->liveMember('MBR-000410');
        $trashed->delete();
        $live = $this->liveMember('MBR-000411');

        $this->post(route('members.bulk-action'), [
            'action' => 'restore',
            'member_ids' => [$trashed->id, $live->id],
        ])->assertRedirect();

        $this->assertFalse(Member::withTrashed()->findOrFail($trashed->id)->trashed());
        $this->assertFalse(Member::withTrashed()->findOrFail($live->id)->trashed());
        $this->assertSame(1, Activity::where('event', 'member.restored')->count());
    }

    public function test_bulk_delete_trashes_the_selection_of_tiers(): void
    {
        $this->actingAsHolderOf(['members.delete']);

        $gold = MemberTier::factory()->create(['name' => 'Gold', 'slug' => 'gold']);
        $silver = MemberTier::factory()->create(['name' => 'Silver', 'slug' => 'silver']);

        $this->post(route('members.tiers.bulk-action'), [
            'action' => 'delete',
            'tier_ids' => [$gold->id, $silver->id],
        ])->assertRedirect();

        $this->assertTrue(MemberTier::withTrashed()->findOrFail($gold->id)->trashed());
        $this->assertTrue(Activity::where('event', 'member_tier.deleted')->exists());
    }

    public function test_a_bulk_tier_trash_aborts_when_one_tier_in_the_selection_is_in_use(): void
    {
        $this->actingAsHolderOf(['members.delete']);

        $inUse = MemberTier::factory()->create(['name' => 'Gold', 'slug' => 'gold']);
        $unused = MemberTier::factory()->create(['name' => 'Silver', 'slug' => 'silver']);
        $this->liveMember('MBR-000412');
        MemberSubscription::first()->update(['member_tier_id' => $inUse->id]);

        $this->post(route('members.tiers.bulk-action'), [
            'action' => 'delete',
            'tier_ids' => [$unused->id, $inUse->id],
        ])->assertSessionHasErrors('name');

        $this->assertFalse(MemberTier::withTrashed()->findOrFail($unused->id)->trashed());
        $this->assertFalse(MemberTier::withTrashed()->findOrFail($inUse->id)->trashed());
    }

    public function test_the_bulk_endpoint_refuses_an_action_this_entity_has_no_permission_for(): void
    {
        $this->actingAsHolderOf(['members.view', 'members.delete']);

        $member = $this->liveMember('MBR-000413');

        // `lock` is a shared bulk action (users have `users.lock`), but there is
        // no `members.lock` in the catalogue — so the prefix mapping denies it
        // rather than falling through to whoever holds any members permission.
        $this->post(route('members.bulk-action'), [
            'action' => 'lock',
            'member_ids' => [$member->id],
        ])->assertForbidden();

        $this->assertNotNull(Member::find($member->id));
    }
}

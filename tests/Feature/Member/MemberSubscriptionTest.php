<?php

namespace Tests\Feature\Member;

use App\Actions\V1\Member\MemberShowAction;
use App\Actions\V1\Member\MemberSubscriptionActivateAction;
use App\Actions\V1\Member\MemberSubscriptionCancelAction;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The subscription engine actions (Phase 18, P18-B3).
 *
 * No routes yet — the subscribe/cancel HTTP surface is P18-B6's remaining half —
 * so the Actions are exercised directly. What this pins down:
 *
 * - a period is issued on the live tier only, and never stacks a second active row;
 * - the tier role is copied per row and applied to the `User`, preserving whatever
 *   roles the account already held;
 * - a tier rename after activation cannot orphan or wrongly revoke the applied role;
 * - cancel revokes only the role this row applied, and only when no other live
 *   period still needs it.
 */
class MemberSubscriptionTest extends TestCase
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

    /** A staff user wearing exactly the given permissions. */
    private function actingAsHolderOf(array $permissions): User
    {
        $role = Role::create(['name' => 'member_clerk_'.uniqid(), 'guard_name' => RoleLookup::guard()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles([$role->name]);

        $this->actingAs($user);

        return $user;
    }

    private function role(string $name): Role
    {
        return Role::create(['name' => $name, 'guard_name' => RoleLookup::guard()]);
    }

    /** A member whose account already holds the `member` base role. */
    private function memberHoldingRole(string $baseRole): Member
    {
        $member = Member::factory()->create();
        $member->user->syncRoles([$baseRole]);

        return $member->fresh();
    }

    private function tier(string $name, string $roleName, bool $active = true): MemberTier
    {
        $this->role($roleName);

        return MemberTier::factory()->create([
            'name' => $name,
            'role_name' => $roleName,
            'duration_days' => 30,
            'is_active' => $active,
        ]);
    }

    public function test_activate_issues_an_active_period_and_copies_the_tier_role(): void
    {
        $member = $this->memberHoldingRole('member');
        $tier = $this->tier('Gold', 'gold_member');

        $subscription = app(MemberSubscriptionActivateAction::class)->run($member, $tier);

        $this->assertSame(MemberSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame('gold_member', $subscription->role_name);
        $this->assertTrue($subscription->expires_at->isAfter(now()->addDays(29)));
    }

    public function test_activate_applies_the_tier_role_without_dropping_the_base_role(): void
    {
        $member = $this->memberHoldingRole('member');
        $tier = $this->tier('Gold', 'gold_member');

        app(MemberSubscriptionActivateAction::class)->run($member, $tier);

        $names = $member->user->fresh()->roles->pluck('name')->all();
        $this->assertContains('member', $names);
        $this->assertContains('gold_member', $names);
    }

    public function test_activate_writes_one_subscribed_and_one_role_synced_row(): void
    {
        $member = $this->memberHoldingRole('member');
        $tier = $this->tier('Gold', 'gold_member');

        app(MemberSubscriptionActivateAction::class)->run($member, $tier);

        $this->assertSame(1, Activity::where('event', 'member.subscribed')->count());
        $this->assertSame(1, Activity::where('event', 'member.role_synced')->count());
    }

    public function test_a_roleless_tier_issues_a_period_without_touching_the_account_roles(): void
    {
        $member = $this->memberHoldingRole('member');
        $tier = MemberTier::factory()->create(['name' => 'Bronze', 'role_name' => null, 'duration_days' => 30]);

        $subscription = app(MemberSubscriptionActivateAction::class)->run($member, $tier);

        $this->assertNull($subscription->role_name);
        $this->assertSame(1, Activity::where('event', 'member.subscribed')->count());
        $this->assertSame(0, Activity::where('event', 'member.role_synced')->count());
        $this->assertSame(['member'], $member->user->fresh()->roles->pluck('name')->all());
    }

    public function test_activate_refuses_an_inactive_tier_and_writes_nothing(): void
    {
        $member = $this->memberHoldingRole('member');
        $tier = $this->tier('Bronze', 'bronze_member', active: false);

        $this->expectException(ValidationException::class);

        try {
            app(MemberSubscriptionActivateAction::class)->run($member, $tier);
        } finally {
            $this->assertSame(0, $member->subscriptions()->count());
            $this->assertNotContains('bronze_member', $member->user->fresh()->roles->pluck('name')->all());
        }
    }

    public function test_activate_refuses_a_second_live_period_for_one_member(): void
    {
        $member = $this->memberHoldingRole('member');
        $tier = $this->tier('Gold', 'gold_member');

        app(MemberSubscriptionActivateAction::class)->run($member, $tier);

        $this->expectException(ValidationException::class);
        app(MemberSubscriptionActivateAction::class)->run($member, $this->tier('Silver', 'silver_member'));
    }

    /**
     * A deactivated profile holds a period that is paused, not ended
     * (`MemberDeactivateAction` freezes it for the return). Selling it another
     * live one would leave two `paused_at` rows, and the resume can only hand
     * one of them back — so the second set of paid days would simply vanish.
     */
    public function test_activate_refuses_a_deactivated_member_and_writes_no_period(): void
    {
        $member = $this->memberHoldingRole('member');
        $member->update(['is_active' => false]);
        $tier = $this->tier('Gold', 'gold_member');

        $this->expectException(ValidationException::class);

        try {
            app(MemberSubscriptionActivateAction::class)->run($member->fresh(), $tier);
        } finally {
            $this->assertSame(0, $member->subscriptions()->count());
            $this->assertNotContains('gold_member', $member->user->fresh()->roles->pluck('name')->all());
        }
    }

    /**
     * The refusal is also the reason the form disappears: a null `subscribeUrl`
     * takes the affordance away instead of posting a guaranteed 422.
     */
    public function test_the_detail_view_model_stops_offering_subscribe_to_a_deactivated_profile(): void
    {
        $member = $this->memberHoldingRole('member');

        $this->assertNotNull(app(MemberShowAction::class)->run($member)['subscribeUrl'], 'pre-condition');

        $member->update(['is_active' => false]);

        $this->assertNull(app(MemberShowAction::class)->run($member->fresh())['subscribeUrl']);
    }

    public function test_a_later_tier_rename_does_not_change_the_role_applied_at_activation(): void
    {
        $member = $this->memberHoldingRole('member');
        $tier = $this->tier('Gold', 'gold_member');

        $subscription = app(MemberSubscriptionActivateAction::class)->run($member, $tier);

        // The operator renames the tier AND its role after the sale.
        $tier->update(['name' => 'Gold Legacy', 'role_name' => 'renamed_role']);

        $this->assertSame('gold_member', $subscription->fresh()->role_name);

        app(MemberSubscriptionCancelAction::class)->run($subscription->fresh());

        // Cancel reads the row's copy, so the OLD role is revoked and the new one untouched.
        $names = $member->user->fresh()->roles->pluck('name')->all();
        $this->assertNotContains('gold_member', $names);
        $this->assertContains('member', $names);
    }

    public function test_cancel_revokes_only_the_applied_role_and_audits(): void
    {
        $member = $this->memberHoldingRole('member');
        $tier = $this->tier('Gold', 'gold_member');
        $subscription = app(MemberSubscriptionActivateAction::class)->run($member, $tier);

        app(MemberSubscriptionCancelAction::class)->run($subscription);

        $this->assertSame(MemberSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
        $this->assertNotContains('gold_member', $member->user->fresh()->roles->pluck('name')->all());
        $this->assertSame(1, Activity::where('event', 'member.subscription_cancelled')->count());

        $revoked = Activity::where('event', 'member.role_synced')->latest('id')->firstOrFail();
        $this->assertSame('revoked', $revoked->properties['change']);
    }

    public function test_cancel_keeps_the_role_when_another_live_period_still_uses_it(): void
    {
        $member = $this->memberHoldingRole('member');
        $tier = $this->tier('Gold', 'gold_member');
        $cancel = app(MemberSubscriptionActivateAction::class)->run($member, $tier);

        // A second live row on the same role, inserted directly (activate would
        // refuse it) — the survivor must keep the role.
        $survivor = MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'member_tier_id' => $tier->id,
            'status' => MemberSubscription::STATUS_ACTIVE,
            'role_name' => 'gold_member',
            'expires_at' => now()->addDays(60),
        ]);

        app(MemberSubscriptionCancelAction::class)->run($cancel);

        $this->assertContains('gold_member', $member->user->fresh()->roles->pluck('name')->all());
        $this->assertSame(MemberSubscription::STATUS_ACTIVE, $survivor->fresh()->status);
    }

    public function test_cancel_is_idempotent(): void
    {
        $member = $this->memberHoldingRole('member');
        $tier = $this->tier('Gold', 'gold_member');
        $subscription = app(MemberSubscriptionActivateAction::class)->run($member, $tier);

        app(MemberSubscriptionCancelAction::class)->run($subscription);
        app(MemberSubscriptionCancelAction::class)->run($subscription->fresh());

        $this->assertSame(1, Activity::where('event', 'member.subscription_cancelled')->count());
    }

    public function test_the_subscribe_route_issues_the_period_the_action_would_refuse_to_double(): void
    {
        $this->actingAsHolderOf(['members.update']);

        $member = $this->memberHoldingRole('member');
        $tier = $this->tier('Gold', 'gold_member');

        $this->post(route('members.subscribe', $member), ['member_tier_id' => $tier->id])
            ->assertRedirect();

        $this->assertDatabaseHas('member_subscriptions', [
            'member_id' => $member->id,
            'member_tier_id' => $tier->id,
            'role_name' => 'gold_member',
            'status' => MemberSubscription::STATUS_ACTIVE,
        ]);

        // A second POST is a validation failure, not a second live row.
        $this->from(route('members.show', $member))
            ->post(route('members.subscribe', $member), ['member_tier_id' => $tier->id])
            ->assertSessionHasErrors('member_id');

        $this->assertSame(1, $member->subscriptions()->count());
    }

    public function test_the_cancel_route_ends_the_period_named_by_its_own_url(): void
    {
        $this->actingAsHolderOf(['members.update']);

        $member = $this->memberHoldingRole('member');
        $tier = $this->tier('Gold', 'gold_member');
        $subscription = app(MemberSubscriptionActivateAction::class)->run($member, $tier);

        $this->post(route('members.subscriptions.cancel', $subscription))->assertRedirect();

        $this->assertSame(MemberSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
        $this->assertNotContains('gold_member', $member->user->fresh()->roles->pluck('name')->all());
    }

    public function test_a_member_writer_without_the_update_permission_cannot_move_a_subscription(): void
    {
        // `members.view` opens the detail page; issuing and ending a period is
        // `members.update`, so the read-only holder gets nothing from either POST.
        $this->actingAsHolderOf(['members.view']);

        $member = $this->memberHoldingRole('member');
        $tier = $this->tier('Gold', 'gold_member');

        $this->post(route('members.subscribe', $member), ['member_tier_id' => $tier->id])->assertForbidden();

        $subscription = MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'member_tier_id' => $tier->id,
            'status' => MemberSubscription::STATUS_ACTIVE,
            'role_name' => 'gold_member',
            'expires_at' => now()->addDays(30),
        ]);

        $this->post(route('members.subscriptions.cancel', $subscription))->assertForbidden();

        $this->assertSame(MemberSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertSame(1, $member->subscriptions()->count(), 'the refused subscribe wrote a row');
    }

    public function test_the_detail_page_offers_subscribe_only_while_no_period_is_live(): void
    {
        $this->actingAsHolderOf(['members.view']);

        $member = $this->memberHoldingRole('member');
        $tier = $this->tier('Gold', 'gold_member');

        $viewModel = app(MemberShowAction::class)->run($member);

        $this->assertSame(route('members.subscribe', $member), $viewModel['subscribeUrl']);
        $this->assertSame([$tier->id => $tier->name.' — '.Money::format($tier->price).' / 30 days'], $viewModel['tierOptions']);
        $this->assertSame([], $viewModel['subscriptions'], 'nothing to cancel before the first period');

        $subscription = app(MemberSubscriptionActivateAction::class)->run($member, $tier);

        $viewModel = app(MemberShowAction::class)->run($member->fresh());

        $this->assertNull($viewModel['subscribeUrl'], 'a second live period would only be refused');
        $this->assertSame(route('members.subscriptions.cancel', $subscription), $viewModel['subscriptions'][0]->cancel_url);

        app(MemberSubscriptionCancelAction::class)->run($subscription);

        $viewModel = app(MemberShowAction::class)->run($member->fresh());

        $this->assertNull($viewModel['subscriptions'][0]->cancel_url, 'a cancelled period is already ended');
        $this->assertNotNull($viewModel['subscribeUrl'], 'the member can buy the next period');
    }

    public function test_a_retired_tier_is_not_offered_on_the_detail_page(): void
    {
        $this->actingAsHolderOf(['members.view']);

        $member = $this->memberHoldingRole('member');
        $this->tier('Bronze', 'bronze_member', active: false);

        $this->assertSame([], app(MemberShowAction::class)->run($member)['tierOptions']);
    }
}

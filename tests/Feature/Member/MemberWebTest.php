<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use App\Models\MemberPointHistory;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * Phase 18 read-only slice over HTTP: the member screens answer with real
 * data behind the real gates.
 *
 * The Group A suite renders fixtures; this proves the routes, the `can:`
 * gates, the `feature:member_loyalty` kill switch and the sidebar visibility
 * all agree — which is what D-4 withheld until the routes existed.
 */
class MemberWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The same four the standing authorization suites seed: roles and
        // permissions for the gate, the settings the web middleware stack
        // reads (without them the password-expiry guard redirects every
        // request before the member routes are reached), and the flags.
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        Feature::flushCache();
    }

    private function signIn(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));
        $this->actingAs($user);

        return $user;
    }

    /** @return array{0: Member, 1: MemberTier} */
    private function memberWithHistory(): array
    {
        $tier = MemberTier::factory()->create(['name' => 'Gold', 'slug' => 'gold']);
        $member = Member::factory()->create(['member_number' => 'MBR-000100', 'points_balance' => 250]);
        MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'member_tier_id' => $tier->id,
            'role_name' => 'gold-member',
        ]);
        MemberPointHistory::factory()->create([
            'member_id' => $member->id,
            'delta' => 250,
            'balance_after' => 250,
            'reason' => 'Welcome bonus',
        ]);

        return [$member, $tier];
    }

    public function test_superadmin_reaches_every_member_screen_with_real_data(): void
    {
        [$member] = $this->memberWithHistory();
        $this->signIn('superadmin');

        $this->get('/members')->assertOk()->assertSee('MBR-000100');
        $this->get('/members/tiers')->assertOk()->assertSee('Gold');
        $this->get('/members/dashboard')->assertOk()->assertSee('Points issued');
        $this->get('/members/'.$member->id)->assertOk()->assertSee('Welcome bonus');
    }

    public function test_the_created_member_affordance_is_live_and_the_unbuilt_ones_are_not(): void
    {
        [$member] = $this->memberWithHistory();
        $this->signIn('superadmin');

        // A superadmin passes every Gate — so the only proof the guards are
        // URL-driven, not permission-driven, is taken at full privilege.
        $this->get('/members')
            ->assertOk()
            ->assertSee('Create Member')
            ->assertDontSee('data-bulk-route', false);
        $this->get('/members/tiers')->assertOk()->assertSee('Create Tier');
        $this->get('/members/dashboard')->assertOk()->assertDontSee('Notify Expiring Members', false);
        // The points form ships; the subscribe and delete forms still do not.
        $this->get('/members/'.$member->id)->assertOk()->assertSee('Adjust Points');
        $this->get('/members/'.$member->id)->assertOk()->assertDontSee('member_tier_id');
    }

    public function test_a_viewer_without_members_view_is_refused_on_every_screen(): void
    {
        [$member] = $this->memberWithHistory();
        $this->signIn('staff');

        $this->get('/members')->assertForbidden();
        $this->get('/members/tiers')->assertForbidden();
        $this->get('/members/dashboard')->assertForbidden();
        $this->get('/members/'.$member->id)->assertForbidden();
    }

    public function test_the_flag_refuses_even_a_superadmin(): void
    {
        $this->signIn('superadmin');
        Feature::deactivate('member_loyalty');

        $this->get('/members')->assertForbidden();
        $this->get('/members/tiers')->assertForbidden();
        $this->get('/members/dashboard')->assertForbidden();
    }

    public function test_the_sidebar_offers_members_only_to_a_viewer_who_may(): void
    {
        // Every label, not just the first: an entry pointing at a route name
        // nobody registered is dropped silently, and `GateDUiGatingTest` only
        // proved it after three entries shipped rather than two.
        $this->signIn('staff');
        $this->get('/dashboard')->assertOk()
            ->assertDontSee('Members')
            ->assertDontSee('Tiers')
            ->assertDontSee('Membership Dashboard');

        $this->signIn('superadmin');
        $this->get('/dashboard')->assertOk()
            ->assertSee('Members')
            ->assertSee('Tiers')
            ->assertSee('Membership Dashboard');
    }
}

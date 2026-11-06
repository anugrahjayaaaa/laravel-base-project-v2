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
 * The member-facing half of /dashboard: an account with a loyalty profile reads
 * its own tier, expiry and ledger there instead of the application status card.
 *
 * No new route — `/dashboard` is where login lands and holds no `can:` gate,
 * which is the only route a zero-permission `member` account can reach.
 */
class MemberSelfDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        Feature::flushCache();
    }

    private function signInAsMember(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find('member'));
        $this->actingAs($user);

        return $user;
    }

    private function memberFor(User $user): Member
    {
        return Member::factory()->create(['user_id' => $user->id]);
    }

    public function test_a_member_sees_their_tier_expiry_and_points_on_the_dashboard(): void
    {
        Feature::activate('member_loyalty');
        $user = $this->signInAsMember();
        $member = $this->memberFor($user);

        MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'member_tier_id' => MemberTier::factory()->create(['name' => 'Gold'])->id,
            'status' => MemberSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addDays(30),
        ]);
        MemberPointHistory::factory()->create([
            'member_id' => $member->id,
            'delta' => 250,
            'balance_after' => 250,
            'reason' => 'Welcome bonus',
        ]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee($member->member_number)
            ->assertSee('Gold')
            ->assertSee(now()->addDays(30)->format('Y-m-d'))
            ->assertSee('Welcome bonus')
            ->assertSee('+250')
            ->assertDontSee('Application Status');
    }

    public function test_the_dashboard_shows_only_the_signed_in_member_own_rows(): void
    {
        Feature::activate('member_loyalty');
        $user = $this->signInAsMember();
        $member = $this->memberFor($user);

        $other = Member::factory()->create();
        MemberPointHistory::factory()->create([
            'member_id' => $other->id,
            'reason' => 'Someone else ledger entry',
        ]);
        MemberPointHistory::factory()->create([
            'member_id' => $member->id,
            'reason' => 'My own ledger entry',
        ]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('My own ledger entry')
            ->assertDontSee('Someone else ledger entry')
            ->assertDontSee($other->member_number);
    }

    public function test_a_member_without_a_subscription_or_ledger_gets_empty_states(): void
    {
        Feature::activate('member_loyalty');
        $member = $this->memberFor($this->signInAsMember());

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee($member->member_number)
            ->assertSee('No active period.')
            ->assertSee('No points recorded yet.');
    }

    public function test_the_member_dashboard_is_not_shown_while_the_feature_flag_is_off(): void
    {
        Feature::deactivate('member_loyalty');
        $member = $this->memberFor($this->signInAsMember());

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Application Status')
            ->assertDontSee($member->member_number);
    }

    public function test_a_staff_user_still_gets_the_application_dashboard(): void
    {
        Feature::activate('member_loyalty');
        $staff = User::factory()->create(['email_verified_at' => now()]);
        $staff->assignRole(RoleLookup::find('superadmin'));
        $this->actingAs($staff);

        $this->get('/dashboard')->assertOk()->assertSee('Application Status');
    }
}

<?php

namespace Tests\Feature\Member;

use App\Jobs\MemberSubscriptionExpirySweepJob;
use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\Role;
use App\Models\RoleLookup;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The scheduled subscription-expiry sweep (Phase 18, P18-C1).
 *
 * Pins the sweep's contract: lapsed periods expire and revoke ONLY the role
 * their own row applied, paused periods are untouched, a second run claims
 * zero rows, and the flag stops the whole thing — the same kill-switch rule
 * the browser obeys. The `member.expired` audit carries no logged-in actor;
 * the SYSTEM marker in properties is what identifies the cron tick (P14-C).
 *
 * SQLite caveat inherited from D-5: this proves state arithmetic, not the row
 * lock a concurrent MySQL run would need — the sweep is the only writer here
 * and `chunkById` rows leave the candidate set the moment they are updated.
 */
class MemberExpirySweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    private function tier(string $roleName): MemberTier
    {
        Role::create(['name' => $roleName, 'guard_name' => RoleLookup::guard()]);

        return MemberTier::factory()->create(['role_name' => $roleName]);
    }

    /** A member whose account holds the base role plus the tier role. */
    private function memberIn(MemberTier $tier): Member
    {
        $member = Member::factory()->create();
        $member->user->syncRoles(['member', $tier->role_name]);

        return $member->fresh();
    }

    private function period(
        Member $member,
        MemberTier $tier,
        string $expiresAt,
        ?string $pausedAt = null,
    ): MemberSubscription {
        return MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'member_tier_id' => $tier->id,
            'status' => MemberSubscription::STATUS_ACTIVE,
            'role_name' => $tier->role_name,
            'expires_at' => $expiresAt,
            'paused_at' => $pausedAt,
        ]);
    }

    public function test_the_sweep_expires_lapsed_periods_and_revokes_the_role_they_applied(): void
    {
        $tier = $this->tier('gold_member');
        $member = $this->memberIn($tier);
        $period = $this->period($member, $tier, now()->subDay());

        MemberSubscriptionExpirySweepJob::dispatchSync();

        $this->assertSame(MemberSubscription::STATUS_EXPIRED, $period->fresh()->status);
        $this->assertFalse($member->user->fresh()->hasRole('gold_member'));
        $this->assertTrue($member->user->fresh()->hasRole('member'), 'the base role must survive');

        $audit = Activity::where('event', 'member.expired')->sole();
        $this->assertSame('SYSTEM', $audit->properties['causer']);
        $this->assertTrue($audit->properties['role_revoked']);
        $this->assertSame($period->id, $audit->properties['subscription_id']);
    }

    public function test_a_role_another_live_period_still_needs_survives_the_sweep(): void
    {
        $tier = $this->tier('gold_member');
        $member = $this->memberIn($tier);
        $this->period($member, $tier, now()->subDay());
        $live = $this->period($member, $tier, now()->addMonth());

        MemberSubscriptionExpirySweepJob::dispatchSync();

        $this->assertTrue($member->user->fresh()->hasRole('gold_member'));
        $this->assertTrue($live->fresh()->isLive());
        $this->assertFalse(Activity::where('event', 'member.expired')->sole()->properties['role_revoked']);
    }

    public function test_paused_periods_are_left_alone(): void
    {
        $tier = $this->tier('gold_member');
        $member = $this->memberIn($tier);
        $period = $this->period($member, $tier, now()->subDay(), pausedAt: now()->subDays(3));

        MemberSubscriptionExpirySweepJob::dispatchSync();

        $this->assertSame(MemberSubscription::STATUS_ACTIVE, $period->fresh()->status);
        $this->assertTrue($member->user->fresh()->hasRole('gold_member'));
        $this->assertSame(0, Activity::where('event', 'member.expired')->count());
    }

    public function test_a_second_run_claims_zero_rows(): void
    {
        $tier = $this->tier('gold_member');
        $member = $this->memberIn($tier);
        $this->period($member, $tier, now()->subDay());

        MemberSubscriptionExpirySweepJob::dispatchSync();
        MemberSubscriptionExpirySweepJob::dispatchSync();

        $this->assertSame(1, Activity::where('event', 'member.expired')->count());
    }

    public function test_periods_still_inside_their_window_are_not_touched(): void
    {
        $tier = $this->tier('gold_member');
        $member = $this->memberIn($tier);
        $period = $this->period($member, $tier, now()->addWeek());

        MemberSubscriptionExpirySweepJob::dispatchSync();

        $this->assertSame(MemberSubscription::STATUS_ACTIVE, $period->fresh()->status);
        $this->assertTrue($member->user->fresh()->hasRole('gold_member'));
    }

    public function test_the_flag_stops_the_sweep_entirely(): void
    {
        $tier = $this->tier('gold_member');
        $member = $this->memberIn($tier);
        $period = $this->period($member, $tier, now()->subDay());

        Feature::deactivate('member_loyalty');
        MemberSubscriptionExpirySweepJob::dispatchSync();

        $this->assertSame(MemberSubscription::STATUS_ACTIVE, $period->fresh()->status);
        $this->assertSame(0, Activity::where('event', 'member.expired')->count());
    }

    /**
     * The chunk carries the relations its per-row Action reads.
     *
     * A dropped `with()` in `MemberSubscriptionExpirySweepJob` is invisible to
     * every assertion above — the run still expires the right rows and revokes
     * the right role, it just pays four selects per period to do it. So the
     * guard is not an assertion about the result, it is the framework's own
     * lazy-load violation: with the guard on, the run either completes silently
     * (loaded) or throws (not loaded).
     */
    public function test_the_sweep_carries_every_relation_its_per_row_work_reads(): void
    {
        $tier = $this->tier('gold_member');
        $this->period($this->memberIn($tier), $tier, now()->subDay());
        $this->period($this->memberIn($tier), $tier, now()->subDays(2));
        $this->period($this->memberIn($tier), $tier, now()->subDays(3));

        Model::preventLazyLoading(true);

        try {
            MemberSubscriptionExpirySweepJob::dispatchSync();
        } finally {
            Model::preventLazyLoading(false);
        }

        $this->assertSame(3, MemberSubscription::query()->where('status', MemberSubscription::STATUS_EXPIRED)->count());
    }
}

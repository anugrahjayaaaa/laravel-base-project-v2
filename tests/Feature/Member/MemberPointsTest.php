<?php

namespace Tests\Feature\Member;

use App\Actions\V1\Member\MemberExpirePointsAction;
use App\Models\Member;
use App\Models\MemberPointHistory;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Points: the manual adjustment (the earning rule applied to a purchase
 * amount), the guard against a negative balance, and the annual expiry the
 * settings date drives (Phase 18, P18-B4 + O-3).
 *
 * What SQLite cannot prove (D-5): `MemberAdjustPointsAction` re-reads the balance
 * under `lockForUpdate()`, and SQLite ignores `FOR UPDATE`, so these tests cover
 * the arithmetic and the refusal — the row lock itself is deferred runtime
 * evidence needing a MySQL two-session probe (`docs/planning/phase-18 § 3 D-5`).
 */
class MemberPointsTest extends TestCase
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

    private function manager(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('admin');
        // The web form is gone (owner 2026-11-06, points move at the Phase 19
        // checkout); the same Action, Request and gates stay reachable here.
        Sanctum::actingAs($user);

        return $user;
    }

    private function member(int $balance = 1000): Member
    {
        return Member::factory()->create(['member_number' => 'MBR-000300', 'points_balance' => $balance]);
    }

    public function test_a_manual_deduction_writes_the_ledger_and_the_balance(): void
    {
        $this->manager();
        $member = $this->member(1000);

        $this->postJson(route('api.v1.members.points', $member), ['delta' => -300, 'reason' => 'Wrong entry'])
            ->assertSuccessful();

        $this->assertSame(700, $member->fresh()->points_balance);

        $entry = MemberPointHistory::sole();
        $this->assertSame(-300, $entry->delta);
        $this->assertSame(700, $entry->balance_after);
        $this->assertSame('Wrong entry', $entry->reason);
        $this->assertTrue(
            Activity::where('event', 'member.points_adjusted')->exists(),
            'a balance change with no trail is the failure mode the ledger exists to prevent'
        );
    }

    public function test_a_purchase_amount_earns_by_the_configured_rule(): void
    {
        $this->manager();
        SystemSetting::set('member_points_earn_amount', '50000');
        SystemSetting::set('member_points_earn_value', '1000');

        $member = $this->member(0);

        $this->postJson(route('api.v1.members.points', $member), ['purchase_amount' => 175000, 'reason' => 'Counter sale'])
            ->assertSuccessful();

        // 175.000 / 50.000 = 3 whole steps, the partial remainder earns nothing.
        $this->assertSame(3000, $member->fresh()->points_balance);
        $this->assertSame(3000, MemberPointHistory::sole()->delta);
    }

    public function test_deducting_more_than_the_balance_is_refused(): void
    {
        $this->manager();
        $member = $this->member(200);

        $this->postJson(route('api.v1.members.points', $member), ['delta' => -500, 'reason' => 'Too much'])
            ->assertJsonValidationErrors('delta');

        $this->assertSame(200, $member->fresh()->points_balance);
        $this->assertSame(0, MemberPointHistory::count(), 'a refused write must leave no ledger row');
    }

    public function test_points_and_purchase_amount_are_mutually_exclusive(): void
    {
        $this->manager();
        $member = $this->member();

        $this->postJson(route('api.v1.members.points', $member), [
            'delta' => 100,
            'purchase_amount' => 50000,
            'reason' => 'Both',
        ])->assertJsonValidationErrors('delta');

        $this->postJson(route('api.v1.members.points', $member), ['reason' => 'Neither'])
            ->assertJsonValidationErrors('delta');
    }

    public function test_a_viewer_cannot_adjust_points_and_the_flag_closes_the_route(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::create(['name' => 'viewer_only', 'guard_name' => RoleLookup::guard()]);
        $role->syncPermissions(['members.view']);
        $user->syncRoles([$role->name]);
        Sanctum::actingAs($user);

        $member = $this->member();

        $this->postJson(route('api.v1.members.points', $member), ['delta' => 10, 'reason' => 'Nope'])->assertForbidden();

        Feature::deactivate('member_loyalty');
        Feature::flushCache();

        $this->manager();
        $this->postJson(route('api.v1.members.points', $member), ['delta' => 10, 'reason' => 'Flag off'])->assertForbidden();
    }

    public function test_the_annual_reset_zeroes_balances_and_moves_the_date_a_year_forward(): void
    {
        SystemSetting::set('member_points_expiry_date', now()->toDateString());

        $member = $this->member(4000);
        MemberPointHistory::create([
            'member_id' => $member->id,
            'delta' => 4000,
            'balance_after' => 4000,
            'reason' => 'Earlier earn',
        ]);

        $this->assertSame(1, (new MemberExpirePointsAction())->run());

        $this->assertSame(0, $member->fresh()->points_balance);

        $reset = MemberPointHistory::orderBy('id')->get()->last();
        $this->assertSame(-4000, $reset->delta);
        $this->assertSame(0, $reset->balance_after);
        $this->assertSame('Annual points expiry', $reset->reason);
        $this->assertTrue(Activity::where('event', 'member.points_expired')->exists());

        // The balance is the ledger, not a second source of truth.
        $this->assertSame(
            Member::find($member->id)->points_balance,
            (int) MemberPointHistory::where('member_id', $member->id)->sum('delta'),
            'a ledger that no longer sums to the balance cannot be reconciled'
        );

        $this->assertSame(
            now()->addYear()->toDateString(),
            SystemSetting::getString('member_points_expiry_date'),
            'an annual reset that left the date in the past would fire every day'
        );
    }

    public function test_no_date_means_no_reset_and_a_future_date_waits(): void
    {
        SystemSetting::set('member_points_expiry_date', '');
        $member = $this->member(4000);

        $this->assertSame(0, (new MemberExpirePointsAction())->run());
        $this->assertSame(4000, $member->fresh()->points_balance);

        SystemSetting::set('member_points_expiry_date', now()->addDays(30)->toDateString());

        $this->assertSame(0, (new MemberExpirePointsAction())->run());
        $this->assertSame(4000, $member->fresh()->points_balance);
    }
}

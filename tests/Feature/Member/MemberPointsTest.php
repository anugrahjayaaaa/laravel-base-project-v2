<?php

namespace Tests\Feature\Member;

use App\Actions\V1\Member\ExpireMemberPointsAction;
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
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Points: the manual adjustment (the earning rule applied to a purchase
 * amount), the guard against a negative balance, and the annual expiry the
 * settings date drives (Phase 18, P18-B4 + O-3).
 */
class MemberPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The POST assertions are about the gate and the ledger, so the CSRF
        // middleware is opted out the same way the standing role suites do it.
        $this->withoutMiddleware(VerifyCsrfToken::class);

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
        $this->actingAs($user);

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

        $this->post(route('members.points', $member), ['delta' => -300, 'reason' => 'Wrong entry'])
            ->assertSessionHasNoErrors();

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

        $this->post(route('members.points', $member), ['purchase_amount' => 175000, 'reason' => 'Counter sale'])
            ->assertSessionHasNoErrors();

        // 175.000 / 50.000 = 3 whole steps, the partial remainder earns nothing.
        $this->assertSame(3000, $member->fresh()->points_balance);
        $this->assertSame(3000, MemberPointHistory::sole()->delta);
    }

    public function test_deducting_more_than_the_balance_is_refused(): void
    {
        $this->manager();
        $member = $this->member(200);

        $this->post(route('members.points', $member), ['delta' => -500, 'reason' => 'Too much'])
            ->assertSessionHasErrors('delta');

        $this->assertSame(200, $member->fresh()->points_balance);
        $this->assertSame(0, MemberPointHistory::count(), 'a refused write must leave no ledger row');
    }

    public function test_points_and_purchase_amount_are_mutually_exclusive(): void
    {
        $this->manager();
        $member = $this->member();

        $this->post(route('members.points', $member), [
            'delta' => 100,
            'purchase_amount' => 50000,
            'reason' => 'Both',
        ])->assertSessionHasErrors('delta');

        $this->post(route('members.points', $member), ['reason' => 'Neither'])
            ->assertSessionHasErrors('delta');
    }

    public function test_a_viewer_cannot_adjust_points_and_the_flag_closes_the_route(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::create(['name' => 'viewer_only', 'guard_name' => RoleLookup::guard()]);
        $role->syncPermissions(['members.view']);
        $user->syncRoles([$role->name]);
        $this->actingAs($user);

        $member = $this->member();

        $this->post(route('members.points', $member), ['delta' => 10, 'reason' => 'Nope'])->assertForbidden();

        Feature::deactivate('member_loyalty');
        Feature::flushCache();

        $this->manager();
        $this->post(route('members.points', $member), ['delta' => 10, 'reason' => 'Flag off'])->assertForbidden();
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

        $this->assertSame(1, (new ExpireMemberPointsAction())->run());

        $this->assertSame(0, $member->fresh()->points_balance);

        $reset = MemberPointHistory::orderBy('id')->get()->last();
        $this->assertSame(-4000, $reset->delta);
        $this->assertSame(0, $reset->balance_after);
        $this->assertSame('Annual points expiry', $reset->reason);
        $this->assertTrue(Activity::where('event', 'member.points_expired')->exists());

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

        $this->assertSame(0, (new ExpireMemberPointsAction())->run());
        $this->assertSame(4000, $member->fresh()->points_balance);

        SystemSetting::set('member_points_expiry_date', now()->addDays(30)->toDateString());

        $this->assertSame(0, (new ExpireMemberPointsAction())->run());
        $this->assertSame(4000, $member->fresh()->points_balance);
    }
}

<?php

namespace Tests\Feature\Member;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Member;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Move a member profile to the trash (Phase 18 P18-B6 write path).
 *
 * The profile goes, the login account stays: `MemberDeleteAction` trashes only
 * the loyalty row, and the account is deleted on the users screen.
 */
class MemberDeleteTest extends TestCase
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
        Feature::flushCache();
    }

    private function actingAsHolderOf(array $permissions): User
    {
        $role = Role::create(['name' => 'member_clerk', 'guard_name' => RoleLookup::guard()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles([$role->name]);

        $this->actingAs($user);

        return $user;
    }

    public function test_a_manager_trashes_the_profile_and_the_account_stays_alive(): void
    {
        $this->actingAsHolderOf(['members.manage']);

        $member = Member::factory()->create(['member_number' => 'MBR-000300']);

        $this->delete(route('members.destroy', $member))->assertRedirect(route('members.index'));

        $this->assertNull(Member::find($member->id), 'a trashed profile must leave the default scope');
        $this->assertTrue(Member::withTrashed()->find($member->id)->trashed());
        $this->assertNotNull(User::find($member->user_id), 'the login account is not deleted here');

        $this->assertTrue(Activity::where('event', 'member.deleted')->exists());
    }

    public function test_a_viewer_cannot_trash_a_profile(): void
    {
        $this->actingAsHolderOf(['members.view']);

        $member = Member::factory()->create();

        $this->delete(route('members.destroy', $member))->assertForbidden();

        $this->assertNotNull(Member::find($member->id));
        $this->assertFalse(Activity::where('event', 'member.deleted')->exists());
    }

    public function test_the_flag_switches_delete_off(): void
    {
        $this->actingAsHolderOf(['members.manage']);

        $member = Member::factory()->create();

        Feature::deactivate('member_loyalty');
        Feature::flushCache();

        $this->delete(route('members.destroy', $member))->assertForbidden();

        $this->assertNotNull(Member::find($member->id));
    }

    public function test_a_delete_for_a_profile_already_in_the_trash_writes_no_audit_row(): void
    {
        $this->actingAsHolderOf(['members.manage']);

        $member = Member::factory()->create();
        $member->delete();

        $this->delete(route('members.destroy', $member))->assertRedirect(route('members.index'));

        $this->assertSame(0, Activity::where('event', 'member.deleted')->count(), 'nothing changed, so the trail must not claim it did');
    }
}

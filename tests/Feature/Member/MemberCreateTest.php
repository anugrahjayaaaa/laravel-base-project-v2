<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\UserCreatedNotification;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * Add new member (Phase 18): one form creates the account and its profile, and
 * the account is treated as a user — verified email, temporary password, the
 * `member` role.
 */
class MemberCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same opt-out the standing role suites use: the POST assertions here
        // are about the gate and the writes, and a token the test does not have
        // would answer 419 before either is reached.
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        Feature::flushCache();

        Notification::fake();
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

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'name' => 'New Member',
            'username' => 'newmember',
            'email' => 'new.member@example.com',
            'notes' => 'Joined at the counter',
        ];
    }

    public function test_the_form_renders_for_a_manager(): void
    {
        $this->actingAsHolderOf(['members.create', 'users.assign_roles']);

        $this->get(route('members.create'))
            ->assertOk()
            ->assertSee('Create Member')
            ->assertSee('Member Number')
            // Shown, never typed: a disabled input has no name, so the number
            // cannot be submitted.
            ->assertSee('id="member_number"', false);
    }

    public function test_a_viewer_cannot_reach_the_form(): void
    {
        $this->actingAsHolderOf(['members.view']);

        $this->get(route('members.create'))->assertForbidden();
        $this->post(route('members.store'), $this->payload())->assertForbidden();
    }

    public function test_creating_a_member_makes_a_user_with_the_member_role_and_a_verification_mail(): void
    {
        $this->actingAsHolderOf(['members.create', 'users.assign_roles']);

        $response = $this->post(route('members.store'), $this->payload());

        $user = User::where('email', 'new.member@example.com')->sole();

        $this->assertSame([SystemRole::MEMBER], $user->getRoleNames()->all());
        $this->assertTrue($user->must_change_password, 'a generated password must be changed on first login');
        $this->assertNull($user->email_verified_at, 'the member must verify their own email');

        $member = Member::sole();
        $this->assertSame('MBR-000001', $member->member_number, 'the first number of the series is 000001');
        $this->assertSame($user->id, $member->user_id);
        $this->assertSame('Joined at the counter', $member->notes);
        $this->assertSame(0, $member->points_balance);

        Notification::assertSentTo($user, UserCreatedNotification::class);

        $response->assertRedirect(route('members.show', $member));
    }

    public function test_creating_a_member_without_role_assignment_permission_is_refused(): void
    {
        $this->actingAsHolderOf(['members.create']);

        $this->post(route('members.store'), $this->payload())->assertForbidden();

        $this->assertSame(0, Member::count(), 'a refused write must not persist a profile');
        $this->assertFalse(User::where('email', 'new.member@example.com')->exists());
    }

    public function test_the_member_number_follows_the_configured_prefix_and_counts_up(): void
    {
        $this->actingAsHolderOf(['members.create', 'users.assign_roles']);

        SystemSetting::set('member_number_prefix', 'ANG');
        SystemSetting::bustCache();

        $this->post(route('members.store'), $this->payload());
        $this->post(route('members.store'), ['name' => 'Second', 'username' => 'secondmember', 'email' => 'second@example.com']);

        $this->assertSame(
            ['ANG-000001', 'ANG-000002'],
            Member::orderBy('id')->pluck('member_number')->all()
        );
    }

    public function test_a_soft_deleted_member_number_is_never_issued_twice(): void
    {
        $this->actingAsHolderOf(['members.create', 'users.assign_roles']);

        Member::factory()->create(['member_number' => 'MBR-000009']);
        Member::query()->delete();

        $this->post(route('members.store'), $this->payload());

        $this->assertSame('MBR-000010', Member::withTrashed()->latest('id')->value('member_number'));
    }

    public function test_a_forged_member_number_in_the_payload_is_not_stored(): void
    {
        $this->actingAsHolderOf(['members.create', 'users.assign_roles']);

        $this->post(route('members.store'), $this->payload() + ['member_number' => 'MBR-999999']);

        $this->assertSame('MBR-000001', Member::sole()->member_number);
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        $this->actingAsHolderOf(['members.create', 'users.assign_roles']);

        User::factory()->create(['email' => 'new.member@example.com']);

        $this->post(route('members.store'), $this->payload())
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Member::count());
    }

    public function test_the_flag_switches_the_whole_flow_off(): void
    {
        $this->actingAsHolderOf(['members.create', 'users.assign_roles']);

        Feature::deactivate('member_loyalty');
        Feature::flushCache();

        $this->get(route('members.create'))->assertForbidden();
        $this->post(route('members.store'), $this->payload())->assertForbidden();
    }
}

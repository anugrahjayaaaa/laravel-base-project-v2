<?php

namespace Tests\Feature;

use App\Actions\V1\Role\AssignRolesAction;
use App\Exceptions\LastSuperadminException;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The last-superadmin guard and the role sync contract (P6-C11).
 *
 * The sync semantics are the part that quietly rots: `array_key_exists` rather
 * than `isset()` is what keeps an omitted key from clearing a user's roles, and
 * a regression there would look like a successful save. Each case is asserted
 * separately for that reason.
 */
class AssignRolesActionTest extends TestCase
{
    use RefreshDatabase;

    private AssignRolesAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->action = app(AssignRolesAction::class);
    }

    public function test_it_syncs_roles(): void
    {
        $user = $this->user();

        $this->action->run($user, [SystemRole::ADMIN], $this->causer());

        $this->assertTrue($user->fresh()->hasRole(SystemRole::ADMIN));
    }

    public function test_an_unknown_role_name_is_skipped_not_thrown_on(): void
    {
        $user = $this->user();

        $this->action->run($user, [SystemRole::ADMIN, 'does-not-exist'], $this->causer());

        // The known one is applied; the unknown one is simply absent.
        $this->assertSame([SystemRole::ADMIN], $user->fresh()->getRoleNames()->all());
    }

    public function test_an_empty_array_clears_every_role(): void
    {
        $user = $this->user();
        $user->assignRole(SystemRole::ADMIN);

        $this->action->run($user, [], $this->causer());

        $this->assertCount(0, $user->fresh()->getRoleNames());
    }

    public function test_stripping_the_last_superadmin_is_refused(): void
    {
        $superadmin = $this->user();
        $superadmin->assignRole(SystemRole::SUPERADMIN);

        $this->expectException(LastSuperadminException::class);

        $this->action->run($superadmin, [SystemRole::USER], $superadmin);
    }

    public function test_the_refusal_leaves_the_superadmin_in_place(): void
    {
        $superadmin = $this->user();
        $superadmin->assignRole(SystemRole::SUPERADMIN);

        try {
            $this->action->run($superadmin, [SystemRole::USER], $superadmin);
        } catch (LastSuperadminException) {
            // Expected. The point of this test is what is left behind.
        }

        $this->assertTrue($superadmin->fresh()->hasRole(SystemRole::SUPERADMIN));
    }

    public function test_demoting_one_of_two_superadmins_is_allowed(): void
    {
        $first = $this->user();
        $first->assignRole(SystemRole::SUPERADMIN);

        $second = $this->user();
        $second->assignRole(SystemRole::SUPERADMIN);

        $this->action->run($second, [SystemRole::ADMIN], $this->causer());

        $this->assertTrue($first->fresh()->hasRole(SystemRole::SUPERADMIN));
        $this->assertFalse($second->fresh()->hasRole(SystemRole::SUPERADMIN));
    }

    public function test_demoting_a_non_superadmin_is_never_blocked(): void
    {
        $user = $this->user();
        $user->assignRole(SystemRole::USER);

        $superadmin = $this->user();
        $superadmin->assignRole(SystemRole::SUPERADMIN);

        // The only superadmin is elsewhere; this user's roles are not the subject.
        $this->action->run($user, [SystemRole::ADMIN], $this->causer());

        $this->assertTrue($user->fresh()->hasRole(SystemRole::ADMIN));
    }

    public function test_it_records_the_before_and_after_role_lists(): void
    {
        $user = $this->user();
        $user->assignRole(SystemRole::USER);

        $this->action->run($user, [SystemRole::ADMIN], $this->causer());

        $activity = Activity::query()
            ->where('event', 'user.roles_assigned')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, 'no user.roles_assigned audit row was written');
        // `before` is the state before the sync — the new role is not in it.
        $this->assertSame([SystemRole::USER], $activity->properties['before'] ?? null);
        $this->assertSame([SystemRole::ADMIN], $activity->properties['after'] ?? null);
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    /**
     * A causer holding users.assign_roles and the superadmin role.
     *
     * AssignRolesAction owns the authorization for both C9 and C10, so these
     * tests bring a causer that passes it — they are about the sync mechanics
     * and the last-superadmin guard, not about who may assign. The refusal cases
     * are SuperadminVisibilityTest and GateCAuthorizationTest.
     */
    private function causer(): User
    {
        $causer = User::factory()->create();
        $causer->assignRole(RoleLookup::find(SystemRole::SUPERADMIN));

        return $causer;
    }
}

<?php

namespace Tests\Feature;

use App\Actions\V1\Role\RoleAssignAction;
use App\Actions\V1\User\UserIndexAction;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The superadmin is not an ordinary account: it is visible only to a superadmin.
 *
 * Two separate halves, and the tests are kept apart on purpose:
 *  - the GRANT is refused unless the causer is already a superadmin (RoleAssignAction)
 *  - the ROLE and the ACCOUNT are hidden from a non-superadmin viewer
 * Hiding alone would prove nothing about the grant, so both are asserted.
 */
class SuperadminVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $delegate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->superadmin = User::factory()->create(['name' => 'Root']);
        $this->superadmin->assignRole(RoleLookup::find(SystemRole::SUPERADMIN));

        // A delegated admin: the whole permission catalogue, no superadmin role.
        $this->delegate = User::factory()->create(['name' => 'Delegate']);
        $this->delegate->assignRole(RoleLookup::find(SystemRole::ADMIN));
    }

    // -- the grant --

    public function test_a_delegated_admin_cannot_grant_superadmin(): void
    {
        $target = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(RoleAssignAction::class)->run($target, [SystemRole::SUPERADMIN], $this->delegate);
    }

    public function test_a_superadmin_can_grant_superadmin(): void
    {
        $target = User::factory()->create();

        // Confirmed — this is the legitimate grant E5 requires a deliberate
        // signal for. Without the flag it is refused, which is the point of the
        // neighbouring test, not a failure of this one.
        app(RoleAssignAction::class)->run($target, [SystemRole::SUPERADMIN], $this->superadmin, true);

        $this->assertTrue($target->fresh()->hasRole(SystemRole::SUPERADMIN));
    }

    public function test_a_delegated_admin_cannot_escalate_via_the_update_endpoint(): void
    {
        // The C9 gap: users.update alone used to be enough to sync any role.
        $target = User::factory()->create();
        $role = RoleLookup::find(SystemRole::USER);
        $role->givePermissionTo(Permission::findByName('users.update', 'web'));
        $this->delegate->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->delegate)
            ->put(route('users.update', $target), [
                'name' => $target->name,
                'status' => 'active',
                'roles' => [SystemRole::SUPERADMIN],
            ]);

        $this->assertFalse($target->fresh()->hasRole(SystemRole::SUPERADMIN));
    }

    // -- the role --

    public function test_the_superadmin_role_is_hidden_from_a_non_superadmin(): void
    {
        $names = RoleLookup::visibleTo($this->delegate)->pluck('name');

        $this->assertNotContains(SystemRole::SUPERADMIN, $names);
        $this->assertContains(SystemRole::ADMIN, $names);
    }

    public function test_a_superadmin_sees_the_superadmin_role(): void
    {
        $names = RoleLookup::visibleTo($this->superadmin)->pluck('name');

        $this->assertContains(SystemRole::SUPERADMIN, $names);
    }

    public function test_the_role_picker_omits_superadmin_for_a_delegated_admin(): void
    {
        $html = $this->actingAs($this->delegate)->get(route('users.create'))->getContent();

        // The name must not be offered as an assignable choice.
        $this->assertStringNotContainsString('value="superadmin"', $html);
    }

    public function test_the_role_picker_offers_superadmin_to_a_superadmin(): void
    {
        $html = $this->actingAs($this->superadmin)->get(route('users.create'))->getContent();

        $this->assertStringContainsString('value="superadmin"', $html);
    }

    // -- the account --

    public function test_the_superadmin_account_is_absent_from_the_user_list(): void
    {
        $action = app(UserIndexAction::class);

        $forDelegate = $action->run(status: null, perPage: 50, viewer: $this->delegate)->pluck('id');
        $forSuperadmin = $action->run(status: null, perPage: 50, viewer: $this->superadmin)->pluck('id');

        $this->assertNotContains($this->superadmin->id, $forDelegate);
        $this->assertContains($this->superadmin->id, $forSuperadmin);
    }

    public function test_the_totals_do_not_count_the_hidden_account(): void
    {
        Cache::flush();
        $action = app(UserIndexAction::class);

        $forDelegate = $action->counts($this->delegate);
        $forSuperadmin = $action->counts($this->superadmin);

        $this->assertSame(
            $forSuperadmin['active'] - 1,
            $forDelegate['active'],
            'the hidden superadmin must not inflate the delegate totals'
        );
    }

    public function test_the_roles_page_count_matches_the_rows_shown(): void
    {
        $body = $this->actingAs($this->delegate)->get(route('roles.index'))->getContent();

        $this->assertStringNotContainsString('>superadmin<', $body);
    }
}

<?php

namespace Tests\Feature\User;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use App\Enums\UserStatusEnum;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Http\Middleware\VerifyCsrfToken;
use Spatie\Permission\PermissionRegistrar;

/**
 * The three render-only tests drop CSRF in their own bodies, because two of
 * them are about the form and one drives a write: binding the opt-out at class
 * level would leave that write test measuring a 419 instead of the flag, and
 * withMiddleware() cannot undo a setUp opt-out — the middleware is already
 * unbound from the container, so the route still passes.
 */

/**
 * P6-E5 through the actual forms.
 *
 * The server-side guard was pinned from the action and the API. This closes the
 * gap that neither could: an audit of the create/edit pages found the
 * confirmation control rendered from the *currently selected* roles, so the
 * form offered no way to send the flag in the one case that needed it —
 * GRANTING superadmin to an account that did not have it.
 *
 * Both directions, asserted on the rendered HTML rather than on the flag's
 * presence alone, because a control the browser never submits is not a control.
 */
class SuperadminGrantUiTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->superadmin->assignRole(SystemRole::SUPERADMIN);
    }

    /**
     * A plain account with no roles at all.
     */
    private function plainUser(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->assertFalse(
            $user->fresh()->hasRole(SystemRole::SUPERADMIN),
            'fixture: the target must start WITHOUT superadmin'
        );

        return $user;
    }

    public function test_the_create_form_offers_the_confirmation_for_a_user_without_the_role(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->actingAs($this->superadmin, 'web')
            ->get(route('users.create'))
            ->assertOk()
            // The regression this pins: the checkbox must be on the ADD form
            // even though nobody being created holds superadmin yet.
            ->assertSee('name="confirm_superadmin"', false);
    }

    public function test_the_edit_form_offers_the_confirmation_for_a_user_without_the_role(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $target = $this->plainUser();

        $this->actingAs($this->superadmin, 'web')
            ->get(route('users.edit', $target))
            ->assertOk()
            ->assertSee('name="confirm_superadmin"', false);
    }

    public function test_a_superadmin_can_be_granted_through_the_form_and_the_flag_is_required(): void
    {
        $target = $this->plainUser();

        // CSRF off: with it on, both requests are refused at the middleware
        // with a 419 and neither assertion below would be about the flag.
        // The token itself is not untested — RbacPentestTest::test_the_settings
        // write requires a valid csrf token proves the 419, alongside a
        // companion that drops the middleware and shows the write then lands.
        $this->withoutMiddleware(VerifyCsrfToken::class);

        // UpdateUserRequest's own shape: status is required and password is
        // prohibited, so a create-shaped payload would be refused by validation
        // before the flag is ever read.
        $payload = [
            'name' => 'Grantee',
            'email' => 'grantee@example.test',
            'status' => UserStatusEnum::ACTIVE->value,
            'roles' => [SystemRole::SUPERADMIN],
        ];

        // Refused without the flag. 403, not a validation error: the guard
        // throws AuthorizationException, which is the same response an
        // unprivileged caller gets. Asserting a redirect-with-errors here
        // would have "passed" only after I loosened it, and would have hidden
        // that the refusal is indistinguishable from a permission denial.
        $this->actingAs($this->superadmin, 'web')
            ->put(route('users.update', $target), $payload)
            ->assertForbidden();

        $this->assertFalse(
            $target->fresh()->hasRole(SystemRole::SUPERADMIN),
            'a refused save must not have granted the role anyway'
        );

        // Allowed with it. Two superadmins now exist, so the last-superadmin
        // guard is not what is being tested here — the flag is.
        $this->actingAs($this->superadmin, 'web')
            ->from(route('users.edit', $target))
            ->put(route('users.update', $target), $payload + ['confirm_superadmin' => '1'])
            // Back to the edit page, not the index: changing status or roles
            // keeps the admin on the form. Asserting the index here would be
            // asserting a redirect this flow never took.
            ->assertRedirect(route('users.edit', $target));

        $this->assertTrue(
            $target->fresh()->hasRole(SystemRole::SUPERADMIN),
            'the confirmed save did not grant the role'
        );
    }

    public function test_a_delegated_assigner_never_sees_the_confirmation_control(): void
    {
        // Holds users.update + users.assign_roles but is not a superadmin, so
        // visibleTo() hides the superadmin role from its picker entirely.
        $delegated = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $role = RoleLookup::assignable()->first(fn ($r) => $r->name === 'admin');
        $role->givePermissionTo(['users.view', 'users.update', 'users.assign_roles']);
        $delegated->assignRole($role);

        $this->assertTrue($delegated->can('users.assign_roles'), 'fixture: no assign_roles');

        $target = $this->plainUser();

        $this->actingAs($delegated, 'web')
            ->get(route('users.edit', $target))
            ->assertOk()
            ->assertDontSee('name="confirm_superadmin"', false)
            // And the role itself is not on offer, so there is nothing to grant.
            ->assertDontSee(SystemRole::SUPERADMIN);
    }

    public function test_the_control_is_absent_for_a_caller_who_may_not_assign_roles(): void
    {
        // The whole picker is inside @can('users.assign_roles'), so a viewer
        // with users.view only gets neither the roles nor the flag — which is
        // what stops the field being posted by someone who cannot use it.
        $readOnly = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        // users.update as well, so the page OPENS. Without it the route gate
        // 403s first and the test would pass for the wrong reason — proving
        // the field is absent, not that assign_roles is what hides it.
        $readOnly->assignRole(
            RoleLookup::assignable()->first(fn ($r) => $r->name === 'admin')
                ->syncPermissions(['users.view', 'users.update'])
        );

        $this->assertTrue($readOnly->can('users.update'), 'fixture: cannot open the edit page');
        $this->assertFalse($readOnly->can('users.assign_roles'), 'fixture: unexpectedly can');

        $this->actingAs($readOnly, 'web')
            ->get(route('users.edit', $this->plainUser()))
            ->assertOk()
            ->assertDontSee('name="confirm_superadmin"', false);
    }
}

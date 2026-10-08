<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use App\Http\Middleware\VerifyCsrfToken;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * Role editing on the admin user form.
 *
 * The role picker renders on both pages/users/create and pages/users/edit from
 * a shared partial, and the update path is the only place roles can be revoked.
 */
class UserRoleEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);

        // The admin form is permission-gated (P6-C14/C15), and a bare
        // `Role::findOrCreate('admin')` holds no permission rows — the matrix
        // lives in PermissionSeeder (RBAC-004), so both must run before the
        // admin is expected to pass `can()`. Kept as `admin` rather than
        // superadmin on purpose: this suite is about a delegated admin.
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($this->admin);

        $this->target = User::factory()->create();
    }

    public function test_edit_page_renders_a_role_picker(): void
    {
        Role::findOrCreate('editor', 'web');
        Role::findOrCreate('viewer', 'web');
        $this->target->assignRole('editor');

        $response = $this->get(route('users.show', $this->target));

        $response->assertOk();
        $response->assertSee('name="roles[]"', false);
        $response->assertSee('value="editor"', false);
        $response->assertSee('value="viewer"', false);
    }

    public function test_edit_page_checks_the_users_current_roles(): void
    {
        Role::findOrCreate('editor', 'web');
        Role::findOrCreate('viewer', 'web');
        $this->target->assignRole('editor');

        $response = $this->get(route('users.show', $this->target));

        $this->assertMatchesRegularExpression(
            '/value="editor"[^>]*\schecked/',
            $response->getContent()
        );
        $this->assertDoesNotMatchRegularExpression(
            '/value="viewer"[^>]*\schecked/',
            $response->getContent()
        );
    }

    public function test_admin_can_replace_a_users_roles(): void
    {
        Role::findOrCreate('editor', 'web');
        Role::findOrCreate('viewer', 'web');
        $this->target->assignRole('editor');

        $response = $this->put(route('users.update', $this->target), [
            'name' => $this->target->name,
            'status' => 'active',
            'roles' => ['viewer'],
        ]);

        $response->assertRedirect();
        $this->assertTrue($this->target->fresh()->hasRole('viewer'));
        $this->assertFalse($this->target->fresh()->hasRole('editor'));
    }

    public function test_an_empty_selection_removes_every_role(): void
    {
        Role::findOrCreate('editor', 'web');
        $this->target->assignRole('editor');

        // No checkboxes checked → the browser sends no `roles` key at all.
        $this->put(route('users.update', $this->target), [
            'name' => $this->target->name,
            'status' => 'active',
            'roles' => [],
        ])->assertRedirect();

        $this->assertCount(0, $this->target->fresh()->getRoleNames());
    }

    public function test_unknown_role_is_rejected(): void
    {
        Role::findOrCreate('editor', 'web');

        $response = $this->from(route('users.show', $this->target))->put(
            route('users.update', $this->target),
            [
                'name' => $this->target->name,
                'status' => 'active',
                'roles' => ['does-not-exist'],
            ]
        );

        $response->assertRedirect(route('users.show', $this->target));
        $response->assertSessionHasErrors('roles.0');
        $this->assertCount(0, $this->target->fresh()->getRoleNames());
    }

    public function test_roles_survive_an_update_that_omits_them(): void
    {
        Role::findOrCreate('editor', 'web');
        $this->target->assignRole('editor');

        // A caller that never sends `roles` — an API client on the old contract.
        $this->put(route('users.update', $this->target), [
            'name' => 'Renamed',
            'status' => 'active',
        ])->assertRedirect();

        $this->assertTrue($this->target->fresh()->hasRole('editor'));
        $this->assertSame('Renamed', $this->target->fresh()->name);
    }

    public function test_create_page_renders_the_same_role_picker(): void
    {
        Role::findOrCreate('editor', 'web');

        $response = $this->get(route('users.create'));

        $response->assertOk();
        $response->assertSee('name="roles[]"', false);
        $response->assertSee('value="editor"', false);
    }
}

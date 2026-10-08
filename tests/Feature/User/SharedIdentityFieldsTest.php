<?php

namespace Tests\Feature\User;

use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The two identity pages render the same avatar and the same role picker.
 *
 * Both used to compute the initials themselves, with different algorithms, so
 * the profile page and the user detail page could disagree about the same
 * person. Now both call User::initials(), and these pages prove it.
 */
class SharedIdentityFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // Several suites flip this off and the store is cached, so a test that
        // asserts the identity form renders must not inherit their state.
        SystemSetting::set('allow_username_change', 'true');
    }

    /**
     * Roles are keyed by (name, guard_name) and the web request resolves the
     * `web` guard, so the fixtures have to be seeded on the guard the request
     * will actually read.
     */
    private function admin(): User
    {
        $guard = RoleLookup::guard();

        $admin = User::factory()->create(['name' => 'John Ronald Reuel Tolkien', 'is_active' => true]);
        // The seeded admin, not Role::create(): the seeder already made the row,
        // and a second create for the same (name, guard) throws.
        $admin->assignRole(Role::where('name', 'admin')->where('guard_name', $guard)->firstOrFail());

        return $admin;
    }

        public function test_both_identity_pages_render_the_same_initials(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('profile.show'))->assertSee('JT');
        $this->actingAs($admin)->get(route('users.show', $admin))->assertSee('JT');
    }

    /**
     * The role list and the identity policy moved out of the controllers into
     * AccountOptionsComposer. Every page that shows those fields must still
     * render, with the data, and with no controller passing it.
     */
        public function test_the_composer_supplies_roles_and_the_identity_policy(): void
    {
        Role::create(['name' => 'editor', 'guard_name' => RoleLookup::guard()]);
        $admin = $this->admin();

        $create = $this->actingAs($admin)->get(route('users.create'));
        $create->assertOk();
        $create->assertSee('editor');

        $edit = $this->actingAs($admin)->get(route('users.show', $admin));
        $edit->assertOk();
        $edit->assertSee('editor');
        $edit->assertSee('Username can be changed');
    }
}

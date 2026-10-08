<?php

namespace Tests\Feature\User;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Http\Middleware\VerifyCsrfToken;

/**
 * Untick every role, save, and the roles must actually go.
 *
 * The role picker is a checkbox group, and a checkbox group has one failure mode
 * no other input has: an UNCHECKED box is not sent at all. Unticking every box
 * therefore posted no `roles` key whatsoever, and `UserUpdateAction` reads
 * `array_key_exists('roles', $data)` precisely to tell "the admin removed them
 * all" apart from "this caller never had roles in its payload". The two were
 * indistinguishable, so removing every role silently did nothing while the page
 * reported success.
 *
 * Fixed on the form side (a hidden `roles[]` so the key always arrives) plus a
 * strip of that placeholder in the request, because `exists:roles,name` would
 * otherwise reject the empty value we added on purpose.
 */
class UserRoleFormTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->admin->assignRole(SystemRole::SUPERADMIN);

        $this->actingAs($this->admin, 'web');
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function makeRole(string $name): AppRole
    {
        return AppRole::create(['name' => $name, 'guard_name' => RoleLookup::guard()]);
    }

    private function makeUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    /**
     * The regression, reproduced as the browser sends it: the hidden
     * `roles[]=""` placeholder is present and every box is unticked.
     */
    public function test_unchecking_every_role_clears_them(): void
    {
        $target = $this->makeUser();
        $target->assignRole([$this->makeRole('Alpha'), $this->makeRole('Beta')]);

        $this->assertCount(2, $target->fresh()->roles);

        $this->put(route('users.update', $target), [
            'name' => $target->name,
            'status' => 'active',
            'roles' => [''],
        ])->assertRedirect();

        $this->assertSame(
            [],
            $target->fresh()->roles->pluck('name')->all(),
            'unticking every role must clear them'
        );
    }

    /**
     * Ticking one of several replaces the set rather than adding to it.
     *
     * The other direction of the same key: the placeholder must not survive as a
     * blank role, and the ticked value must be the only one applied.
     */
    public function test_ticking_one_role_replaces_the_whole_set(): void
    {
        $target = $this->makeUser();
        $target->assignRole([$this->makeRole('Alpha'), $this->makeRole('Beta')]);

        $keep = $this->makeRole('Gamma');

        $this->put(route('users.update', $target), [
            'name' => $target->name,
            'status' => 'active',
            'roles' => ['', 'Gamma'],
        ])->assertRedirect();

        $this->assertSame(['Gamma'], $target->fresh()->roles->pluck('name')->all());
    }

    /**
     * The other half of the contract, which must NOT regress.
     *
     * A payload with no `roles` key at all means "leave the roles alone" — the
     * API depends on it, because omitting the key must never silently revoke
     * somebody's access as a side effect of updating their name.
     */
    public function test_omitting_the_roles_key_leaves_them_alone(): void
    {
        $target = $this->makeUser();
        $target->assignRole($this->makeRole('Alpha'));

        $this->put(route('users.update', $target), [
            'name' => $target->name,
            'status' => 'active',
        ])->assertRedirect();

        $this->assertSame(['Alpha'], $target->fresh()->roles->pluck('name')->all());
    }

    /** The API spelling of the same intent, unchanged by the form fix. */
    public function test_an_explicit_empty_array_clears_roles(): void
    {
        $target = $this->makeUser();
        $target->assignRole($this->makeRole('Alpha'));

        $this->put(route('users.update', $target), [
            'name' => $target->name,
            'status' => 'active',
            'roles' => [],
        ])->assertRedirect();

        $this->assertSame([], $target->fresh()->roles->pluck('name')->all());
    }

    /**
     * The form has to render the placeholder, or the whole fix is invisible to
     * a browser. Asserted against the rendered HTML rather than the partial, so
     * a future edit to the form that drops it fails here.
     */
    public function test_the_role_picker_renders_the_placeholder_before_the_checkboxes (): void
    {
        $target = $this->makeUser();

        $html = $this->get(route('users.edit', $target))->assertOk()->getContent();

        $this->assertStringContainsString('type="hidden" name="roles[]"', $html);

        // PHP keeps the LAST value for a repeated name, so a placeholder placed
        // after the checkboxes would win every time and blank the whole set.
        $hidden = strpos($html, 'type="hidden" name="roles[]"');
        $firstCheckbox = strpos($html, 'type="checkbox" name="roles[]"');

        $this->assertNotFalse($hidden);
        $this->assertNotFalse($firstCheckbox);
        $this->assertLessThan(
            $firstCheckbox,
            $hidden,
            'the hidden input must come first, or it overwrites the ticked values'
        );
    }
}

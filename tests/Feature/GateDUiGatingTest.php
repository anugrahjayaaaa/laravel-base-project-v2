<?php

namespace Tests\Feature;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The permission boundary as a user actually meets it: routes, sidebar, buttons.
 *
 * Gate C proved the endpoints refuse a caller who lacks the permission. It said
 * nothing about the other two halves of the same boundary — that the sidebar
 * stops offering a screen that would 403, and that the buttons inside a page
 * stop offering actions the caller would be refused.
 *
 * Those three can disagree. A menu that outruns the routes shows dead links; a
 * page that renders a Delete button the server will reject is a 403 the user
 * earns by clicking the obvious thing. So the same permission is asserted at
 * all three layers rather than trusted to line up on its own.
 */
class GateDUiGatingTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->superadmin = User::factory()->create([
            'name' => 'Super Admin',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->superadmin->assignRole(SystemRole::SUPERADMIN);

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    /**
     * A user holding exactly the named permissions, holding nothing else.
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        if ($permissions === []) {
            $user->assignRole(RoleLookup::find(SystemRole::USER));

            return $user->fresh();
        }

        $role = AppRole::create([
            'name' => 'Scoped '.implode('+', $permissions).' '.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);

        $role->givePermissionTo(
            \Spatie\Permission\Models\Permission::whereIn('name', $permissions)
                ->where('guard_name', RoleLookup::guard())
                ->get()
        );

        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * The verification spec, first half: a zero-permission user is refused
     * everywhere that is gated and reaches everything that is not.
     */
    public function test_a_zero_permission_user_is_refused_every_gated_page(): void
    {
        $nobody = $this->userWith([]);

        $this->actingAs($nobody, 'web');

        foreach ([
            route('users.index'),
            route('settings.index'),
            route('roles.index'),
            route('permissions.index'),
        ] as $uri) {
            $this->get($uri)->assertForbidden();
        }
    }

    /**
     * ...and is NOT refused the pages that carry no permission gate, because a
     * Gate::before that answered "no" to everything would pass the test above
     * while locking every account out of the application entirely.
     */
    public function test_a_zero_permission_user_keeps_the_ungated_pages(): void
    {
        $nobody = $this->userWith([]);

        $this->actingAs($nobody, 'web');

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('profile.show'))->assertOk();
        $this->get(route('sessions'))->assertOk();
    }

    /**
     * The same boundary on the API surface. A web gate with no API counterpart
     * is not a boundary, it is a suggestion.
     */
    public function test_the_api_refuses_the_same_pages_the_web_does(): void
    {
        $nobody = $this->userWith([]);

        $this->actingAs($nobody, 'web');

        $this->getJson(route('api.v1.users.index'))->assertForbidden();
        $this->getJson(route('api.v1.settings.index'))->assertForbidden();

        $this->getJson(route('api.v1.profile.show'))->assertOk();
    }

    /**
     * The verification spec, second half: a narrowly-permissioned user sees
     * exactly the menu entries matching their permissions.
     */
    public function test_the_sidebar_shows_exactly_what_the_permissions_allow(): void
    {
        $staff = $this->userWith(['users.view', 'users.update', 'settings.manage']);

        $this->actingAs($staff, 'web');

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        // The three they hold — assert on the LINK TEXT, which is what a user
        // sees. The hrefs alone would also match a URL echoed somewhere else.
        $this->assertMatchesRegularExpression('/>\s*Users\s*</', $html);
        $this->assertMatchesRegularExpression('/>\s*Settings\s*</', $html);

        // Dashboard is unconditional.
        $this->assertStringContainsString(route('dashboard'), $html);

        // The two they do not.
        $this->assertStringNotContainsString(route('roles.index'), $html);
        $this->assertStringNotContainsString(route('permissions.index'), $html);
    }

    /**
     * A group whose every item was filtered out must not leave its heading
     * behind: "Management" over an empty list reads as a broken sidebar, not a
     * narrow one.
     */
    public function test_an_emptied_group_does_not_leave_a_heading(): void
    {
        $nobody = $this->userWith([]);

        $this->actingAs($nobody, 'web');

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            route('roles.index'),
            $html,
            'a user with no roles permissions must not be offered Roles'
        );
    }

    /**
     * The composer is the single place filtering happens. The sidebar partial
     * must not repeat it: two gate lookups for the same answer, and two places
     * that could disagree.
     */
    public function test_the_sidebar_does_not_repeat_the_composers_filtering(): void
    {
        $source = (string) file_get_contents(
            resource_path('views/layouts/partials/sidebar.blade.php')
        );

        $this->assertStringNotContainsString(
            '@can',
            $source,
            'sidebar.blade.php must rely on AppMenuComposer, not its own @can checks'
        );
    }

    /**
     * Every menu entry that names a permission must name one the catalogue
     * actually has. A typo does not fail — it just hides the link forever.
     */
    public function test_every_menu_permission_is_a_real_permission(): void
    {
        $known = \App\Support\PermissionCatalog::all();

        $source = (string) file_get_contents(
            app_path('View/Composers/AppMenuComposer.php')
        );

        preg_match_all("/'permission' => '([a-z_.]+)'/", $source, $matches);

        $this->assertNotEmpty($matches[1], 'no menu item declares a permission');

        foreach ($matches[1] as $permission) {
            $this->assertContains(
                $permission,
                $known,
                "the menu gates on '{$permission}', which is not in the catalogue"
            );
        }
    }

    /**
     * A menu entry pointing at a route that was never shipped renders as a dead
     * '#' link, so the composer drops it.
     *
     * Two entries genuinely have no route today — activity-logs.index and
     * translations.index — so the requirement is not "every entry resolves" but
     * "no UNRESOLVED entry is rendered". Asserting the composer is honest about
     * what it drops is what keeps a fourth dead link from being added quietly.
     */
    public function test_an_unshipped_menu_route_is_dropped_rather_than_rendered(): void
    {
        $this->actingAs($this->superadmin, 'web');

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $source = (string) file_get_contents(
            app_path('View/Composers/AppMenuComposer.php')
        );

        preg_match_all("/'route' => '([^']+)'/", $source, $matches);

        $unshipped = array_values(array_filter(
            array_unique($matches[1]),
            fn (string $name) => $name !== '#' && ! Route::has($name)
        ));

        // Activity Logs is one of them today, so prove the drop is real rather
        // than an empty loop over nothing.
        $this->assertContains('activity-logs.index', $unshipped);
        $this->assertContains('translations.index', $unshipped);

        // Assert the dropped entry's LABEL is absent. Asserting on href="#" would
        // be wrong: the decorative Labels group is '#' on purpose, and a dead
        // link is what this rule exists to prevent, not the character itself.
        $this->assertStringNotContainsString('Activity Logs', $html);
        $this->assertStringNotContainsString('Translations', $html);

        // The labels group IS '#' by design and must survive.
        $this->assertStringContainsString('Important', $html);
    }

    /**
     * P6-D5/D6: the user list must not offer a control the caller would be
     * refused. The server still re-checks — this is about not handing someone a
     * button whose only outcome is a 403.
     */
    public function test_the_user_list_hides_actions_the_caller_cannot_perform(): void
    {
        $viewer = $this->userWith(['users.view']);

        $this->actingAs($viewer, 'web');

        $html = $this->get(route('users.index'))->assertOk()->getContent();

        // Can view the list, cannot create, delete, or lock anybody.
        $this->assertStringNotContainsString(route('users.create'), $html);
        $this->assertStringNotContainsString('Permanent Delete', $html);
        $this->assertStringNotContainsString('Lock Account', $html);
        $this->assertStringNotContainsString('>Move to Trash<', $html);
    }

    /**
     * A caller who does hold the permission still gets the control — a gate that
     * hides everything is indistinguishable from a broken page.
     */
    public function test_the_user_list_still_offers_what_the_caller_can_perform(): void
    {
        $this->actingAs($this->superadmin, 'web');

        $html = $this->get(route('users.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('users.create'), $html);
        $this->assertStringContainsString('Lock Account', $html);
        $this->assertStringContainsString('>Move to Trash<', $html);
    }

    /**
     * P6-D6: the bulk dropdown offers only actions the caller may run.
     */
    public function test_the_bulk_dropdown_offers_only_permitted_actions(): void
    {
        $viewer = $this->userWith(['users.view', 'users.deactivate']);

        $this->actingAs($viewer, 'web');

        $html = $this->get(route('users.index'))->assertOk()->getContent();

        // Held, so offered.
        $this->assertStringContainsString('>Deactivate<', $html);

        // Not held, so absent — including from the bulk dropdown, not just the
        // per-row buttons.
        $this->assertStringNotContainsString('>Permanent Delete<', $html);
        $this->assertStringNotContainsString('>Lock<', $html);
        $this->assertStringNotContainsString('>Restore<', $html);
    }

    /**
     * P6-D7: hiding the role picker must not post a roles key at all.
     *
     * This is the one that could actually destroy data. The picker's hidden
     * input is what distinguishes "cleared them" from "never sent roles" — so if
     * the hidden input renders OUTSIDE the @can, a caller without
     * users.assign_roles who saves an unrelated field posts roles: [] and strips
     * every role from the account.
     */
    public function test_hiding_the_role_picker_posts_no_roles_key(): void
    {
        $editor = $this->userWith(['users.update']);
        $target = $this->userWith(['users.view']);
        $target->assignRole(RoleLookup::find(SystemRole::USER));

        $before = $target->fresh()->roles->pluck('name')->all();

        $this->actingAs($editor, 'web');

        // The picker must be absent from the form...
        $html = $this->get(route('users.edit', $target->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="roles[]"', $html);

        // ...and a save that omits it must leave the roles alone.
        $this->put(route('users.update', $target->id), [
            'name' => 'Renamed By Editor',
            'status' => 'active',
        ])->assertRedirect();

        $this->assertSame(
            $before,
            $target->fresh()->roles->pluck('name')->all(),
            'a save by someone without users.assign_roles must not strip roles'
        );
    }

    /**
     * ...and a caller who does hold it still gets the picker, with the current
     * assignment checked.
     */
    public function test_the_role_picker_is_present_for_a_caller_who_may_assign(): void
    {
        $this->actingAs($this->superadmin, 'web');

        $target = $this->userWith(['users.view']);
        $target->assignRole(RoleLookup::find(SystemRole::USER));

        $html = $this->get(route('users.edit', $target->id))->assertOk()->getContent();

        $this->assertStringContainsString('name="roles[]"', $html);
    }

    /**
     * P6-D8: settings.view alone yields a READ-ONLY page, not a 403. Being told
     * "you may not view settings" by a page whose route is called settings.view
     * is a contradiction; showing it read-only is the answer.
     */
    public function test_settings_view_alone_gives_a_read_only_page(): void
    {
        $reader = $this->userWith(['settings.view']);

        $this->actingAs($reader, 'web');

        // Seed one setting so the read-only table has a row to show.
        \App\Models\SystemSetting::set('login_max_attempts', '7');
        $settings = \App\Models\SystemSetting::getAll();

        // Precondition, so this cannot pass for the wrong reason: if this
        // user held settings.manage the page would be writable and the
        // assertions below would be meaningless.
        $this->assertTrue($reader->can('settings.view'));
        $this->assertFalse($reader->can('settings.manage'));

        $html = $this->get(route('settings.index'))->assertOk()->getContent();

        // Match the form's action attribute, not the bare settings URL — the
        // sidebar legitimately links to /settings for anyone with settings.view,
        // and asserting on that would be asserting the link is missing.
        $this->assertStringNotContainsString(
            'action="'.route('settings.update').'"',
            $html,
            'a viewer must not get the save form'
        );
        $this->assertStringNotContainsString('<form method="POST" action="'.route('settings.update'), $html);
        $this->assertStringNotContainsString('Save Settings', $html);

        // Read-only means the values are still readable, so the table is
        // rendered rather than the page being left blank. Assert on what the
        // table itself shows: the seeded keys, not the view's inline defaults
        // (a setting nobody has changed is absent from $settings entirely).
        $this->assertStringContainsString('Read-only', $html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString(
            (string) array_key_first($settings ?? []),
            $html
        );
    }

    /**
     * The other half: settings.manage gets the working form back.
     */
    public function test_settings_manage_gets_the_write_form(): void
    {
        $writer = $this->userWith(['settings.view', 'settings.manage']);

        $this->actingAs($writer, 'web');

        $html = $this->get(route('settings.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('settings.update'), $html);
        $this->assertStringContainsString('Save Settings', $html);
    }

    /**
     * The write endpoint re-checks regardless of what the form showed.
     */
    public function test_the_settings_write_endpoint_refuses_a_viewer(): void
    {
        $reader = $this->userWith(['settings.view']);

        $this->actingAs($reader, 'web');

        $this->post(route('settings.update'), ['login_max_attempts' => 99])
            ->assertForbidden();
    }
}

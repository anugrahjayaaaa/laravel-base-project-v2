<?php

namespace Tests\Feature\Role;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Symfony\Component\Finder\Finder;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\SystemSetting;
use App\Support\PermissionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

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
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->superadmin = User::factory()->create([
            'name' => 'Super Admin',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->superadmin->assignRole(SystemRole::SUPERADMIN);

        $this->withoutMiddleware(VerifyCsrfToken::class);
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
            Permission::whereIn('name', $permissions)
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
        // settings.view, not settings.manage: the sidebar item is gated on what
        // may SEE the page. Asserting manage here passed for the wrong reason —
        // it matched the footer link, which is now gone.
        $staff = $this->userWith(['users.view', 'users.update', 'settings.view']);

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
     * No view may ask the Gate itself.
     *
     * A view that calls `auth()->user()?->can(...)` is a view doing a Gate
     * lookup, and the answer belongs to whoever already knows it — a composer,
     * a Form Request, or the action that built the data. Two consequences, both
     * real: the lookup runs per row rather than once, and the rule lives in two
     * places that can disagree.
     *
     * The features index was the last holdout — it passed `manageable` as
     * `auth()->user()?->can('features.manage') ?? false` while every other
     * binding in the same view was a plain variable. `FeatureIndexAction`
     * computes it now.
     *
     * Scans the whole view layer, not one file, because the point is that there
     * are none: a first-file check passes the moment a second view grows one.
     */
    public function test_no_view_asks_the_gate_itself(): void
    {
        $offenders = [];

        // Finder, not glob(): PHP's glob() does NOT treat ** as recursive — it
        // matches a single level, so `views/**/*.blade.php` found 11 of the 45
        // blade files on disk and this test passed without reading the rest.
        // The file count is asserted below so that cannot happen silently.
        $files = Finder::create()
            ->files()
            ->in(resource_path('views'))
            ->name('*.blade.php');

        $seen = 0;

        foreach ($files as $file) {
            $seen++;
            $source = (string) file_get_contents($file->getPathname());

            // Strip Blade comments — a note that *documents* the ban may name
            // the call it forbids, and that is not a violation.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;

            if (preg_match('/auth\(\)->user\(\)\??->can\(|Auth::user\(\)\??->can\(/', $source)) {
                $offenders[] = str_replace(resource_path('views').'/', '', $file->getRelativePathname());
            }
        }

        // Guard against a scanner that finds nothing and reports success.
        $this->assertGreaterThan(30, $seen, 'the view scanner read almost no files — the ban below is vacuous');

        $this->assertSame(
            [],
            $offenders,
            'these views call the Gate directly instead of reading a variable: '
                .implode(', ', $offenders)
        );
    }

    /**
     * Every menu entry that names a permission must name one the catalogue
     * actually has. A typo does not fail — it just hides the link forever.
     */
    public function test_every_menu_permission_is_a_real_permission(): void
    {
        $known = PermissionCatalog::all();

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
     * `translations.index` is the one entry that genuinely has no route today, so
     * the requirement is not "every entry resolves" but "no UNRESOLVED entry is
     * rendered". Asserting the composer is honest about what it drops is what
     * keeps a second dead link from being added quietly.
     *
     * `activity-logs.index` was the other exemplar until Phase 10 shipped the
     * audit viewer, at which point `Route::has()` started returning true for it
     * and this assertion failed for a reason that had nothing to do with the rule
     * it exists to prove. The negative label assertion moved with it.
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

        // Translations is one of them today, so prove the drop is real rather
        // than an empty loop over nothing.
        $this->assertContains('translations.index', $unshipped);

        // Assert the dropped entry's LABEL is absent. Asserting on href="#" would
        // be wrong: the decorative Labels group is '#' on purpose, and a dead
        // link is what this rule exists to prevent, not the character itself.
        $this->assertStringNotContainsString('Translations', $html);

        // The counterpart, now that the audit route ships: a SHIPPED entry is
        // rendered. Without this the previous assertion would pass simply because
        // the label vanished for an unrelated reason.
        $this->assertStringContainsString('Activity Logs', $html);

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
        SystemSetting::set('login_max_attempts', '7');
        $settings = SystemSetting::getAll();

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

    /**
     * P6-D audit follow-up: the user DETAIL page had the same seven triggers as
     * the list, ungated. Gating the list and not the detail is the same control
     * missing one page over — a caller who may edit a user is on that page by
     * definition, and every button there was offered unconditionally.
     */
    public function test_the_user_detail_page_hides_actions_the_caller_cannot_perform(): void
    {
        $editor = $this->userWith(['users.view', 'users.update']);

        // Unverified, or the resend callout is not rendered at all and the
        // positive assertion below would pass on an absent string.
        $target = User::factory()->create([
            'email_verified_at' => null,
            'is_active' => true,
        ]);

        $this->actingAs($editor, 'web');

        $html = $this->get(route('users.edit', $target->id))->assertOk()->getContent();

        // Held, so still offered — a gate that hides everything is a broken page.
        $this->assertStringContainsString(
            route('users.resend-verification', $target),
            $html
        );

        // Not held. Assert data-action, not the bare URL: the page's own update
        // form posts to the same /users/3, so a URL match proves nothing. The
        // confirm-action component is the only thing that emits data-action.
        $this->assertStringNotContainsString('data-action="'.route('users.deactivate', $target).'"', $html);
        $this->assertStringNotContainsString('data-action="'.route('users.lock', $target).'"', $html);
        $this->assertStringNotContainsString('data-action="'.route('users.destroy', $target).'"', $html);
    }

    /**
     * A caller who holds the state permissions gets them back on the detail page.
     */
    public function test_the_user_detail_page_still_offers_what_the_caller_can_perform(): void
    {
        $locker = $this->userWith(['users.view', 'users.update', 'users.deactivate', 'users.lock']);
        $target = $this->userWith(['users.view']);

        $this->actingAs($locker, 'web');

        $html = $this->get(route('users.edit', $target->id))->assertOk()->getContent();

        $this->assertStringContainsString('data-action="'.route('users.deactivate', $target).'"', $html);
        $this->assertStringContainsString('data-action="'.route('users.lock', $target).'"', $html);
    }

    /**
     * roles.assign_permissions existed in the catalogue, showed in the
     * permissions UI, and gated nothing: the matrix rendered unconditionally
     * and both role requests accepted a posted permission set from anyone who
     * could open the form. So a caller holding only roles.update could hand a
     * role superadmin — the one action in this app that escalates the caller.
     */
    public function test_a_rename_only_admin_cannot_rewrite_a_permission_set(): void
    {
        $renamer = $this->userWith(['roles.view', 'roles.update']);

        $role = AppRole::create([
            'name' => 'Rename Only Target',
            'guard_name' => RoleLookup::guard(),
        ]);

        $this->actingAs($renamer, 'web');

        // The view must not offer it...
        $html = $this->get(route('roles.edit', $role->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="permissions[]"', $html);

        // ...and the endpoint must refuse a hand-rolled POST, which is the half
        // that actually matters: @can in a template is not a control.
        $this->put(route('roles.update', $role->id), [
            'name' => 'Renamed',
            'permissions' => [$this->permissionId('roles.force_delete')],
        ])->assertForbidden();

        // Neither the rename nor the permission set moved.
        $role->refresh();
        $this->assertSame('Rename Only Target', $role->name);
        $this->assertCount(0, $role->permissions);
    }

    /**
     * The rename half stays open — only the permission set is split off, so an
     * admin who may name a role but not distribute permissions is not locked
     * out of a form they are otherwise entitled to use.
     */
    public function test_a_rename_only_admin_can_still_rename_a_role(): void
    {
        $renamer = $this->userWith(['roles.view', 'roles.update']);

        $role = AppRole::create([
            'name' => 'Before',
            'guard_name' => RoleLookup::guard(),
        ]);

        $this->actingAs($renamer, 'web');

        $this->put(route('roles.update', $role->id), ['name' => 'After'])
            ->assertRedirect(route('roles.index'));

        $this->assertSame('After', $role->fresh()->name);
    }

    /**
     * ...and one who may assign permissions gets the matrix back, working.
     */
    public function test_the_matrix_is_present_for_a_caller_who_may_assign_permissions(): void
    {
        $assigner = $this->userWith([
            'roles.view',
            'roles.update',
            'roles.assign_permissions',
        ]);

        $role = AppRole::create([
            'name' => 'Assigner Target',
            'guard_name' => RoleLookup::guard(),
        ]);

        $this->actingAs($assigner, 'web');

        $html = $this->get(route('roles.edit', $role->id))->assertOk()->getContent();
        $this->assertStringContainsString('name="permissions[]"', $html);

        $this->put(route('roles.update', $role->id), [
            'name' => 'Assigner Target',
            'permissions' => [$this->permissionId('roles.force_delete')],
        ])->assertRedirect(route('roles.index'));

        $this->assertTrue(
            $role->fresh()->permissions->contains('name', 'roles.force_delete')
        );
    }

    /**
     * The store side carries the same split: creating a role that grants nothing
     * is harmless, creating one that grants superadmin is not.
     */
    public function test_a_role_cannot_be_created_granting_permissions_without_the_permission(): void
    {
        $creator = $this->userWith(['roles.view', 'roles.create']);

        $this->actingAs($creator, 'web');

        $this->post(route('roles.store'), [
            'name' => 'Sneaky Escalation',
            'permissions' => [$this->permissionId('roles.force_delete')],
        ])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'Sneaky Escalation']);
    }

    private function permissionId(string $name): int
    {
        return Permission::where('name', $name)
            ->where('guard_name', RoleLookup::guard())
            ->value('id');
    }
}

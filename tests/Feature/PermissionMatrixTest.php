<?php

namespace Tests\Feature;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The permission matrix split pane.
 *
 * This is not a pixel test. It pins the one invariant whose violation is
 * invisible until someone loses access: EVERY permission input must be in the
 * DOM at all times.
 *
 * An absent input submits nothing, and an unchecked input also submits nothing
 * — the two are byte-identical on the wire. So a matrix that removed or
 * server-filtered its inputs would look perfect, pass a visual review, and turn
 * Save into "uncheck everything the admin cannot currently see". No HTTP test
 * would catch it, because the request that does the damage is a normal one.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private AppRole $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->superadmin->assignRole(SystemRole::SUPERADMIN);

        $this->role = AppRole::create([
            'name' => 'editable',
            'guard_name' => RoleLookup::guard(),
        ]);
    }

    /** @return array<string, \Illuminate\Support\Collection> */
    /**
     * Group order exactly as RoleController::permissionData() produces it.
     *
     * The orderBy is not decoration here: the rail marks the FIRST group as
     * current, so a helper that groups an unordered result would assert against
     * a different order than the view renders and fail on ordering, not on the
     * thing it means to check. This mirrors the controller rather than
     * reimplementing it, so if the controller's ordering is ever deliberate,
     * this follows instead of arguing with it.
     */
    private function permissionGroups(): array
    {
        return collect(\Spatie\Permission\Models\Permission::query()
            ->where('guard_name', RoleLookup::guard())
            ->orderBy('name')
            ->get())
            ->groupBy(fn ($permission) => str($permission->name)->before('.')->value())
            ->all();
    }

    private function catalogue(): int
    {
        return \Spatie\Permission\Models\Permission::where('guard_name', RoleLookup::guard())->count();
    }

    public function test_every_permission_input_is_rendered_even_in_groups_that_start_collapsed(): void
    {
        $html = $this->actingAs($this->superadmin, 'web')
            ->get(route('roles.edit', $this->role))
            ->assertOk()
            ->getContent();

        // The load-bearing assertion. Counted from the submitted form's point of
        // view: every permission in the catalogue has an input, so every
        // permission can be posted — checked or not.
        $this->assertSame(
            $this->catalogue(),
            substr_count($html, 'name="permissions[]"'),
            'a permission has no input on the page, so it cannot be posted at all'
        );

        // And the collapsed groups are collapsed by `hidden`, not by absence.
        // A `d-none` class would be equally safe; removing the markup is not.
        $this->assertSame(
            $this->catalogue(),
            substr_count($html, 'data-matrix-row'),
            'rows and inputs disagree — something is rendered without a control'
        );
    }

    public function test_only_the_first_group_section_starts_visible(): void
    {
        $html = $this->actingAs($this->superadmin, 'web')
            ->get(route('roles.edit', $this->role))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match_all('/<section data-matrix-section="[^"]+"(?![^>]*hidden)/', $html),
            'more than one group is visible on load, or the visibility is done wrong'
        );

        // The hidden ones still carry their markup.
        $this->assertGreaterThan(
            1,
            substr_count($html, 'data-matrix-section='),
            'expected several groups to be rendered'
        );
    }

    /**
     * The rail must not look like a different application.
     *
     * The design system defines a nav-pills convention (docs/base/ui/design-system.md
     * § Navigation Tabs) and it is explicitly for the HORIZONTAL status filter on
     * an index page, where `active` is an underline: `border-bottom border-primary
     * border-2`. Reusing it vertically was the first attempt at this rail and read
     * as foreign — an AdminLTE filled block where every other page in the app
     * underlines.
     *
     * So the rail borrows that convention's typography and badge pair and uses the
     * left edge as the indicator instead. These assertions exist because "looks
     * consistent with the rest of the app" is otherwise an opinion nobody checks.
     */
    public function test_the_rail_follows_the_design_system_badge_pair(): void
    {
        $html = $this->actingAs($this->superadmin, 'web')
            ->get(route('roles.edit', $this->role))
            ->assertOk()
            ->getContent();

        // DS badge pair only. `bg-warning` was a third state invented for
        // "partially selected"; the count text carries that instead.
        $this->assertStringNotContainsString('bg-warning', $html);
        $this->assertStringNotContainsString('text-dark', $html);

        // The horizontal underline must not be rotated onto a column.
        $this->assertStringNotContainsString('border-bottom border-primary', $html);

        // And the rail is not a nav-pills list any more.
        $this->assertStringNotContainsString('nav nav-pills', $html);
    }

    /**
     * The search field must match the header's live search.
     *
     * First attempt led with the icon in an input-group-text BEFORE the input,
     * which reads as a button: people click the affordance next to a field and
     * nothing happens. The header search puts it on the right, and that is the
     * control this one is imitating.
     *
     * Also pins `fas` over `bi`. Bootstrap Icons appear in 16 views, Font
     * Awesome in 25, so a `bi` here is not an unknown class -- it is a silently
     * empty box in the most-looked-at control on the page if that font is not
     * loaded. The form-text hint went with it: `form-text` in this project means
     * "constraint on this field" (see roles/edit.blade.php), and a note about
     * what the filter matches is neither a constraint nor needed, since the
     * result list already shows it.
     */
    public function test_the_search_field_matches_the_header_search(): void
    {
        $html = $this->actingAs($this->superadmin, 'web')
            ->get(route('roles.edit', $this->role))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('fas fa-search', $html, 'the search icon must be Font Awesome');
        $this->assertStringNotContainsString('bi bi-search', $html);

        // Icon AFTER the input, matching header.blade.php.
        $this->assertMatchesRegularExpression(
            '/data-matrix-search.*?<span class="input-group-text[^"]*">\s*<i class="fas fa-search/s',
            $html,
            'the icon affordance must follow the input, not precede it'
        );

        // Named for assistive tech even though the visible label is gone.
        $this->assertStringContainsString('aria-label="Search permissions"', $html);
    }

    public function test_exactly_one_rail_entry_is_marked_current_and_it_is_the_first(): void
    {
        $html = $this->actingAs($this->superadmin, 'web')
            ->get(route('roles.edit', $this->role))
            ->assertOk()
            ->getContent();

        // aria-current, not a class: it is what the JS swaps, and it survives a
        // class rename without going quietly inert.
        $this->assertSame(
            1,
            preg_match_all('/data-matrix-group="[^"]+"\s+aria-current="true"/', $html),
            'exactly one group must start selected'
        );

        // That one must be the group the view listed first. Its identity is not
        // guessed -- it is whatever order() produced, so this cannot fail on an
        // unrelated catalogue change (alphabetically the first group is
        // `permissions`, not `users`).
        preg_match('/data-matrix-group="([^"]+)"\s+aria-current="true"/', $html, $selected);

        $this->assertSame(
            array_key_first($this->permissionGroups()),
            $selected[1] ?? null,
            'the marked-current group is not the first one the view rendered'
        );
    }

    public function test_the_rail_carries_a_count_for_every_group(): void
    {
        $html = $this->actingAs($this->superadmin, 'web')
            ->get(route('roles.edit', $this->role))
            ->assertOk()
            ->getContent();

        // One badge and one select-all per group — the split pane's whole point
        // is that "which resources have access" is answerable without scrolling.
        $this->assertSame(
            substr_count($html, 'data-matrix-count='),
            substr_count($html, 'data-matrix-group='),
            'a group is missing its count badge'
        );

        $this->assertSame(
            substr_count($html, 'data-matrix-group='),
            substr_count($html, 'data-matrix-selectall='),
            'a group is missing its select-all toggle'
        );
    }

    public function test_the_change_summary_carries_the_baseline_the_form_started_from(): void
    {
        $permission = \Spatie\Permission\Models\Permission::findByName('users.view', RoleLookup::guard());
        $other = \Spatie\Permission\Models\Permission::findByName('roles.view', RoleLookup::guard());
        $this->role->syncPermissions([$permission->id, $other->id]);

        $html = $this->actingAs($this->superadmin, 'web')
            ->get(route('roles.edit', $this->role))
            ->assertOk()
            ->getContent();

        // Sorted, comma-joined ids: the JS diffs the live selection against it,
        // so a form that lost its baseline would report every restored checkbox
        // as an addition after a validation failure.
        preg_match('/data-matrix-original="([^"]*)"/', $html, $m);

        $baseline = explode(',', $m[1]);
        sort($baseline);

        $expected = [(string) $permission->id, (string) $other->id];
        sort($expected);

        $this->assertSame($expected, $baseline);
    }

    public function test_an_empty_role_reports_an_empty_baseline(): void
    {
        $html = $this->actingAs($this->superadmin, 'web')
            ->get(route('roles.edit', $this->role))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-matrix-original=""', $html);
    }

    public function test_the_create_form_renders_the_matrix_too(): void
    {
        $html = $this->actingAs($this->superadmin, 'web')
            ->get(route('roles.create'))
            ->assertOk()
            ->getContent();

        $this->assertSame($this->catalogue(), substr_count($html, 'name="permissions[]"'));
        $this->assertStringContainsString('id="permissionMatrix"', $html);
    }

    /**
     * The behaviour the whole design rests on, stated as a request: filter the
     * visible rows, then submit. Every permission the admin did not tick must
     * still be posted as unchecked — that is what "unchecked" means on a form,
     * and it is only true because the input stayed in the DOM.
     */
    public function test_a_partial_selection_posts_the_whole_set_not_just_the_visible_rows(): void
    {
        $view = \Spatie\Permission\Models\Permission::where('guard_name', RoleLookup::guard())
            ->orderBy('name')->get();

        // One from users, one from roles: a selection the admin could only make
        // by touching two different groups.
        $payload = [
            'name' => 'editable',
            'permissions' => [$view->firstWhere('name', 'users.view')->id, $view->firstWhere('name', 'roles.view')->id],
        ];

        // CSRF off: this is the one test that submits the form, and a token
        // would have both requests refused at the middleware before the
        // permission set was ever read. The token itself is covered by
        // RbacPentestTest.
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->actingAs($this->superadmin, 'web')
            ->put(route('roles.update', $this->role), $payload)
            ->assertRedirect(route('roles.index'));

        $this->assertCount(2, $this->role->fresh()->permissions);

        // And the ones nobody ticked are genuinely gone — not merely absent
        // from the DOM, which would look identical until this assertion.
        $this->assertFalse(
            $this->role->fresh()->hasPermissionTo('users.delete'),
            'an untouched permission survived the sync'
        );
    }
}

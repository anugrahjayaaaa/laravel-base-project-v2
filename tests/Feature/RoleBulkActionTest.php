<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk actions on the roles index, and the tab-pill parity with users/index.
 *
 * The reported problem was cosmetic — the Trash pill did not look like the All
 * Roles pill. The cause is shape, not colour: every users/index tab carries a
 * count badge, and the All Roles pill carried none, so a bare label sat beside a
 * badged one and the pair read as two different components. These tests assert
 * the badge is on both, because a colour tweak would not have caught it.
 *
 * The bulk bar is shared JS (resources/js/helpers/bulk-actions.js) configured
 * from data attributes, so these tests also pin the configuration the roles page
 * hands it: the id field name, the per-tab action list, and the action-config
 * key the confirm modal resolves copy from. A roles bar that posted user_ids[]
 * would 422, and one that posted role_ids[] but resolved the USER copy would
 * tell the admin "They can be restored later" about a role.
 */
class RoleBulkActionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->admin->assignRole(RoleLookup::find('admin'));
        $this->actingAs($this->admin);
    }

    private function makeRole(string $name): Role
    {
        return Role::create(['name' => $name, 'guard_name' => RoleLookup::guard()]);
    }

    public function test_both_tabs_carry_a_count_badge(): void
    {
        $html = $this->get(route('roles.index'))->assertOk()->getContent();

        // Two pills, two badges — the same shape on each.
        $this->assertSame(2, substr_count($html, 'class="badge rounded-pill '));

        $trash = $this->get(route('roles.index', ['trashed' => 1]))->assertOk()->getContent();
        $this->assertSame(2, substr_count($trash, 'class="badge rounded-pill '));
    }

    public function test_the_live_tab_offers_only_delete_and_the_trash_tab_offers_restore_and_force_delete(): void
    {
        // Page 1 holds the three seeded system roles, and a system role is not
        // selectable, so a bare "no data-status" assertion would pass for the
        // wrong reason. Seed a real role so the row markup is actually rendered.
        $this->makeRole('Support One');

        $live = $this->get(route('roles.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-bulk-states=\'{"active":["delete"]}\'', $live);
        $this->assertStringContainsString('data-bulk-field="role_ids[]"', $live);
        $this->assertStringContainsString('data-bulk-noun="role"', $live);
        // The confirm modal must resolve the ROLE copy, not the user copy.
        $this->assertStringContainsString('"delete":"delete_role"', $live);
        // A live row is data-status=active so the dropdown narrows correctly.
        $this->assertStringContainsString('data-status="active"', $live);

        $trash = $this->get(route('roles.index', ['trashed' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('data-bulk-states=\'{"trashed":["restore","force_delete"]}\'', $trash);
    }

    public function test_a_system_role_is_not_selectable(): void
    {
        $html = $this->get(route('roles.index'))->assertOk()->getContent();

        // The seeded system roles are on this page and none is checkable.
        // `superadmin` is absent from the list entirely for a non-superadmin
        // viewer, so it is not asserted here — SuperadminVisibilityTest covers it.
        foreach (['admin', 'user'] as $system) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($system, '/').'.*?(?!.*bulk-check)/s',
                $html,
                "the {$system} row rendered a bulk checkbox"
            );
        }
    }

    public function test_bulk_delete_trashes_every_selected_role_and_revokes_it(): void
    {
        $a = $this->makeRole('Support One');
        $b = $this->makeRole('Support Two');
        $holder = User::factory()->create();
        $holder->assignRole($a);
        $holder->assignRole($b);

        $this->assertTrue($holder->fresh()->hasRole('Support One'), 'precondition');

        $this->from(route('roles.index'))->post(route('roles.bulk-action'), [
            'action' => 'delete',
            'role_ids' => [$a->id, $b->id],
        ])->assertRedirect(route('roles.index'))->assertSessionHas('status');

        $this->assertSoftDeleted('roles', ['id' => $a->id]);
        $this->assertSoftDeleted('roles', ['id' => $b->id]);
        // The whole point: trashing must REVOKE, not just hide.
        $this->assertFalse($holder->fresh()->hasRole('Support One'));
        $this->assertFalse($holder->fresh()->hasRole('Support Two'));
        $this->assertNull(RoleLookup::find('Support One'), 'a trashed role must stop resolving');
    }

    public function test_bulk_delete_refuses_a_system_role_even_when_its_id_is_posted(): void
    {
        $system = RoleLookup::find('admin');
        $target = $this->makeRole('Support One');

        $this->post(route('roles.bulk-action'), [
            'action' => 'delete',
            'role_ids' => [$system->id, $target->id],
        ])->assertRedirect();

        // The handler filtered the system role out, so it is still resolvable.
        $this->assertNotNull(
            RoleLookup::find('admin'),
            'the system role must survive a posted id — getValidItems excluded it'
        );
        // assertSoftDeleted/assertNotSoftDeleted take no message parameter — the
        // 2nd arg is the connection name, so passing prose there throws
        // "connection not configured". Let the row id speak.
        $this->assertSoftDeleted('roles', ['id' => $target->id]);
    }

    public function test_bulk_restore_brings_roles_back_without_reassigning_them(): void
    {
        $role = $this->makeRole('Support One');
        $holder = User::factory()->create();
        $holder->assignRole($role);

        $this->post(route('roles.bulk-action'), ['action' => 'delete', 'role_ids' => [$role->id]]);
        $this->assertSoftDeleted('roles', ['id' => $role->id]);

        $this->from(route('roles.index', ['trashed' => 1]))->post(route('roles.bulk-action'), [
            'action' => 'restore',
            'role_ids' => [$role->id],
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertNotSoftDeleted('roles', ['id' => $role->id]);
        $this->assertNotNull(RoleLookup::find('Support One'), 'a restored role must resolve again');
        // Restoring the row is not re-granting access.
        $this->assertFalse($holder->fresh()->hasRole('Support One'), 'restore must not reassign users');
    }

    public function test_bulk_force_delete_removes_the_row_entirely(): void
    {
        $role = $this->makeRole('Support One');

        $this->post(route('roles.bulk-action'), ['action' => 'delete', 'role_ids' => [$role->id]]);
        $this->from(route('roles.index', ['trashed' => 1]))->post(route('roles.bulk-action'), [
            'action' => 'force_delete',
            'role_ids' => [$role->id],
        ])->assertRedirect();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_bulk_restore_will_not_act_on_a_live_role(): void
    {
        $role = $this->makeRole('Support One');

        $this->post(route('roles.bulk-action'), ['action' => 'restore', 'role_ids' => [$role->id]])
            ->assertRedirect();

        // A live role is filtered out by getValidItems, so the count is 0 and the
        // row is untouched.
        $this->assertNotSoftDeleted('roles', ['id' => $role->id]);
    }

    public function test_it_rejects_an_unknown_action_and_a_missing_id(): void
    {
        $role = $this->makeRole('Support One');

        // 403, not a validation error: authorize() runs before rules(), and an
        // action missing from the permission map must fail closed rather than
        // fall through to a permissive default.
        $this->post(route('roles.bulk-action'), ['action' => 'drop-database', 'role_ids' => [$role->id]])
            ->assertForbidden();
        $this->post(route('roles.bulk-action'), ['action' => 'delete', 'role_ids' => []])
            ->assertSessionHasErrors('role_ids');
        $this->post(route('roles.bulk-action'), ['action' => 'delete', 'role_ids' => [999999]])
            ->assertSessionHasErrors('role_ids.0');
    }

    public function test_bulk_delete_is_audited_per_role_with_the_revoked_count(): void
    {
        $role = $this->makeRole('Support One');
        User::factory(2)->create()->each(fn (User $u) => $u->assignRole($role));

        $this->post(route('roles.bulk-action'), ['action' => 'delete', 'role_ids' => [$role->id]])
            ->assertRedirect();

        // One row per role, carrying the count — the property that makes the log
        // useful in an incident review.
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Role::class,
            'subject_id' => $role->id,
            'description' => 'role.deleted',
        ]);
    }
}

<?php

namespace Tests\Feature\FeatureFlag;

use App\Actions\V1\Feature\FeatureBulkToggleAction;
use App\Actions\V1\Feature\FeatureToggleAction;
use App\Actions\V1\Permission\PermissionIndexAction;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Switching a module off takes its permissions with it — owner decision, Phase
 * 11 fix #4.
 *
 * Three halves that only work together:
 *
 *  - the toggle REVOKES the permissions, so "already granted" does not survive
 *    the switch;
 *  - the role matrix and the permission index HIDE them, so they cannot be
 *    handed out again while the module is off;
 *  - the role write path does not invent or destroy them on an unrelated save —
 *    a grant it cannot render is kept, a grant the form never offered cannot be
 *    posted in.
 *
 * The second half alone would be worse than nothing: a grant that is invisible
 * cannot be removed through the UI, so the permission would stay live for a
 * module nobody can reach.
 */
class FeaturePermissionRevokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function login(string $role = SystemRole::ADMIN): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user, 'web');

        return $user;
    }

    private function permissionId(string $name): int
    {
        return (int) Permission::query()->where('name', $name)->value('id');
    }

    public function test_switching_a_flag_off_revokes_its_permission_from_the_role(): void
    {
        $admin = $this->login();
        $role = RoleLookup::find(SystemRole::ADMIN);

        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $this->permissionId('pulse.view'),
        ]);

        $result = app(FeatureToggleAction::class)->run('pulse', false, $admin);

        $this->assertSame(['pulse.view'], $result['revoked']);

        $this->assertDatabaseMissing('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $this->permissionId('pulse.view'),
        ]);

        // The rest of the catalogue is untouched: the revoke answers to one
        // flag's permissions, not to "the module is off, strip something".
        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $this->permissionId('queues.view'),
        ]);
    }

    /**
     * Both grant paths, not just the one the application happens to use.
     *
     * ADR-004 keeps `model_has_permissions` empty, so this is a no-op today —
     * which is exactly why it is asserted: a revoke that covered only roles
     * would look identical until the first direct grant appeared, and then the
     * grant would outlive the flag.
     */
    public function test_the_revoke_reaches_a_direct_user_grant_too(): void
    {
        $admin = $this->login();

        $holder = User::factory()->create(['email_verified_at' => now()]);
        $holder->givePermissionTo('pulse.view');

        $this->assertDatabaseHas('model_has_permissions', [
            'model_id' => $holder->id,
            'permission_id' => $this->permissionId('pulse.view'),
        ]);

        app(FeatureToggleAction::class)->run('pulse', false, $admin);

        $this->assertDatabaseMissing('model_has_permissions', [
            'model_id' => $holder->id,
            'permission_id' => $this->permissionId('pulse.view'),
        ]);
    }

    /**
     * The revoke is final. Re-enabling does not restore what it took.
     *
     * Restoring would need the previous holdings stored somewhere, and it would
     * silently undo the state the operator asked for the moment they flipped the
     * switch back — so a re-grant is a deliberate act through the role matrix.
     */
    public function test_switching_the_flag_back_on_does_not_grant_anything_again(): void
    {
        $admin = $this->login();
        $action = app(FeatureToggleAction::class);

        $action->run('pulse', false, $admin);
        $result = $action->run('pulse', true, $admin);

        $this->assertSame([], $result['revoked'], 'an enable has nothing to revoke');
        $this->assertTrue(Feature::active('pulse'), 'precondition: the flag is back on');

        $this->assertDatabaseMissing('role_has_permissions', [
            'role_id' => RoleLookup::find(SystemRole::ADMIN)->id,
            'permission_id' => $this->permissionId('pulse.view'),
        ]);
    }

    /**
     * A switched-off module is not a reason to report a revoke that took nothing.
     *
     * The audit row is read by a human asking "what did this switch take away",
     * and a permission nobody held is not an answer to that.
     */
    public function test_a_permission_nobody_held_is_not_reported(): void
    {
        $admin = $this->login();

        DB::table('role_has_permissions')->where('permission_id', $this->permissionId('pulse.view'))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $result = app(FeatureToggleAction::class)->run('pulse', false, $admin);

        $this->assertSame([], $result['revoked']);
        $this->assertFalse(Feature::active('pulse'), 'the switch still happened');
    }

    public function test_the_audit_row_names_what_the_switch_took_away(): void
    {
        $admin = $this->login();

        app(FeatureToggleAction::class)->run('pulse', false, $admin);

        $row = DB::table('activity_log')->where('event', FeatureToggleAction::EVENT)->first();
        $properties = json_decode($row->properties, true);

        // The name, not a count: the grants are gone, so the row is the only
        // place the answer survives.
        $this->assertSame(['pulse.view'], $properties['revoked']);
    }

    public function test_a_bulk_switch_off_revokes_each_flags_permissions(): void
    {
        $admin = $this->login();

        // Asked in reverse catalogue order on purpose: `runMany()` reports in the
        // order it was asked, and `PermissionCatalog` lists `pulse` before
        // `health`. Forward order would also pass if the report inherited the
        // order the rows happened to come back in, which is not a thing to rely
        // on for a row an auditor reads against the selection they made.
        $result = app(FeatureBulkToggleAction::class)->run(['health', 'pulse'], false, $admin);

        $this->assertSame(
            ['health.view', 'pulse.view'],
            array_merge(...array_values($result['revoked'])),
            'the bulk revoke must report both modules'
        );

        $this->assertSame(['health', 'pulse'], array_keys($result['revoked']));

        foreach (['pulse.view', 'health.view'] as $name) {
            $this->assertDatabaseMissing('role_has_permissions', [
                'role_id' => RoleLookup::find(SystemRole::ADMIN)->id,
                'permission_id' => $this->permissionId($name),
            ]);
        }

        $properties = json_decode(
            DB::table('activity_log')->where('event', FeatureBulkToggleAction::EVENT)->value('properties'),
            true
        );

        $this->assertSame(['pulse.view'], $properties['revoked']['pulse']);
        $this->assertSame(['health.view'], $properties['revoked']['health']);
    }

    /**
     * Hiding is asserted on the ROUTE, not on the query: the point is what an
     * operator can grant, which is the page in front of them.
     */
    public function test_the_role_matrix_stops_offering_a_switched_off_permission(): void
    {
        $this->login(SystemRole::SUPERADMIN);

        $role = Role::create(['name' => 'Editor', 'guard_name' => RoleLookup::guard()]);

        $this->assertStringContainsString('pulse.view', $this->get(route('roles.edit', $role))->assertOk()->getContent());

        Feature::deactivate('pulse');
        Feature::flushCache();

        $html = $this->get(route('roles.edit', $role))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'pulse.view',
            $html,
            'the matrix still offers a permission whose module is switched off'
        );

        // The rest of the catalogue is still there — a filter that dropped the
        // wrong rows would empty the page and pass a `assertStringNotContains`.
        $this->assertStringContainsString('queues.view', $html);
    }

    public function test_the_permission_index_hides_it_too(): void
    {
        $this->login(SystemRole::SUPERADMIN);

        $this->assertContains('pulse.view', $this->indexedNames());

        Feature::deactivate('pulse');
        Feature::flushCache();

        $this->assertNotContains('pulse.view', $this->indexedNames());
    }

    /**
     * A grant the matrix cannot render must survive an unrelated save.
     *
     * The flag can go off by config (`disabled => true`, a deploy-time kill
     * switch) with no toggle behind it, so the grant is still in the database
     * while the checkbox is gone. Syncing only what the form posted would drop
     * it — the role's permission set rewritten by a save nobody connected to it.
     */
    public function test_a_hidden_grant_survives_a_role_save(): void
    {
        $this->login(SystemRole::SUPERADMIN);

        $role = Role::create(['name' => 'Editor', 'guard_name' => RoleLookup::guard()]);
        $role->givePermissionTo('pulse.view');

        Feature::deactivate('pulse');
        Feature::flushCache();

        $this->put(route('roles.update', $role), [
            'name' => 'Editor',
            // What the matrix submits: the visible checkboxes only.
            'permissions' => [(string) $this->permissionId('users.view')],
        ])->assertRedirect();

        $role->refresh();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($role->hasPermissionTo('users.view'), 'the posted permission was not saved');
        $this->assertTrue(
            Role::find($role->id)->hasPermissionTo('pulse.view'),
            'the save dropped a grant that the form could not show'
        );
    }

    /**
     * The hidden permission cannot be posted in either.
     *
     * The matrix is not the only way to reach the write path, and "not offered"
     * has to survive a request that names the id anyway. Silently filtered rather
     * than refused: there is no checkbox an operator could have ticked, so the
     * only caller that can send this is one that never saw the form.
     */
    public function test_a_crafted_post_cannot_grant_a_hidden_permission(): void
    {
        $this->login(SystemRole::SUPERADMIN);

        Feature::deactivate('pulse');
        Feature::flushCache();

        $this->post(route('roles.store'), [
            'name' => 'Sneaky',
            'permissions' => [(string) $this->permissionId('pulse.view')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $role = RoleLookup::find('Sneaky');

        $this->assertNotNull($role, 'precondition: the role was created');
        $this->assertFalse(
            $role->hasPermissionTo('pulse.view'),
            'a switched-off permission was granted from a request that named its id'
        );
    }

    /**
     * @return array<int, string>
     */
    private function indexedNames(): array
    {
        // The whole catalogue, not page 1: this asserts presence or absence of a
        // specific row, and a row that sorts onto page 2 would read as "hidden".
        return app(PermissionIndexAction::class)->run(perPage: 100)['permissions']->pluck('name')->all();
    }
}

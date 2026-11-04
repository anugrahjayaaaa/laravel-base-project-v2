<?php

namespace Tests\Unit\Actions;

use App\Actions\V1\Role\RoleCreateAction;
use App\Actions\V1\Role\RoleDeleteAction;
use App\Actions\V1\Role\RoleForceDeleteAction;
use App\Actions\V1\Role\RoleIndexAction;
use App\Actions\V1\Role\RoleRestoreAction;
use App\Actions\V1\Role\RoleUpdateAction;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The role write paths, including the two refusals that protect the application:
 * a system role's definition cannot be edited, and trashing a role must not
 * leave its holders with nothing.
 */
class RoleActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    // === create / update ===

    public function test_creating_persists_the_permission_set_and_audits_its_names(): void
    {
        $permissions = Permission::query()->limit(2)->pluck('id')->all();

        $role = app(RoleCreateAction::class)->run(
            ['name' => 'reporter', 'permissions' => $permissions],
            $this->admin()
        );

        $this->assertSame(2, $role->permissions()->count());
        // The names, not just the count: "this role gained report export" is the
        // fact an incident review can use, and the pivot does not keep history.
        $this->assertCount(2, $this->audit('role.created')->properties['permissions']);
    }

    public function test_string_permission_ids_from_the_matrix_are_cast(): void
    {
        // Checkbox values arrive as strings; Spatie resolves a non-int as a
        // permission NAME and throws PermissionDoesNotExist for '19'.
        $permissions = Permission::query()->limit(1)->pluck('id')->map(fn ($id) => (string) $id)->all();

        $role = app(RoleCreateAction::class)->run(['name' => 'stringer', 'permissions' => $permissions]);

        $this->assertSame(1, $role->permissions()->count());
    }

    public function test_renaming_an_ordinary_role_is_allowed(): void
    {
        $role = $this->role('custom');

        app(RoleUpdateAction::class)->run($role, ['name' => 'renamed'], $this->admin());

        $this->assertSame('renamed', $role->fresh()->name);
    }

    public function test_renaming_a_system_role_is_refused(): void
    {
        $role = Role::findByName(SystemRole::ADMIN, 'web');

        $this->expectException(ValidationException::class);

        app(RoleUpdateAction::class)->run($role, ['name' => 'not-admin'], $this->admin());
    }

    public function test_a_system_role_cannot_be_renamed_into_something_editable(): void
    {
        $role = $this->role('custom');

        // The guard reads the STORED name for the system check and the incoming
        // one for the target: a custom role renamed TO `admin` must not become
        // the protected row, and `admin` itself must not escape.
        $this->expectException(ValidationException::class);

        app(RoleUpdateAction::class)->run(
            $role,
            ['name' => SystemRole::ADMIN, 'permissions' => []],
            $this->admin()
        );
    }

    public function test_editing_a_system_role_permission_set_is_refused(): void
    {
        $role = Role::findByName(SystemRole::SUPERADMIN, 'web');

        // One form post with no checkboxes would otherwise strip the whole admin
        // panel from the row, silently, with an audit row recording success.
        $this->expectException(ValidationException::class);

        app(RoleUpdateAction::class)->run($role, ['name' => SystemRole::SUPERADMIN, 'permissions' => []], $this->admin());
    }

    public function test_an_update_without_a_permissions_key_syncs_nothing(): void
    {
        $role = $this->role('custom', Permission::query()->limit(1)->pluck('id')->all());

        app(RoleUpdateAction::class)->run($role, ['name' => 'renamed'], $this->admin());

        $this->assertSame(1, $role->fresh()->permissions()->count());
    }

    // === delete ===

    public function test_a_system_role_cannot_be_trashed(): void
    {
        $this->expectException(ValidationException::class);

        app(RoleDeleteAction::class)->run(Role::findByName(SystemRole::USER, 'web'));
    }

    public function test_a_role_still_held_by_users_is_refused_without_force(): void
    {
        $role = $this->role('held');
        $holder = $this->user();
        $holder->assignRole($role);

        $this->expectException(ValidationException::class);

        app(RoleDeleteAction::class)->run($role);
    }

    public function test_force_trashing_detaches_holders_and_records_how_many_lost_it(): void
    {
        $role = $this->role('held');
        $holder = $this->user();
        $holder->assignRole($role);

        app(RoleDeleteAction::class)->run($role, $this->admin(), force: true);

        $this->assertSoftDeleted('roles', ['id' => $role->id]);
        $this->assertFalse($holder->fresh()->hasRole('held'));
        $this->assertSame(1, $this->audit('role.deleted')->properties['revoked_users']);
    }

    public function test_a_holder_left_with_nothing_falls_back_to_the_default_role(): void
    {
        SystemSetting::set('registration_default_role', SystemRole::USER);

        $role = $this->role('only-role');
        $holder = $this->user();
        $holder->assignRole($role);

        app(RoleDeleteAction::class)->run($role, $this->admin(), force: true);

        $this->assertSame([SystemRole::USER], $holder->fresh()->getRoleNames()->all());
        $this->assertSame(1, $this->audit('role.deleted')->properties['reassigned_to_default']);
    }

    public function test_a_holder_who_keeps_another_role_is_not_rewritten(): void
    {
        $role = $this->role('secondary');
        $holder = $this->user();
        $holder->assignRole($role);
        $holder->assignRole(SystemRole::ADMIN);

        app(RoleDeleteAction::class)->run($role, $this->admin(), force: true);

        $this->assertSame([SystemRole::ADMIN], $holder->fresh()->getRoleNames()->all());
        $this->assertSame(0, $this->audit('role.deleted')->properties['reassigned_to_default']);
    }

    public function test_the_fallback_never_promotes_to_superadmin(): void
    {
        SystemSetting::set('registration_default_role', SystemRole::SUPERADMIN);

        $role = $this->role('only-role');
        $holder = $this->user();
        $holder->assignRole($role);

        app(RoleDeleteAction::class)->run($role, $this->admin(), force: true);

        // Empty is the deliberate outcome: a role deletion is not an actor anyone
        // authorised to hand out the one restricted grant.
        $this->assertCount(0, $holder->fresh()->getRoleNames());
    }

    // === restore / force delete ===

    public function test_restoring_brings_the_definition_back_without_the_assignment(): void
    {
        $role = $this->role('retired', Permission::query()->limit(1)->pluck('id')->all());
        $holder = $this->user();
        $holder->assignRole($role);
        app(RoleDeleteAction::class)->run($role, $this->admin(), force: true);

        app(RoleRestoreAction::class)->run($role->fresh(), $this->admin());

        $restored = $role->fresh();
        $this->assertNull($restored->deleted_at);
        $this->assertSame(1, $restored->permissions()->count());
        // The un-do of a role grant has to be deliberate.
        $this->assertFalse($holder->fresh()->hasRole('retired'));
        $this->assertSame(0, $this->audit('role.restored')->properties['reassigned_users']);
    }

    public function test_a_live_role_cannot_be_force_deleted(): void
    {
        $this->expectException(ValidationException::class);

        app(RoleForceDeleteAction::class)->run($this->role('live'));
    }

    public function test_a_system_role_cannot_be_force_deleted(): void
    {
        $this->expectException(ValidationException::class);

        app(RoleForceDeleteAction::class)->run(Role::findByName(SystemRole::ADMIN, 'web'));
    }

    public function test_force_deleting_a_trashed_role_records_what_it_took_with_it(): void
    {
        $permissions = Permission::query()->limit(2)->pluck('id')->all();
        $role = $this->role('gone', $permissions);
        $holder = $this->user();
        $holder->assignRole($role);

        app(RoleDeleteAction::class)->run($role, $this->admin(), force: true);
        app(RoleForceDeleteAction::class)->run($role->fresh(), $this->admin());

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);

        // The pivot rows are gone by then, so the counts are unrecoverable unless
        // the row captures them first. `revoked_users` is 0 by construction: the
        // trash already detached every holder, so a second count would report
        // nobody — the number an incident review wants is on `role.deleted`.
        $row = $this->audit('role.force_deleted');
        $this->assertSame('gone', $row->properties['role_name']);
        $this->assertSame(0, $row->properties['revoked_users']);
        $this->assertSame(2, $row->properties['revoked_permissions']);
    }

    // === index ===

    public function test_an_unknown_sort_column_falls_back_to_name_instead_of_reaching_order_by(): void
    {
        $this->role('zulu');
        $this->role('alpha');

        $page = app(RoleIndexAction::class)->run(null, 'name); drop table roles', 'asc', 100);
        $names = collect($page->items())->pluck('name')->values();

        // The fallback still SORTS — proving the whitelist replaced the value
        // rather than silently dropping the order clause.
        $this->assertSame($names->sort()->values()->all(), $names->all());
    }

    public function test_the_superadmin_role_is_listed_only_for_a_superadmin_viewer(): void
    {
        $superadmin = $this->user();
        $superadmin->assignRole(SystemRole::SUPERADMIN);

        $visibleToOthers = collect(app(RoleIndexAction::class)->run(perPage: 100, viewer: $this->user())->items())->pluck('name');
        $visibleToSuperadmin = collect(app(RoleIndexAction::class)->run(perPage: 100, viewer: $superadmin)->items())->pluck('name');

        $this->assertNotContains(SystemRole::SUPERADMIN, $visibleToOthers);
        $this->assertContains(SystemRole::SUPERADMIN, $visibleToSuperadmin);
    }

    // === transaction boundary ===

    public function test_a_creation_rolls_the_role_row_back_when_the_audit_write_fails(): void
    {
        $this->failAuditWrites();

        try {
            app(RoleCreateAction::class)->run(['name' => 'reporter', 'permissions' => [Permission::query()->value('id')]]);
            $this->fail('The action completed with a broken audit write.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseMissing('roles', ['name' => 'reporter', 'guard_name' => RoleLookup::guard()]);
    }

    public function test_an_update_rolls_the_name_and_permission_set_back_when_the_audit_write_fails(): void
    {
        $permission = Permission::query()->value('id');
        $role = $this->role('reporter');

        $this->failAuditWrites();

        try {
            app(RoleUpdateAction::class)->run($role, ['name' => 'renamed', 'permissions' => [$permission]], $this->admin());
            $this->fail('The action completed with a broken audit write.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('reporter', $role->fresh()->name);
        $this->assertSame(0, $role->fresh()->permissions()->count());
    }

    // === helpers ===

    private function failAuditWrites(): void
    {
        Event::listen('eloquent.creating: '.Activity::class, fn () => throw new RuntimeException('audit write failed'));
    }

    /**
     * @param  array<int, int|string>  $permissions
     */
    private function role(string $name, array $permissions = []): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        return $role;
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function admin(): User
    {
        $admin = $this->user();
        $admin->assignRole(SystemRole::ADMIN);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    private function audit(string $event): Activity
    {
        return Activity::query()->where('event', $event)->latest('id')->firstOrFail();
    }
}

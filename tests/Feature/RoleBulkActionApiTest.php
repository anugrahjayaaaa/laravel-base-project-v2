<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `POST /api/v1/roles/bulk-action` — the API counterpart of the web bulk bar.
 *
 * Found by a route audit rather than by a failing test: users and features had a
 * bulk endpoint on BOTH surfaces, roles had one only in the browser, so a
 * non-browser client could retire a batch of roles only by calling the
 * single-role endpoint once per role. Nothing failed, because nothing asserted
 * that the two surfaces were equally complete — `ApiRoleAuditTrailTest` walks
 * every role mutation it knows about, and bulk was not one it knew about.
 *
 * The controller holds no logic: it delegates to the same `BulkActionProcessor`
 * and `RoleBulkActionHandler` the web bar uses, so the per-action permission,
 * the selection cap, the system-role guard and the per-subject audit rows are
 * inherited rather than restated. These tests are about the parts that are
 * genuinely new — that the route exists, that the answer is JSON, and that the
 * authorization did not get looser by arriving on a second surface.
 */
class RoleBulkActionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeRole(string $name): Role
    {
        return Role::create(['name' => $name, 'guard_name' => RoleLookup::guard()]);
    }

    private function operator(): User
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::ADMIN));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * The endpoint exists and answers JSON — the whole point of the addition.
     */
    public function test_a_role_can_be_trashed_through_the_api(): void
    {
        $admin = $this->operator();
        $role = $this->makeRole('bulk-trash-me');

        $this->actingAs($admin, 'sanctum')
            ->postJson(route('api.v1.roles.bulk-action'), [
                'action' => 'delete',
                'role_ids' => [$role->getKey()],
            ])
            ->assertOk()
            ->assertJsonPath('data.message', 'moved to trash (1 roles).');

        $this->assertTrue($role->fresh()->trashed(), 'precondition: the role was trashed');
    }

    /**
     * Restoring a trashed role through the API, because restore is the branch
     * that reads `onlyTrashed()` and is the one most likely to answer 404 when
     * the binding is copied carelessly.
     */
    public function test_a_trashed_role_can_be_restored_through_the_api(): void
    {
        $admin = $this->operator();
        $role = $this->makeRole('bulk-restore-me');
        $role->delete();

        $this->actingAs($admin, 'sanctum')
            ->postJson(route('api.v1.roles.bulk-action'), [
                'action' => 'restore',
                'role_ids' => [$role->getKey()],
            ])
            ->assertOk();

        $this->assertFalse($role->fresh()->trashed(), 'the role was not restored');
    }

    /**
     * The per-action permission, which is the whole authorization story on a
     * bulk route: the permission depends on WHICH action was requested, so the
     * route carries no `can()` and `BulkRoleRequest` decides.
     *
     * Holding the permission for a trash must not authorize a force-delete, and
     * an action nobody holds must not go through on a route that looks open.
     */
    public function test_the_permission_follows_the_requested_action(): void
    {
        // `roles.restore` and NOT `roles.force_delete`.
        $role = \Spatie\Permission\Models\Role::create([
            'name' => 'restorer',
            'guard_name' => RoleLookup::guard(),
        ]);
        $role->givePermissionTo('roles.restore');

        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $trashable = $this->makeRole('allowed');
        $trashable->delete();

        // The action they hold: allowed.
        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.roles.bulk-action'), [
                'action' => 'restore',
                'role_ids' => [$trashable->getKey()],
            ])
            ->assertOk();

        // The action they do not: refused, and nothing happens.
        $other = $this->makeRole('not-allowed');
        $other->delete();

        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.roles.bulk-action'), [
                'action' => 'force_delete',
                'role_ids' => [$other->getKey()],
            ])
            ->assertForbidden();

        $this->assertNotNull(
            Role::onlyTrashed()->find($other->getKey()),
            'a refused bulk action still permanently deleted the row'
        );
    }

    /**
     * A user with no role permissions at all cannot reach it.
     */
    public function test_a_user_without_role_permissions_is_refused(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::USER));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = $this->makeRole('untouched');

        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.roles.bulk-action'), [
                'action' => 'delete',
                'role_ids' => [$role->getKey()],
            ])
            ->assertForbidden();

        $this->assertFalse($role->fresh()->trashed());
    }

    /**
     * The selection cap and the id validation, inherited from `BulkRoleRequest`.
     *
     * Asserted here because the cap exists to bound what ONE call can hand the
     * handler, and this is the surface a script drives rather than a browser
     * constrained by a rendered page.
     */
    public function test_the_selection_cap_and_id_validation_apply(): void
    {
        $admin = $this->operator();

        $ids = [];

        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->makeRole('cap-'.$i)->getKey();
        }

        $this->actingAs($admin, 'sanctum')
            ->postJson(route('api.v1.roles.bulk-action'), ['action' => 'delete', 'role_ids' => $ids])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_ids');

        $this->actingAs($admin, 'sanctum')
            ->postJson(route('api.v1.roles.bulk-action'), [
                'action' => 'delete',
                'role_ids' => [999_999],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_ids.0');
    }

    /**
     * Audit integrity: one row per role, from the handler's own action, not one
     * aggregate row from the controller.
     *
     * The single-row trap is the reason the controller writes nothing: a
     * `role.bulk_deleted` row would name the same roles again with none of
     * `revoked_users` and `revoked_permissions`.
     */
    public function test_each_role_writes_its_own_audited_row(): void
    {
        $admin = $this->operator();
        $first = $this->makeRole('audited-one');
        $second = $this->makeRole('audited-two');

        $this->actingAs($admin, 'sanctum')
            ->postJson(route('api.v1.roles.bulk-action'), [
                'action' => 'delete',
                'role_ids' => [$first->getKey(), $second->getKey()],
            ])
            ->assertOk();

        $rows = Activity::where('event', 'role.deleted')->get();

        $this->assertCount(2, $rows, 'expected one audit row per role, not one for the batch');
        $this->assertSame(
            [$first->getKey(), $second->getKey()],
            $rows->pluck('subject_id')->sort()->values()->all(),
            'the audit rows do not name the roles that were deleted'
        );
    }

    /**
     * The parity this endpoint was missing, asserted directly.
     *
     * Every web bulk endpoint has an API twin. This is the assertion that would
     * have failed before the route existed, and it fails by NAME rather than by
     * route list, so a removal says which resource lost its surface.
     */
    #[DataProvider('bulkEndpoints')]
    public function test_every_web_bulk_endpoint_has_an_api_counterpart(string $webName, string $apiName): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has($apiName),
            "{$webName} has no API counterpart ({$apiName}) — a non-browser client cannot do this action"
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bulkEndpoints(): array
    {
        return [
            'users' => ['users.bulk-action', 'api.v1.users.bulk-action'],
            'roles' => ['roles.bulk-action', 'api.v1.roles.bulk-action'],
            'features' => ['features.bulk-action', 'api.v1.features.bulk-action'],
        ];
    }
}

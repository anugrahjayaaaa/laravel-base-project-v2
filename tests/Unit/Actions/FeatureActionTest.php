<?php

namespace Tests\Unit\Actions;

use App\Actions\V1\Feature\FeatureBulkToggleAction;
use App\Actions\V1\Feature\FeatureRevokePermissionsAction;
use App\Actions\V1\Feature\FeatureToggleAction;
use App\Models\Role;
use App\Models\User;
use App\Support\FeatureCatalog;
use App\Support\PermissionCatalog;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Feature;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The flag switch, its permission revocation and the batch variant.
 *
 * Three properties carry the weight: an undeclared slug is refused before
 * anything is written, switching a module off also removes the access it was
 * gating, and one batch produces one audit row.
 */
class FeatureActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    // === single toggle ===

    public function test_an_undeclared_slug_is_refused_before_anything_is_written(): void
    {
        // Pennant would store any string, and a row that exists in the database
        // and in no gate looks real to the next reader.
        $this->expectException(NotFoundHttpException::class);

        app(FeatureToggleAction::class)->run('not-a-flag', false);
    }

    public function test_toggling_records_both_states_in_the_audit_row(): void
    {
        $result = app(FeatureToggleAction::class)->run('sessions', false, $this->admin());

        $this->assertTrue($result['from']);
        $this->assertFalse($result['to']);

        // Without `from` the row says something changed but not what it changed
        // from, which is the half an auditor needs.
        $row = $this->audit(FeatureToggleAction::EVENT);
        $this->assertTrue($row->properties['from']);
        $this->assertFalse($row->properties['to']);
        $this->assertSame('sessions', $row->properties['feature']);
    }

    public function test_switching_a_module_off_revokes_the_permissions_it_gated(): void
    {
        $permission = PermissionCatalog::forFeature('roles')[0];
        $role = Role::create(['name' => 'delegated', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $result = app(FeatureToggleAction::class)->run('roles', false, $this->admin());

        $this->assertContains($permission, $result['revoked']);
        $this->assertCount(0, $role->fresh()->permissions);
        $this->assertSame($result['revoked'], $this->audit(FeatureToggleAction::EVENT)->properties['revoked']);
    }

    public function test_switching_a_module_back_on_does_not_restore_the_grants(): void
    {
        $permission = PermissionCatalog::forFeature('roles')[0];
        $role = Role::create(['name' => 'delegated', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $action = app(FeatureToggleAction::class);
        $action->run('roles', false, $this->admin());
        $reenabled = $action->run('roles', true, $this->admin());

        // A re-enable is not an un-revoke: the rows were deleted, and silently
        // handing them back is how a retired grant comes back to life.
        $this->assertSame([], $reenabled['revoked']);
        $this->assertCount(0, $role->fresh()->permissions);
    }

    // === revoke helper ===

    public function test_revoking_reports_only_permissions_somebody_held(): void
    {
        $all = PermissionCatalog::forFeature('roles');
        $held = $all[0];

        // The seeder hands every permission to the system roles, so clear those
        // first — otherwise every permission in the set has a holder and the
        // "only what was held" rule is untestable.
        foreach (Role::whereIn('name', SystemRole::names())->get() as $systemRole) {
            $systemRole->revokePermissionTo($all);
        }

        $role = Role::create(['name' => 'delegated', 'guard_name' => 'web']);
        $role->givePermissionTo($held);

        $revoked = app(FeatureRevokePermissionsAction::class)->run('roles');

        // A permission nobody held is not reported: nothing was taken from
        // anyone, and claiming otherwise misreads the audit row.
        $this->assertSame([$held], $revoked);
    }

    public function test_revoking_a_flag_with_no_permissions_is_a_no_op(): void
    {
        $this->assertSame([], app(FeatureRevokePermissionsAction::class)->run('sessions'));
    }

    // === bulk ===

    public function test_a_batch_dedupes_the_selection_and_reports_what_moved(): void
    {
        $result = app(FeatureBulkToggleAction::class)->run(['sessions', 'health', 'sessions'], false);

        $this->assertCount(2, $result['changed']);
        $this->assertSame([], $result['unchanged']);
    }

    public function test_a_flag_already_in_the_requested_state_is_reported_as_unchanged(): void
    {
        $action = app(FeatureBulkToggleAction::class);
        $action->run(['sessions'], false);

        $result = $action->run(['sessions', 'health'], false);

        $this->assertSame(['sessions'], $result['unchanged']);
        $this->assertSame(['health'], array_column($result['changed'], 'slug'));
    }

    public function test_one_undeclared_slug_refuses_the_whole_batch(): void
    {
        $this->expectException(NotFoundHttpException::class);

        try {
            app(FeatureBulkToggleAction::class)->run(['sessions', 'invented'], false);
        } finally {
            // Validated up front: not even the real slug moved.
            $this->assertSame(0, Activity::query()->where('event', FeatureBulkToggleAction::EVENT)->count());
        }
    }

    public function test_a_batch_writes_one_audit_row_for_the_request(): void
    {
        app(FeatureBulkToggleAction::class)->run(['sessions', 'health'], false, $this->admin());

        $rows = Activity::query()->where('event', FeatureBulkToggleAction::EVENT)->get();

        // The unit an operator reasons about is "I turned off three flags";
        // three rows cannot say that two of them were asked for as a group.
        $this->assertCount(1, $rows);
        $this->assertSame(['sessions', 'health'], $rows->first()->properties['requested']);
        $this->assertSame(2, $rows->first()->properties['count']);
    }

    // === helpers ===

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(SystemRole::ADMIN);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    // === transaction boundary ===

    public function test_a_toggle_rolls_the_flag_and_its_revoked_grants_back_when_the_audit_write_fails(): void
    {
        $permission = PermissionCatalog::forFeature('roles')[0];
        $role = Role::create(['name' => 'delegated', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $this->failAuditWrites();

        try {
            app(FeatureToggleAction::class)->run('roles', false, $this->admin());
            $this->fail('The action completed with a broken audit write.');
        } catch (RuntimeException) {
            // expected
        }

        Feature::flushCache();

        $this->assertTrue(FeatureCatalog::isActive('roles'));
        $this->assertTrue($role->fresh()->hasPermissionTo($permission));
    }

    private function failAuditWrites(): void
    {
        Event::listen('eloquent.creating: '.Activity::class, fn () => throw new RuntimeException('audit write failed'));
    }

    private function audit(string $event): Activity
    {
        return Activity::query()->where('event', $event)->latest('id')->firstOrFail();
    }
}

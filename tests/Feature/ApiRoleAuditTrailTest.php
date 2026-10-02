<?php

namespace Tests\Feature;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The API role surface writes the same audit rows the web one does.
 *
 * ## Why this file exists
 *
 * An audit reported the API role controller as having no audit calls at all,
 * which would have made privilege changes over the API invisible. Grepping the
 * controller is the wrong place to look: role actions are audited INSIDE the
 * action (`PersistsRole::persist()`, `RoleDeleteAction`, `RoleRestoreAction`,
 * `RoleForceDeleteAction`) and the controller only orchestrates. Every one of
 * the five methods is covered here so the conclusion is checked by running the
 * routes, not by reading a grep.
 *
 * The same property is asserted for the two columns that matter: `event` must be
 * set (every reader filters on it) and the causer must be the caller. A row
 * written with `event` NULL exists and is still invisible to every filter.
 */
class ApiRoleAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->admin->assignRole(RoleLookup::find(SystemRole::SUPERADMIN));
        $this->actingAs($this->admin, 'sanctum');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // VerifyCsrfToken::runningUnitTests() is hardcoded false in this app, and
        // the bulk half below is a web POST.
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function makeRole(string $name): int
    {
        $this->postJson(route('api.v1.roles.store'), ['name' => $name, 'permissions' => []])
            ->assertCreated();

        return (int) DB::table('roles')->where('name', $name)->value('id');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mutations(): array
    {
        return [
            'create' => ['create', 'role.created'],
            'update' => ['update', 'role.updated'],
            'trash' => ['trash', 'role.deleted'],
            'restore' => ['restore', 'role.restored'],
            'force delete' => ['force delete', 'role.force_deleted'],
        ];
    }

    #[Test]
    #[DataProvider('mutations')]
    public function every_api_role_mutation_writes_an_audited_row(string $mutation, string $event): void
    {
        $id = $this->makeRole('Audit Probe');

        match ($mutation) {
            'create' => null,
            'update' => $this->putJson(route('api.v1.roles.update', $id), [
                'name' => 'Audit Probe Renamed',
                'permissions' => [],
            ])->assertOk(),
            'trash' => $this->deleteJson(route('api.v1.roles.destroy', $id))->assertOk(),
            'restore' => tap($this->deleteJson(route('api.v1.roles.destroy', $id)), function () use ($id) {
                $this->postJson(route('api.v1.roles.restore', $id))->assertOk();
            }),
            'force delete' => $this->deleteJson(route('api.v1.roles.destroy', $id))
                ->assertOk()
                && $this->deleteJson(route('api.v1.roles.force-delete', $id))->assertOk(),
        };

        $rows = DB::table('activity_log')->where('subject_id', $id)->get();

        $this->assertTrue(
            $rows->contains(fn ($r) => $r->event === $event),
            "[{$mutation}] wrote no `{$event}` row with a non-null event column; got: "
                .$rows->pluck('event')->implode(', ')
        );

        $row = $rows->firstWhere('event', $event);

        $this->assertSame(
            (int) $this->admin->id,
            (int) $row->causer_id,
            "[{$mutation}] did not attribute the row to the caller"
        );
    }

    /**
     * The specific defect `bulkAudit()` had: a row whose `event` column is NULL.
     *
     * It wrote `description` and left `event` empty, so every `where('event', …)`
     * in this codebase — and any viewer built on that column — skipped it. Bulk
     * user actions were affected on both web and API; the fix is one line in the
     * shared helper, and this is what pins it.
     */
    #[Test]
    public function a_bulk_user_action_writes_a_row_that_event_filters_can_find(): void
    {
        $users = User::factory()->count(2)->create();

        $this->post(route('users.bulk-action'), [
            'action' => 'deactivate',
            'user_ids' => $users->pluck('id')->all(),
        ])->assertRedirect();

        $rows = DB::table('activity_log')
            ->where('subject_type', User::class)
            ->whereIn('subject_id', $users->pluck('id')->all())
            ->get();

        $this->assertCount(2, $rows, 'bulk deactivate wrote the wrong number of rows');

        foreach ($rows as $row) {
            $this->assertNotNull(
                $row->event,
                'a bulk row left `event` NULL, so every event filter skips it'
            );
            $this->assertSame('user.deactivate', $row->event);
        }

        // One request is one user action, so the rows must be correlatable.
        $uuids = $rows->pluck('batch_uuid')->unique();
        $this->assertCount(1, $uuids, 'bulk rows carry different batch_uuids, so they are not one action');
    }
}

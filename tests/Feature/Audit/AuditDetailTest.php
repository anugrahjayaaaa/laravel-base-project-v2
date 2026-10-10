<?php

namespace Tests\Feature\Audit;

use App\Actions\V1\Role\RoleForceDeleteAction;
use App\Actions\V1\User\UserCancelEmailChangeAction;
use App\Actions\V1\User\UserCreateAction;
use App\Actions\V1\User\UserDeleteAction;
use App\Actions\V1\User\UserForceDeleteAction;
use App\Actions\V1\User\UserRestoreAction;
use App\Models\Activity;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A destructive or creating event records WHAT it changed.
 *
 * `audit-trail.md` requires that properties say what changed, not that
 * something changed — "a role was updated is not actionable during an
 * incident". Seven events carried no properties at all, and they are not the
 * harmless ones:
 *
 * - `user.force_deleted` and `role.force_deleted` are the two events after
 *   which NOTHING about the removed thing survives anywhere in the database.
 *   Whatever the row omits is gone permanently, which is why the identity is
 *   captured BEFORE the delete rather than read back after it.
 * - `user.restored` cannot tell the two restore shapes apart: `restore()`
 *   alone leaves the account trashed and only the caller clears `is_locked`.
 *
 * Split from `AuditActorTest` deliberately — that one asks "who did this",
 * this one asks "to what". Both read the same rows; the failures they report
 * are different and the fix for each lives in a different argument list.
 */
class AuditDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->admin->assignRole(RoleLookup::find('admin'));
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(string $event): array
    {
        $row = Activity::query()->where('event', $event)->latest('id')->firstOrFail();

        return $row->properties instanceof Collection ? $row->properties->toArray() : [];
    }

    /**
     * The delete events carry the identity of what was removed.
     *
     * `user.force_deleted` is the one that matters most: after it runs there is
     * no record of the account anywhere in the database, so whatever this row
     * omits is gone permanently.
     */
    #[Test]
    public function test_a_soft_delete_records_the_address_it_removed(): void
    {
        $target = User::factory()->create(['email' => 'doomed@example.test']);

        app(UserDeleteAction::class)->run($target, $this->admin);

        $properties = $this->properties('user.deleted');

        $this->assertSame('doomed@example.test', $properties['target_email'] ?? null);
        $this->assertSame($target->id, $properties['target_id'] ?? null);
    }

    #[Test]
    public function test_a_force_delete_records_the_identity_that_no_longer_exists(): void
    {
        $target = User::factory()->create([
            'email' => 'gone@example.test',
            'username' => 'gone.user',
        ]);

        app(UserDeleteAction::class)->run($target, $this->admin);
        app(UserForceDeleteAction::class)->run($target->fresh(), $this->admin);

        $properties = $this->properties('user.force_deleted');

        $this->assertSame('gone@example.test', $properties['target_email'] ?? null);
        $this->assertSame('gone.user', $properties['target_username'] ?? null);
        $this->assertSame($target->id, $properties['target_id'] ?? null);

        // The point of capturing it: the subject is genuinely gone, so this row is
        // the only place the address survives.
        $this->assertSame(0, DB::table('users')->where('id', $target->id)->count());
        $this->assertNull(
            Activity::query()->where('event', 'user.force_deleted')->latest('id')->firstOrFail()->subject,
            'precondition: no subject remains, so this row is the only record of the address'
        );
    }

    #[Test]
    public function test_a_restore_records_whether_it_reactivated_the_account(): void
    {
        $target = User::factory()->create(['is_active' => false, 'is_locked' => true]);
        app(UserDeleteAction::class)->run($target, $this->admin);

        app(UserRestoreAction::class)->run($target->fresh(), true, $this->admin);

        $properties = $this->properties('user.restored');

        $this->assertTrue($properties['reactivated'] ?? null, 'the flag that distinguishes the two restore shapes');
        $this->assertSame($target->email, $properties['target_email'] ?? null);
    }

    #[Test]
    public function test_a_role_force_delete_records_what_it_took_with_it(): void
    {
        $role = Role::create(['name' => 'auditor', 'guard_name' => RoleLookup::guard()]);
        $role->givePermissionTo('audit.view');
        $this->admin->assignRole($role);

        // Trashed first: the action refuses a permanent delete of a live role.
        // The pivot rows SURVIVE the soft delete, which is what makes the counts
        // still available at force-delete time.
        $role->delete();

        app(RoleForceDeleteAction::class)->run($role->fresh(), $this->admin);

        $properties = $this->properties('role.force_deleted');

        // Counted before the delete, because afterwards the pivot rows are gone
        // and the number cannot be recovered.
        $this->assertSame(1, $properties['revoked_users'] ?? null);
        $this->assertSame(1, $properties['revoked_permissions'] ?? null);
        $this->assertSame('auditor', $properties['role_name'] ?? null);
    }

    #[Test]
    public function test_cancelling_an_email_change_records_which_address_was_abandoned(): void
    {
        $target = User::factory()->create(['pending_email' => 'takeover@example.test']);

        app(UserCancelEmailChangeAction::class)->run($target, $this->admin);

        $this->assertSame(
            'takeover@example.test',
            $this->properties('user.email_change_cancelled')['cancelled_pending_email'] ?? null,
            'the destination is nulled one line after the read; without it the cancel says nothing'
        );
    }

    #[Test]
    public function test_creating_an_account_records_what_was_created(): void
    {
        app(UserCreateAction::class)->run([
            'name' => 'Made By Admin',
            'username' => 'made.by.admin',
            'email' => 'made@example.test',
        ], 'Adm1n-Pass!', $this->admin);

        $properties = $this->properties('user.created');

        $this->assertSame('made@example.test', $properties['target_email'] ?? null);
        $this->assertSame('made.by.admin', $properties['target_username'] ?? null);
    }
}

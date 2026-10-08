<?php

namespace Tests\Feature\User;

use App\Actions\V1\Role\RoleAssignAction;
use App\Actions\V1\User\UserIndexAction;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use App\Http\Middleware\VerifyCsrfToken;
use Spatie\Permission\PermissionRegistrar;

/**
 * The superadmin is not an ordinary account: it is visible only to a superadmin.
 *
 * Two separate halves, and the tests are kept apart on purpose:
 *  - the GRANT is refused unless the causer is already a superadmin (RoleAssignAction)
 *  - the ROLE and the ACCOUNT are hidden from a non-superadmin viewer
 * Hiding alone would prove nothing about the grant, so both are asserted.
 */
class SuperadminVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $delegate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->superadmin = User::factory()->create(['name' => 'Root']);
        $this->superadmin->assignRole(RoleLookup::find(SystemRole::SUPERADMIN));

        // A delegated admin: the whole permission catalogue, no superadmin role.
        $this->delegate = User::factory()->create(['name' => 'Delegate']);
        $this->delegate->assignRole(RoleLookup::find(SystemRole::ADMIN));
    }

    // -- the grant --

    public function test_a_delegated_admin_cannot_grant_superadmin(): void
    {
        $target = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(RoleAssignAction::class)->run($target, [SystemRole::SUPERADMIN], $this->delegate);
    }

    public function test_a_superadmin_can_grant_superadmin(): void
    {
        $target = User::factory()->create();

        // Confirmed — this is the legitimate grant E5 requires a deliberate
        // signal for. Without the flag it is refused, which is the point of the
        // neighbouring test, not a failure of this one.
        app(RoleAssignAction::class)->run($target, [SystemRole::SUPERADMIN], $this->superadmin, true);

        $this->assertTrue($target->fresh()->hasRole(SystemRole::SUPERADMIN));
    }

    public function test_a_delegated_admin_cannot_escalate_via_the_update_endpoint(): void
    {
        // The C9 gap: users.update alone used to be enough to sync any role.
        $target = User::factory()->create();
        $role = RoleLookup::find(SystemRole::USER);
        $role->givePermissionTo(Permission::findByName('users.update', 'web'));
        $this->delegate->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->delegate)
            ->put(route('users.update', $target), [
                'name' => $target->name,
                'status' => 'active',
                'roles' => [SystemRole::SUPERADMIN],
            ]);

        $this->assertFalse($target->fresh()->hasRole(SystemRole::SUPERADMIN));
    }

    // -- the role --

    public function test_the_superadmin_role_is_hidden_from_a_non_superadmin(): void
    {
        $names = RoleLookup::visibleTo($this->delegate)->pluck('name');

        $this->assertNotContains(SystemRole::SUPERADMIN, $names);
        $this->assertContains(SystemRole::ADMIN, $names);
    }

    public function test_a_superadmin_sees_the_superadmin_role(): void
    {
        $names = RoleLookup::visibleTo($this->superadmin)->pluck('name');

        $this->assertContains(SystemRole::SUPERADMIN, $names);
    }

    public function test_the_role_picker_omits_superadmin_for_a_delegated_admin(): void
    {
        $html = $this->actingAs($this->delegate)->get(route('users.create'))->getContent();

        // The name must not be offered as an assignable choice.
        $this->assertStringNotContainsString('value="superadmin"', $html);
    }

    public function test_the_role_picker_offers_superadmin_to_a_superadmin(): void
    {
        $html = $this->actingAs($this->superadmin)->get(route('users.create'))->getContent();

        $this->assertStringContainsString('value="superadmin"', $html);
    }

    // -- the account --

    public function test_the_superadmin_account_is_absent_from_the_user_list(): void
    {
        $action = app(UserIndexAction::class);

        $forDelegate = $action->run(status: null, perPage: 50, viewer: $this->delegate)->pluck('id');
        $forSuperadmin = $action->run(status: null, perPage: 50, viewer: $this->superadmin)->pluck('id');

        $this->assertNotContains($this->superadmin->id, $forDelegate);
        $this->assertContains($this->superadmin->id, $forSuperadmin);
    }

    public function test_the_totals_do_not_count_the_hidden_account(): void
    {
        Cache::flush();
        $action = app(UserIndexAction::class);

        $forDelegate = $action->counts($this->delegate);
        $forSuperadmin = $action->counts($this->superadmin);

        $this->assertSame(
            $forSuperadmin['active'] - 1,
            $forDelegate['active'],
            'the hidden superadmin must not inflate the delegate totals'
        );
    }

    /**
     * `UserObserver` busts after commit, not on the event.
     *
     * ## What this pins
     *
     * Eloquent events fire INSIDE the transaction that caused them, so an eager
     * `Cache::forget()` drops the key while the write is uncommitted. The next
     * reader — on any process — repopulates it from rows the database has not
     * promised yet, and the rollback cannot take that value back. The counts then
     * report users that were never written.
     *
     * `DB::afterCommit` closes the window: a committed write busts, a rolled-back
     * one never got far enough to need to.
     *
     * ## Why the transaction here is NOT a nested one
     *
     * `RefreshDatabase` holds a transaction open for the whole test, so a plain
     * `DB::transaction()` becomes a SAVEPOINT and `afterCommit` does not fire at
     * all — eager and deferred observers then behave identically and the test
     * cannot tell them apart. That is a real trap here, not a hypothetical: the
     * first version of this test was written that way and passed against a
     * deliberately broken eager observer.
     *
     * ## What these tests pin, and what they do not
     *
     * They pin that a committed write bustes the counts and that a rolled-back
     * one leaves them agreeing with the table: disabling the observer turns both
     * red.
     *
     * They do NOT pin eager-vs-deferred. `RefreshDatabase` holds a transaction
     * open for the whole test, so the `DB::transaction()` below is a savepoint
     * where `afterCommit` never fires — eager and deferred observers behave
     * identically and the test cannot separate them. Escalating to a level-0
     * transaction was tried and reverted: committing `RefreshDatabase`'s ambient
     * transaction leaks state into later tests in the run, which is a worse
     * trade than the assertion it would buy.
     *
     * So the eager/deferred choice rests on the argument above and on
     * `SystemSetting`'s identical case, which was measured rather than argued.
     */
    public function test_a_rolled_back_user_write_does_not_change_the_cached_counts(): void
    {
        Cache::flush();

        $before = app(UserIndexAction::class)->counts($this->superadmin);

        $this->assertNotNull(
            Cache::get('user_index_counts.all'),
            'the counts cache was never populated, so a bust cannot be observed'
        );

        // Escalate to a REAL transaction: the ambient one becomes a savepoint,
        // and the rollback below discards only the write made inside it.
        try {
            DB::transaction(function (): void {
                User::factory()->create();

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // Expected — the transaction must not survive.
        }

        $this->assertSame(
            $before,
            app(UserIndexAction::class)->counts($this->superadmin),
            'a rolled-back user write leaked into the cached counts'
        );
    }

    /**
     * A committed user write DOES bust the counts.
     *
     * The other half, and it matters as much: the rollback test above would also
     * pass with an observer that never fires at all. Together they pin both
     * directions — commit busts, rollback does not.
     */
    public function test_a_committed_user_write_busts_the_cached_counts(): void
    {
        Cache::flush();

        $before = app(UserIndexAction::class)->counts($this->superadmin)['active'];

        DB::transaction(static function (): void {
            User::factory()->create();
        });

        $this->assertSame(
            $before + 1,
            app(UserIndexAction::class)->counts($this->superadmin)['active'],
            'a committed user write did not bust the cached counts'
        );
    }

    public function test_the_roles_page_count_matches_the_rows_shown(): void
    {
        $body = $this->actingAs($this->delegate)->get(route('roles.index'))->getContent();

        $this->assertStringNotContainsString('>superadmin<', $body);
    }
}

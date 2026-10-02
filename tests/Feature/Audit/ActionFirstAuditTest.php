<?php

namespace Tests\Feature\Audit;

use App\Actions\V1\BulkAction\BulkActionProcessor;
use App\Actions\V1\System\SystemSettingsUpdateAction;
use App\Actions\V1\User\UserBulkActionHandler;
use App\Actions\V1\User\UserDeleteAction;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The Action-first audit standard, pinned on the user delete path.
 *
 * State mutations are audited by the Action that performs them, inside that
 * action's transaction. Controllers orchestrate and do not audit. These three
 * tests are the properties that rule exists to guarantee:
 *
 *  1. one mutation, one record — a second writer (the controller) would
 *     double-count every deletion;
 *  2. every subject in a bulk mutation is recorded, because the bulk handler
 *     loops the same action rather than writing rows of its own;
 *  3. a rollback takes the audit row with it, so a failed mutation can never
 *     leave a record claiming it succeeded.
 */
class ActionFirstAuditTest extends TestCase
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

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // VerifyCsrfToken::runningUnitTests() is hardcoded false in this app.
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function deletedRows(int $userId)
    {
        return DB::table('activity_log')
            ->where('subject_type', User::class)
            ->where('subject_id', $userId)
            ->where('event', 'user.deleted')
            ->get();
    }

    /**
     * Every audit row for one subject, whatever the event.
     *
     * The count assertions below use this rather than an event filter: a
     * duplicate write usually differs in event name (an aggregate `user.delete`
     * next to an action's `user.deleted`), so filtering by event first would
     * hide exactly the defect being looked for.
     */
    private function rowsFor(int $userId)
    {
        return DB::table('activity_log')
            ->where('subject_type', User::class)
            ->where('subject_id', $userId)
            ->get();
    }

    /**
     * A single delete through the controller writes exactly one record.
     *
     * The controller used to add its own `$user->audit('user.deleted')` on top
     * of the action, which is the double-write this standard forbids.
     */
    #[Test]
    public function a_single_delete_writes_exactly_one_audit_record(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('users.destroy', $user))
            ->assertRedirect();

        $this->assertCount(1, $this->deletedRows($user->id), 'single delete wrote a duplicate audit record');

        $row = $this->deletedRows($user->id)->first();

        $this->assertSame((int) $this->admin->id, (int) $row->causer_id, 'audit record not attributed to the caller');
    }

    /**
     * A bulk delete records every deleted subject, one row each.
     *
     * `UserBulkActionHandler::deleteUsers()` loops UserDeleteAction, so the
     * records come from the same code path the row button uses — there is no
     * bulk-specific audit writer to keep in sync.
     */
    #[Test]
    public function a_bulk_delete_records_every_deleted_user(): void
    {
        $users = User::factory()->count(3)->create();

        $this->actingAs($this->admin);

        $result = app(BulkActionProcessor::class)->run(
            action: 'delete',
            ids: $users->pluck('id')->all(),
            causer: $this->admin,
            handler: app(UserBulkActionHandler::class),
        );

        $this->assertSame(3, $result['count']);

        foreach ($users as $user) {
            $this->assertCount(1, $this->deletedRows($user->id), "user {$user->id} has no single audit record");
            $this->assertTrue($user->fresh()->trashed(), 'user was not actually trashed');
        }
    }

    /**
     * The bulk bar must not add an aggregate row on top of the per-user ones.
     *
     * Two rows naming the same subject — one from the action, one from the
     * controller's aggregate write — is the same defect as the single-delete
     * double-write, one layer up. Driven through the HTTP route rather than the
     * processor, because the controller's skip is part of what is under test.
     */
    #[Test]
    public function a_bulk_delete_writes_no_aggregate_row(): void
    {
        $users = User::factory()->count(2)->create();

        $this->actingAs($this->admin)->post(route('users.bulk-action'), [
            'action' => 'delete',
            'user_ids' => $users->pluck('id')->all(),
        ])->assertRedirect();

        foreach ($users as $user) {
            $this->assertCount(1, $this->rowsFor($user->id), 'bulk delete wrote a duplicate audit record for one subject');
        }
    }

    /**
     * Every remaining single-user mutation writes exactly one row, through its
     * real route.
     *
     * A data provider rather than one test per event: the property is identical
     * for all six (exactly one row, attributed to the caller), and a provider
     * makes the next migrated action a one-line addition instead of a new
     * twenty-line test. Counts read every row for the subject, not just the
     * expected event, so an aggregate row written under a different name still
     * fails the assertion.
     *
     * @param  string  $event
     * @param  string  $method
     * @param  string  $routeName
     * @param  array<string, mixed>  $state  Starting state the route requires.
     */
    #[Test]
    #[DataProvider('singleMutationCases')]
    public function a_single_user_mutation_writes_exactly_one_audit_record(
        string $event,
        string $method,
        string $routeName,
        array $state,
    ): void {
        $subject = User::factory()->create($state);

        $this->actingAs($this->admin);

        $url = route($routeName, ['user' => $subject->id]);

        $method === 'DELETE'
            ? $this->delete($url)
            : $this->post($url);

        $rows = $this->rowsFor($subject->id)->where('event', $event);

        $this->assertCount(1, $rows, "{$event} did not write exactly one audit record");
        $this->assertSame(
            (int) $this->admin->id,
            (int) $rows->first()->causer_id,
            "{$event} was not attributed to the caller"
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    public static function singleMutationCases(): array
    {
        return [
            'activate' => ['user.activated', 'POST', 'users.activate', ['is_active' => false]],
            'deactivate' => ['user.deactivated', 'POST', 'users.deactivate', []],
            'lock' => ['user.locked', 'POST', 'users.lock', []],
            'unlock' => ['user.unlocked', 'POST', 'users.unlock', ['is_locked' => true]],
            'restore' => ['user.restored', 'POST', 'users.restore', ['deleted_at' => now()]],
            'destroy' => ['user.deleted', 'DELETE', 'users.destroy', []],
        ];
    }

    /**
     * A user state change records the target it affected, not just its id.
     *
     * `target_email` is redundant with the subject and that is the point: the
     * audit list is filtered on properties, and a state row carrying only a
     * subject id cannot be searched by the address it was applied to.
     */
    #[Test]
    public function a_state_change_records_the_target_it_affected(): void
    {
        $subject = User::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin)->post(route('users.activate', $subject))->assertRedirect();

        $row = $this->rowsFor($subject->id)->where('event', 'user.activated')->first();

        $this->assertNotNull($row, 'no user.activated row was written');

        $properties = json_decode($row->properties, true);

        $this->assertSame($subject->id, $properties['target_id'] ?? null);
        $this->assertSame($subject->email, $properties['target_email'] ?? null);
    }

    /**
     * A settings save is one transaction, and its audit row goes with it.
     *
     * `SystemSetting::set()` writes one row per key with no transaction of its
     * own, so before this the ~40-key loop could stop half way and leave a
     * password policy updated, a registration toggle not, and an audit row
     * claiming the whole save succeeded.
     *
     * Asserted against the table, not `SystemSetting::getString()`. That reader
     * goes through a `rememberForever` cache which a rolled-back write does not
     * roll back — it keeps serving the value the aborted transaction wrote, so
     * reading through it here would report a false failure for a save that
     * correctly changed nothing.
     */
    #[Test]
    public function a_rolled_back_settings_save_leaves_no_value_or_audit_row(): void
    {
        $before = DB::table('system_settings')->where('key', 'password_min_length')->value('value');

        $this->actingAs($this->admin);

        try {
            DB::transaction(function () {
                app(SystemSettingsUpdateAction::class)->run(
                    ['password_min_length' => '20'],
                    causer: $this->admin,
                );

                throw new RuntimeException('a later step failed');
            });
        } catch (RuntimeException) {
            // Expected: the enclosing transaction rolled back.
        }

        $this->assertSame(
            $before,
            DB::table('system_settings')->where('key', 'password_min_length')->value('value'),
            'a rolled-back settings save left a changed value behind'
        );

        $this->assertSame(
            0,
            DB::table('activity_log')->where('event', 'system_setting.updated')->count(),
            'a rolled-back settings save left an orphan audit row'
        );
    }

    /**
     * A committed settings save writes exactly one row, attributed to the caller.
     */
    #[Test]
    public function a_settings_save_writes_exactly_one_audit_record(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.update'), ['password_min_length' => 14])
            ->assertRedirect();

        $rows = DB::table('activity_log')->where('event', 'system_setting.updated')->get();

        $this->assertCount(1, $rows, 'the settings save did not write exactly one audit record');
        $this->assertSame((int) $this->admin->id, (int) $rows->first()->causer_id);
    }

    /**
     * A rolled-back mutation leaves no audit row behind.
     *
     * The action writes its record inside the transaction, so an outer failure
     * discards both the deletion and the claim that it happened.
     */
    #[Test]
    public function a_rolled_back_delete_leaves_no_audit_record(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin);

        try {
            DB::transaction(function () use ($user) {
                app(UserDeleteAction::class)->run($user, $this->admin);

                throw new RuntimeException('something downstream failed');
            });
        } catch (RuntimeException) {
            // Expected: the enclosing transaction rolled back.
        }

        $this->assertSame(0, $this->deletedRows($user->id)->count(), 'a rolled-back delete left an orphan audit row');
        $this->assertFalse($user->fresh()->trashed(), 'the delete was not rolled back');
    }
}

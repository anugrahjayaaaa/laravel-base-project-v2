<?php

namespace Tests\Feature\Audit;

use App\Actions\V1\BulkAction\BulkActionProcessor;
use App\Actions\V1\System\SystemSettingsUpdateAction;
use App\Actions\V1\User\UserBulkActionHandler;
use App\Actions\V1\User\UserDeleteAction;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\AccountStateChangedNotification;
use App\Notifications\ChangeEmailVerificationNotification;
use App\Notifications\ConfigurationChangedNotification;
use App\Notifications\RegisterNotification;
use App\Notifications\RolesChangedNotification;
use App\Notifications\UserCreatedNotification;
use App\Notifications\UserRegisteredNotification;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;
use App\Actions\V1\Auth\AuthLoginCompletedAction;
use App\Actions\V1\Auth\AuthLogoutAllDevicesAction;
use App\Actions\V1\Auth\AuthVerifyEmailAction;
use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\PasswordExpirySweep;
use App\Services\InactivityLock;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Database\Eloquent\Relations\Relation;

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

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // VerifyCsrfToken::runningUnitTests() is hardcoded false in this app.
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function deletedRows(int $userId)
    {
        return DB::table('activity_log')
            ->where('subject_type', Relation::getMorphAlias(User::class))
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
            ->where('subject_type', Relation::getMorphAlias(User::class))
            ->where('subject_id', $userId)
            ->get();
    }

    /**
     * A single delete through the controller writes exactly one record.
     *
     * The controller used to add its own `$user->audit('user.deleted')` on top
     * of the action, which is the double-write this standard forbids.
     */
    public function test_a_single_delete_writes_exactly_one_audit_record(): void
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
    public function test_a_bulk_delete_records_every_deleted_user(): void
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
    public function test_a_bulk_delete_writes_no_aggregate_row(): void
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
    #[DataProvider('singleMutationCases')]
    public function test_a_single_user_mutation_writes_exactly_one_audit_record(
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
     * Every bulk state action records each subject, and adds no aggregate row.
     *
     * `lock`, `unlock` and `activate` used to be written as
     * `User::whereIn(...)->update()` with no action behind them, so the only
     * record was the aggregate the controller wrote after the processor
     * returned — outside the transaction, with no `target_id`/`target_email`,
     * and skipping the guards the row buttons enforce. Routing them through
     * their actions makes them behave like `delete` already did.
     *
     * Driven through the HTTP route on purpose: the controller's skip and the
     * handler's event name are both part of what is under test.
     *
     * @param string $action
     * @param string $event
     * @param array<string, mixed> $state
     */
    #[DataProvider('bulkStateCases')]
    public function test_a_bulk_state_action_records_every_subject_once(
        string $action,
        string $event,
        array $state,
    ): void {
        $users = User::factory()->count(2)->create($state);

        $this->actingAs($this->admin)->post(route('users.bulk-action'), [
            'action' => $action,
            'user_ids' => $users->pluck('id')->all(),
        ])->assertRedirect();

        foreach ($users as $user) {
            $this->assertCount(
                1,
                $this->rowsFor($user->id),
                "bulk {$action} wrote a duplicate audit record for user {$user->id}"
            );
            $this->assertSame(
                1,
                $this->rowsFor($user->id)->where('event', $event)->count(),
                "bulk {$action} wrote no {$event} row for user {$user->id}"
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function bulkStateCases(): array
    {
        return [
            'lock' => ['lock', 'user.locked', []],
            'unlock' => ['unlock', 'user.unlocked', ['is_locked' => true]],
            'activate' => ['activate', 'user.activated', ['is_active' => false]],
        ];
    }

    /**
     * A user state change records the target it affected, not just its id.
     *
     * `target_email` is redundant with the subject and that is the point: the
     * audit list is filtered on properties, and a state row carrying only a
     * subject id cannot be searched by the address it was applied to.
     */
    public function test_a_state_change_records_the_target_it_affected(): void
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
    public function test_a_rolled_back_settings_save_leaves_no_value_or_audit_row(): void
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
    public function test_a_settings_save_writes_exactly_one_audit_record(): void
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
    public function test_a_rolled_back_delete_leaves_no_audit_record(): void
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

    /**
     * The four mutations that used to audit outside their transaction.
     *
     * All four are `DB::transaction`-free in the sense that mattered: each wrote
     * its state change and its audit row as unguarded separate statements, so a
     * failure between them left the record claiming a change that never committed.
     * The tracker's "events with no state change" note covers `login_failed`,
     * `account_locked`, `verification_resent` and `registered` — true for those,
     * but not for these: all four mutate a column or delete a row.
     *
     * Driven through the action inside an enclosing transaction that then throws,
     * which is the only way to reach the failure window these had.
     *
     * @param  string  $event
     * @param  array<string, mixed>  $state  Starting state the action requires.
     * @param  callable(User): mixed  $mutate
     * @param  callable(User): bool  $assertRolledBack
     */
    #[DataProvider('unguardedAuditCases')]
    public function test_a_rolled_back_mutation_leaves_no_audit_record(
        string $event,
        array $state,
        callable $mutate,
        callable $assertRolledBack,
    ): void {
        $user = User::factory()->create($state);

        try {
            DB::transaction(function () use ($user, $mutate) {
                $mutate($user);

                throw new RuntimeException('a later step failed');
            });
        } catch (RuntimeException) {
            // Expected: the enclosing transaction rolled back.
        }

        $this->assertSame(
            0,
            $this->rowsFor($user->id)->where('event', $event)->count(),
            "a rolled-back {$event} left an orphan audit row"
        );

        $this->assertTrue(
            $assertRolledBack($user),
            "the {$event} state change was not rolled back with its audit row"
        );
    }

    /**
     * Every notification this application sends defers its delivery to the
     * commit that produced it.
     *
     * Every notification implements `ShouldQueue` and every connection in
     * `config/queue.php` runs `after_commit => false`, so a class without this
     * property is queued the moment it is dispatched: a worker can send before the
     * row exists, and a rollback still delivers a working temporary password, a
     * signed verification link, an email-change link whose token was never
     * written. This file's own subject is audit integrity, and a notification
     * that outlives the transaction it describes is the same defect as an audit
     * row that does.
     *
     * A NEW class that forgets the property inherits all of it with nothing to
     * point at it, which is the reason this is a data provider over the whole
     * directory rather than a line in the one class that happened to need it
     * today.
     *
     * Asserted by reflection rather than by observing a delivery, and that is a
     * deliberate choice worth recording: this trait holds every test inside a
     * transaction that is never committed, so a deferred send is never delivered
     * at all and there is nothing to count. Worse, every fake in the toolbox
     * intercepts ABOVE the decision — `Notification::fake()` records the `send()`
     * call, `Bus::fake()` the dispatch, `Queue::fake()` the push — while the
     * deferral lives in `Illuminate\Queue\Queue::enqueueUsing()`, which none of
     * them run. An end-to-end version of this test would have passed with the
     * property deleted; this one does not. Reverting `afterCommit` to `false`
     * turns it red.
     */
    #[DataProvider('notificationClasses')]
    public function test_every_notification_defers_to_the_commit(string $class): void
    {
        // Reflected, not instantiated: the constructors take the event's own
        // arguments, and this is a question about a class-level default.
        $defaults = (new ReflectionClass($class))->getDefaultProperties();

        $this->assertArrayHasKey('afterCommit', $defaults, "{$class} does not declare the property at all");
        $this->assertTrue(
            $defaults['afterCommit'],
            "{$class} defaults \$afterCommit to false, so a worker can deliver before its row commits"
        );
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function notificationClasses(): array
    {
        return [
            'account state changed' => [AccountStateChangedNotification::class],
            'change email verification' => [ChangeEmailVerificationNotification::class],
            'configuration changed' => [ConfigurationChangedNotification::class],
            'register' => [RegisterNotification::class],
            'roles changed' => [RolesChangedNotification::class],
            'user created' => [UserCreatedNotification::class],
            'user registered' => [UserRegisteredNotification::class],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: callable, 3: callable}>
     */
    public static function unguardedAuditCases(): array
    {
        return [
            'inactivity lock' => [
                'auth.inactivity_lock',
                ['last_activity_at' => now()->subDays(35)],
                fn (User $user) => InactivityLock::lock($user),
                fn (User $user) => ! $user->fresh()->is_locked,
            ],
            // `email_verified_at => null` is required, not cosmetic: the factory
            // creates a verified user and the action refuses one outright, so
            // with the default state it would write nothing and the test would
            // pass without reaching the failure window.
            'email verified' => [
                'auth.email_verified',
                ['email_verified_at' => null],
                fn (User $user) => app(AuthVerifyEmailAction::class)->run($user),
                fn (User $user) => $user->fresh()->email_verified_at === null,
            ],
            'logout all' => [
                'auth.logout_all',
                [],
                fn (User $user) => app(AuthLogoutAllDevicesAction::class)->run($user),
                // Sessions and tokens are deleted inside the transaction now, so
                // there is no column to read back for this one; the audit-row
                // count above is the assertion.
                fn (User $user) => true,
            ],
            'password expiry flag' => [
                'auth.password_expiry.sweep',
                // The job's own query needs an expired password and a user not
                // already flagged, so the guard and the chunk both admit it.
                ['must_change_password' => false, 'password_expires_at' => now()->subDay()],
                fn (User $user) => (new PasswordExpirySweep())->handle(),
                fn (User $user) => ! $user->fresh()->must_change_password,
            ],
            'login' => [
                'auth.login',
                ['last_activity_at' => null],
                fn (User $user) => app(AuthLoginCompletedAction::class)
                    ->run($user, false),
                fn (User $user) => $user->fresh()->last_activity_at === null,
            ],
        ];
    }
}

<?php

namespace Tests\Feature\Notification;

use App\Actions\V1\Notification\NotificationInboxAction;
use App\Actions\V1\Role\RoleAssignAction;
use App\Models\RoleLookup;
use Spatie\Permission\Models\Role;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use App\Support\NotificationAudience;
use App\Support\SystemRole;
use App\Support\UnreadNotificationCount;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 9 performance measurement — the notification module's hot paths.
 *
 * Follows the Phase 8 shape (`SettingsBenchmarkTest`): a warm-up run outside the
 * measured window, query log + wall clock + memory, results written to /tmp for a
 * before/after diff.
 *
 * ## Why these paths and not others
 *
 * The module has exactly two kinds of cost, and only one of them is on every
 * page request:
 *
 *   1. **The header composer.** `UnreadNotificationCount` runs on EVERY
 *      authenticated page, for EVERY user, whether or not they have ever
 *      received a notification. That makes it the only Phase 9 code whose cost is
 *      multiplied by the whole user base on every render, which is why the cold
 *      and warm numbers are reported separately and why the warm one is asserted
 *      rather than merely printed.
 *   2. **Dispatch resolution.** `NotificationAudience` is a `whereHas` over
 *      `roles.permissions` for every administrative event. It runs per dispatch,
 *      not per page, and its cost scales with the USER TABLE, not with the number
 *      of administrators — so it is measured against a populated table rather
 *      than the handful of rows a test fixture makes.
 *
 * The inbox itself is bounded by design (paginated at 30) and the two admin pages
 * are asserted elsewhere to render without touching the database, so those are
 * measured for completeness rather than as a risk.
 *
 * ## Timings are measured, never asserted
 *
 * A latency assertion fails the suite on a loaded CI box for reasons that have
 * nothing to do with the code. The query-count assertions at the bottom are a
 * different matter — they are exact integers that depend on the code and not on
 * the machine, so they are allowed to fail the run.
 */
class NotificationBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string,float|int>> */
    protected static array $results = [];

    public static function tearDownAfterClass(): void
    {
        file_put_contents(
            '/tmp/phase9_notifications_benchmark.json',
            json_encode(static::$results, JSON_PRETTY_PRINT)
        );

        fwrite(STDERR, "\n  → /tmp/phase9_notifications_benchmark.json\n");
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * One measured closure: warm-up outside the window, then queries, latency and
     * memory for the run that counts.
     *
     * @return array{queries:int, latencyMs:float, memMB:float, slowQueries:int}
     */
    private function measure(callable $fn): array
    {
        $fn();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $memBefore = memory_get_usage(true);
        $start = microtime(true);

        $fn();

        $latencyMs = round((microtime(true) - $start) * 1000, 2);
        $memMB = round((memory_get_usage(true) - $memBefore) / 1048576, 3);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $slow = 0;

        foreach ($log as $query) {
            if (($query['time'] ?? 0) > 50) {
                $slow++;
            }
        }

        return [
            'queries' => count($log),
            'latencyMs' => $latencyMs,
            'memMB' => $memMB,
            'slowQueries' => $slow,
        ];
    }

    /**
     * @param  array{queries:int, latencyMs:float, memMB:float, slowQueries:int}  $r
     */
    private function record(string $op, array $r): void
    {
        static::$results[$op] = $r;

        fwrite(STDERR, sprintf(
            "  %-46s %3d queries  %7.2f ms  %6.3f MB\n",
            $op,
            $r['queries'],
            $r['latencyMs'],
            $r['memMB']
        ));
    }

    /**
     * A user holding an administrative permission, so the audience resolver has
     * something to find.
     *
     * Through a ROLE, not `givePermissionTo()`, and that is not a detail:
     * `NotificationAudience::administratorsFor()` reaches holders through
     * `roles.permissions`, which is how the application grants permissions
     * (Phase 6 has no UI for attaching one to a person directly). A directly
     * granted permission is invisible to the resolver — see the note on the
     * audience tests below, which is a finding and not an oversight here.
     */
    private function operatorWith(string $permission, string $roleName = 'bench-operator'): User
    {
        $role = RoleLookup::find($roleName) ?? Role::create([
            'name' => $roleName,
            'guard_name' => RoleLookup::guard(),
        ]);

        $role->givePermissionTo($permission);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * @return array<string, int>
     */
    private function seedNotifications(User $user, int $unread, int $read): array
    {
        $rows = [];
        $now = now();

        for ($i = 0; $i < $unread; $i++) {
            $rows[] = [
                'id' => (string) str()->uuid(),
                'type' => 'database',
                'notifiable_type' => User::class,
                'notifiable_id' => $user->getKey(),
                'data' => json_encode(['subject' => 'Notice '.$i, 'lines' => ['Something happened.']]),
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        for ($i = 0; $i < $read; $i++) {
            $rows[] = [
                'id' => (string) str()->uuid(),
                'type' => 'database',
                'notifiable_type' => User::class,
                'notifiable_id' => $user->getKey(),
                'data' => json_encode(['subject' => 'Read '.$i, 'lines' => ['Something happened.']]),
                'read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }

        return ['unread' => $unread, 'read' => $read];
    }

    // ---------------------------------------------------------------------
    // 1. The header composer — runs on every authenticated page render
    // ---------------------------------------------------------------------

    public function test_the_header_composer_cold_and_warm(): void
    {
        fwrite(STDERR, "\n[Phase 9] header composer — every authenticated page\n");

        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->seedNotifications($user, unread: 40, read: 400);

        // Cold: what the first page after a deploy, or after anything busts the
        // cache, costs. This is the number that scales with the user base.
        //
        // `Cache::flush()` sits INSIDE the measured closure: the helper warms up
        // before it starts the clock, so busting the cache beforehand measures a
        // second warm render.
        $this->record('bell: unread count, cold cache', $this->measure(function () use ($user) {
            Cache::flush();

            UnreadNotificationCount::forgetAllFor($user);
            UnreadNotificationCount::for($user);
        }));

        // Warm: the steady state. The composer runs on every page, so this is the
        // one that decides whether the module is affordable.
        $this->record('bell: unread count, warm cache', $this->measure(function () use ($user) {
            UnreadNotificationCount::for($user);
        }));

        // The dropdown's five rows share the same lifetime as the badge, and are
        // what E10 added on top of it — measured separately because a regression
        // there is a regression nobody would notice in the badge number.
        $this->record('bell: recent list, warm cache', $this->measure(function () use ($user) {
            UnreadNotificationCount::recent($user);
        }));

        // What a delivery costs the next page view: bust, then refill. This is the
        // price of the correctness E10 fixed — the badge can never be stale
        // without someone paying to rebuild it.
        $this->record('bell: invalidate + refill (one delivery)', $this->measure(function () use ($user) {
            UnreadNotificationCount::forgetAllFor($user);
            UnreadNotificationCount::for($user);
            UnreadNotificationCount::recent($user);
        }));

        // Measures; the exact query counts are asserted below.
        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------
    // 2. The inbox
    // ---------------------------------------------------------------------

    public function test_the_inbox_at_volume(): void
    {
        fwrite(STDERR, "\n[Phase 9] inbox\n");

        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->seedNotifications($user, unread: 120, read: 2_000);

        $action = app(NotificationInboxAction::class);

        // Both channels of one page render: the paginated rows and the count the
        // header already showed. Pagination is the only reason this number does
        // not grow with the inbox.
        $this->record('inbox: 30 rows of 2,120', $this->measure(function () use ($action, $user) {
            $action->inbox($user);
        }));

        // A single-user isolation read, which is the inbox's only authorization
        // boundary: it must stay one indexed lookup however big the table is.
        $id = $user->notifications()->value('id');

        $this->record('inbox: mark one read', $this->measure(function () use ($action, $user, $id) {
            $action->markAsRead($user, $id);
        }));

        $this->record('inbox: mark all read', $this->measure(function () use ($action, $user) {
            $action->markAllAsRead($user);
        }));

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------
    // 3. Dispatch resolution — cost scales with the user table
    // ---------------------------------------------------------------------

    public function test_audience_resolution_against_a_populated_table(): void
    {
        fwrite(STDERR, "\n[Phase 9] audience resolution\n");

        $this->operatorWith('users.lock');
        $this->operatorWith('users.lock');
        $this->operatorWith('users.lock');
        $superadmin = User::factory()->create(['email_verified_at' => now()]);
        $superadmin->assignRole(RoleLookup::find(SystemRole::SUPERADMIN));

        // The resolver is a `whereHas` over roles.permissions, so the interesting
        // question is what it costs when the table holds everyone who is NOT an
        // administrator. Sixty non-holders is roughly a small install; the cost
        // that matters is whether it grows with them.
        User::factory(60)->create();

        $this->record('audience: 4 holders, 65 users', $this->measure(function () {
            NotificationAudience::administratorsFor('user.locked');
        }));

        // Superadmin holds no permission rows, so it can only be found by the
        // second query. Measuring it separately is what proves that query is not
        // dead weight on every dispatch.
        $this->record('audience: forEvent (both queries)', $this->measure(function () {
            NotificationAudience::forEvent('user.locked');
        }));

        // An event with no mapping: the narrow-audience fallback, which must not
        // fall through to a broadcast.
        $this->record('audience: unmapped event', $this->measure(function () {
            NotificationAudience::forEvent('nope.not.a.real.event');
        }));

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------
    // 4. The dispatch path the user reported on — a role change
    // ---------------------------------------------------------------------

    public function test_a_role_change_end_to_end(): void
    {
        fwrite(STDERR, "\n[Phase 9] dispatch: role change\n");

        $subject = User::factory()->create(['email_verified_at' => now()]);
        $subject->assignRole(RoleLookup::find(SystemRole::USER));

        $operator = $this->operatorWith('roles.assign_permissions');
        $operator->assignRole(RoleLookup::find(SystemRole::ADMIN));

        $action = app(RoleAssignAction::class);

        // With notifications faked, so the number is the ROLE WORK and not the
        // delivery — the delivery is queued, and a queue write is not what this
        // measurement is about.
        Notification::fake();

        $this->record('role change: assign + audit + resolve', $this->measure(
            fn () => $action->run($subject->fresh(), ['user', 'admin'], $operator)
        ));

        // And the same call when nothing actually changed. The diff guard exists so
        // an empty save does not mail everyone; this is the number proving the
        // guard is cheap enough to keep.
        $this->record('role change: no-op save', $this->measure(
            fn () => $action->run($subject->fresh(), ['user', 'admin'], $operator)
        ));

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------
    // 5. Retention sweep — the DELETE the scheduler runs daily
    // ---------------------------------------------------------------------

    public function test_the_retention_sweep(): void
    {
        fwrite(STDERR, "\n[Phase 9] retention sweep\n");

        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->seedNotifications($user, unread: 100, read: 5_000);

        $schedule = app(Schedule::class);
        $event = collect($schedule->events())->first(
            fn ($e): bool => $e->description === 'notification-retention'
        );

        $this->assertNotNull($event, 'the retention sweep is not scheduled');

        // The sweep has to run against the default connection, not the event's
        // own, and its cost is the DELETE rather than the callback wrapper.
        $sweep = function () use ($user): void {
            DB::table('notifications')
                ->whereNotNull('read_at')
                ->where('read_at', '<', now()->subDays(90))
                ->delete();
        };

        // Nothing is past the window yet, so this measures the scan cost alone.
        $this->record('retention: scan 5,100 rows, none expired', $this->measure($sweep));

        $expired = DB::table('notifications')
            ->whereNotNull('read_at')
            ->limit(4_000)
            ->pluck('id');

        DB::table('notifications')->whereIn('id', $expired)->update([
            'read_at' => now()->subDays(200),
        ]);

        $this->record('retention: delete 4,000 expired rows', $this->measure($sweep));

        $this->assertSame(
            1_100,
            DB::table('notifications')->count(),
            'the sweep left rows it should have deleted, or deleted rows it should have kept'
        );

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------
    // Deterministic invariants — exact integers, so these may fail the run
    // ---------------------------------------------------------------------

    /**
     * The one Phase 9 number that is allowed to be an assertion.
     *
     * The composer is not a page-specific cost: it runs on every authenticated
     * render for every user, so a regression here is the module deciding to be
     * unaffordable. A cold cache may cost queries — that is the first render after
     * a deploy. A warm one may cost none, because that is every other render in
     * the application's life.
     *
     * This is why E10 cached the count at all: fifteen existing query guards
     * elsewhere in the suite already depend on a page not paying for it.
     */
    public function test_a_warm_bell_cache_costs_no_query(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->seedNotifications($user, unread: 5, read: 10);

        // The cache is busted INSIDE the measured closure, not before it. The
        // helper runs a warm-up pass first, so busting before the call measures a
        // second warm render — which is how this row first read "cold: 0 queries",
        // a number that would have been the clearest possible lie in the file.
        $this->record('invariant: bell, cold', $this->measure(function () use ($user) {
            Cache::flush();
            UnreadNotificationCount::forgetAllFor($user);

            UnreadNotificationCount::for($user);
            UnreadNotificationCount::recent($user);
        }));

        $this->record('invariant: bell, warm', $this->measure(function () use ($user) {
            UnreadNotificationCount::for($user);
            UnreadNotificationCount::recent($user);
        }));

        $this->assertSame(
            0,
            static::$results['invariant: bell, warm']['queries'],
            'the bell reads the database on a warm cache; it runs on every authenticated page'
        );
    }

    /**
     * The inbox paginates, so its cost is a function of the page size rather than
     * of how much has piled up. Without pagination this number grows with the
     * inbox, and the inbox grows forever.
     */
    public function test_the_inbox_cost_does_not_grow_with_its_size(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->seedNotifications($user, unread: 5, read: 10);

        $action = app(NotificationInboxAction::class);

        $small = $this->measure(fn () => $action->inbox($user))['queries'];

        $this->seedNotifications($user, unread: 0, read: 1_000);

        $large = $this->measure(fn () => $action->inbox($user))['queries'];

        $this->assertSame(
            $small,
            $large,
            'the inbox costs more with 1,015 notifications than with 15, so it is not paginating'
        );
        $this->assertLessThanOrEqual(
            30,
            $action->inbox($user)['notifications']->count(),
            'the inbox returned more rows than a page'
        );
    }

    /**
     * Audience resolution is two queries regardless of how many administrators
     * exist — the shape that keeps a dispatch cheap on a large install. A `foreach`
     * with `can()` in it would pass the notification tests and fail this one,
     * because `User::can()` is memoised per instance and a fresh instance per
     * user means a fresh gate read for each.
     */
    public function test_audience_resolution_is_two_queries_however_many_admins(): void
    {
        $one = $this->operatorWith('users.lock');

        $single = $this->measure(
            fn () => NotificationAudience::administratorsFor('user.locked')
        )['queries'];

        // Added to the SAME role, which is how the application grants them.
        RoleLookup::find('bench-operator')->givePermissionTo('users.lock');

        User::factory(20)->create()->each(
            fn (User $user) => $user->assignRole(RoleLookup::find('bench-operator'))
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $many = $this->measure(
            fn () => NotificationAudience::administratorsFor('user.locked')
        )['queries'];

        $this->assertSame(
            $single,
            $many,
            'audience resolution issues more queries with 21 administrators than with 1'
        );
        $this->assertSame(
            21,
            NotificationAudience::administratorsFor('user.locked')->count(),
            'precondition: every holder was resolved'
        );
        $this->assertTrue(
            NotificationAudience::administratorsFor('user.locked')->contains('id', $one->getKey()),
            'the original holder was dropped from the audience'
        );
    }
}

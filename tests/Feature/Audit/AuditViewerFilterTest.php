<?php

namespace Tests\Feature\Audit;

use App\Models\Activity;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The audit viewer's filters actually filter (Phase 10, P10-C4/C3).
 *
 * Over HTTP rather than against the action directly, so the whole chain is
 * exercised: `ActivityQueryRequest` validates and normalises, the action builds
 * the query, the controller paginates. A test that calls `AuditIndexAction::run()`
 * with a hand-built array skips the validation, and `sort` is a security control
 * — the one thing here that must never reach `orderBy()` unvalidated.
 *
 * Rows are written through `Auditable::audit()` rather than `Activity::create()`,
 * because that is what production does and because it is the only way a row gets
 * the `source` / `ip` / `user_agent` properties the source filter reads.
 */
class AuditViewerFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->viewer = User::factory()->create(['email_verified_at' => now()]);
        $this->viewer->assignRole(RoleLookup::find('admin'));
        $this->actingAs($this->viewer, 'web');
    }

    private User $viewer;

    private User $otherUser;

    /**
     * Write a row the way the application does, and hand back the written row.
     *
     * Goes through Spatie's builder rather than `Auditable::audit()` because that
     * method returns void — the fixture needs the row it just created so it can
     * back-date it. The CHAIN is the same one `Auditable::audit()` builds
     * (`performedOn` + `event` + `causedBy` + `withProperties` + `log`), so a row
     * written here is shaped like a production row.
     *
     * @param  array<string, mixed>  $properties
     */
    private function record(
        string $event,
        ?User $subject = null,
        ?User $causer = null,
        array $properties = [],
        ?string $createdAt = null,
    ): Activity {
        $subject ??= $this->otherUser ??= User::factory()->create();

        // `Auditable::auditContext()` merged in, exactly as the real writer does
        // it. Without it the fixture rows carry no `source`, and every row renders
        // as `unknown` — which would make the source filter test pass for the
        // wrong reason, and would not be the shape a production row has.
        //
        // Called on `User` because that is where the trait lives: `Activity` does
        // not carry `Auditable` (it is the read model, not a writer).
        $properties = array_merge(User::auditContext(), $properties);

        $logger = activity()->performedOn($subject)->event($event)->withProperties($properties);

        // A null causer is `causedByAnonymous()`, exactly as Auditable does it —
        // a login failure has nobody to attribute the row to.
        $row = $causer === null
            ? $logger->causedByAnonymous()->log($event)
            : $logger->causedBy($causer)->log($event);

        if ($createdAt !== null) {
            $row->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }

        // Re-read through the application's own model rather than returning the
        // package's instance, so every fixture hands back a real `App\Models\Activity`
        // and a test that touches the read model exercises the real read model.
        return Activity::query()->findOrFail($row->getKey());
    }

    /**
     * The event names the rendered index shows, in order.
     *
     * Read from the TABLE rather than from `audit.*` matchers: `assertSee` on the
     * whole page passes when the string appears anywhere, including in the filter
     * dropdown, which lists every event in the table whether or not the filter
     * matched. That is the difference between a filter test and a test that only
     * proves the dropdown works.
     *
     * @return array<int, string>
     */
    private function renderedEvents(): array
    {
        return $this->renderedEventsWith([]);
    }

    /**
     * Event names from the rendered TABLE BODY, one per row, in render order.
     *
     * Scoped to `<tbody>` because the filter dropdown above the table lists every
     * event that exists — matching the whole page would read a filtered list as
     * "unfiltered", since the dropdown always shows all of them.
     *
     * Per-row, and the FIRST badge in each row: the event badge is the link, and
     * the source badge that follows it would otherwise be collected too.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    private function renderedEventsWith(array $filters): array
    {
        $html = $this->get(route('activity-logs.index', $filters))->assertOk()->getContent();

        preg_match('#<tbody>(.*?)</tbody>#s', $html, $body);
        preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $body[1] ?? '', $rows);

        $events = [];

        foreach ($rows[1] as $rowHtml) {
            // The empty-state row carries no detail link, so it is skipped rather
            // than contributing an empty event name.
            if (preg_match('#activity-logs/\d+#', $rowHtml) !== 1) {
                continue;
            }

            preg_match('/class="badge[^"]*"[^>]*>\s*([a-z_.]+)/', $rowHtml, $event);
            $events[] = $event[1] ?? '';
        }

        return $events;
    }

    #[Test]
    public function test_the_unfiltered_list_shows_every_row_newest_first(): void
    {
        $this->record('user.locked', createdAt: '2026-10-01 10:00:00');
        $this->record('user.unlocked', createdAt: '2026-10-02 10:00:00');
        $this->record('auth.login', createdAt: '2026-10-03 10:00:00');

        $this->assertSame(['auth.login', 'user.unlocked', 'user.locked'], $this->renderedEvents());
    }

    #[Test]
    public function test_the_event_filter_narrows_to_one_event(): void
    {
        $this->record('user.locked');
        $this->record('user.unlocked');
        $this->record('user.locked');

        $html = $this->get(route('activity-logs.index', ['event' => 'user.locked']))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, substr_count($html, 'user.locked</span>'));
        $this->assertStringNotContainsString('user.unlocked</span>', $html);
    }

    #[Test]
    public function test_the_search_matches_event_and_description_but_not_properties(): void
    {
        $this->record('user.locked', properties: ['reason' => 'needle-in-properties']);
        $this->record('auth.login');

        // Searching `needle` must NOT find the row whose properties contain it:
        // `properties` is JSON, unindexed, and a LIKE across it is a table scan
        // over the largest table in the system. The test pins the LIMITATION,
        // not just the happy path.
        $this->assertSame(
            [],
            $this->renderedEventsWith(['search' => 'needle-in-properties'])
        );

        // A search on the event name does find it.
        $this->assertSame(['user.locked'], $this->renderedEventsWith(['search' => 'locked']));
    }

    #[Test]
    public function test_the_actor_filter_narrows_to_one_causer(): void
    {
        $second = User::factory()->create();

        $this->record('auth.login', causer: $this->viewer);
        $this->record('auth.logout', causer: $second);

        $this->assertSame(
            ['auth.login'],
            $this->renderedEventsWith(['causer_id' => $this->viewer->id])
        );
    }

    #[Test]
    public function test_the_date_range_includes_the_whole_end_day(): void
    {
        $this->record('user.locked', createdAt: '2026-10-09 00:00:01');
        $this->record('user.unlocked', createdAt: '2026-10-09 23:59:59');
        $this->record('auth.login', createdAt: '2026-10-10 12:00:00');

        // The end-of-day bug this pins: `whereDate(created_at, '<=', $dateTo)`
        // truncates to midnight and silently drops the 23:59:59 row, so the
        // range reads correctly and is short by most of a working day.
        $this->assertSame(
            ['user.unlocked', 'user.locked'],
            $this->renderedEventsWith(['date_from' => '2026-10-09', 'date_to' => '2026-10-09'])
        );
    }

    /**
     * An inverted date range is rejected rather than silently returning nothing.
     */
    #[Test]
    public function test_a_date_range_that_ends_before_it_starts_is_rejected(): void
    {
        $this->record('auth.login');

        $this->get(route('activity-logs.index', ['date_from' => '2026-10-09', 'date_to' => '2026-10-01']))
            ->assertSessionHasErrors('date_to');
    }

    /**
     * `sort` reaches `orderBy()`, which does not bind its second argument — it
     * interpolates it as an identifier. A value outside the allowlist must be
     * refused at validation rather than reaching the builder.
     */
    #[Test]
    public function test_an_unsortable_column_is_rejected(): void
    {
        $this->record('auth.login');

        $this->get(route('activity-logs.index', ['sort' => 'properties']))
            ->assertSessionHasErrors('sort');

        $this->get(route('activity-logs.index', ['sort' => 'created_at); drop table users; --']))
            ->assertSessionHasErrors('sort');
    }

    #[Test]
    public function test_the_sort_direction_flips_the_order(): void
    {
        $this->record('user.locked', createdAt: '2026-10-01 10:00:00');
        $this->record('auth.login', createdAt: '2026-10-03 10:00:00');

        $this->assertSame(
            ['user.locked', 'auth.login'],
            $this->renderedEventsWith(['direction' => 'asc'])
        );
    }

    /**
     * The source filter reads the JSON `properties` column, which is a different
     * query shape from the string columns — the reason it has its own code path.
     */
    #[Test]
    public function test_the_source_filter_reads_the_json_properties_column(): void
    {
        // `system` is what AuditsSystemActivity writes for a job row: no causer,
        // `source => system`. It is written through the job concern on purpose.
        $subject = User::factory()->create();
        $subject->audit('system_setting.updated', null, ['source' => 'system']);
        $subject->audit('auth.login', $this->viewer, ['source' => 'web']);

        $this->assertSame(
            ['system_setting.updated'],
            $this->renderedEventsWith(['source' => 'system'])
        );
    }

    /**
     * A row whose `source` property is missing must not break the filter or the
     * dropdown — a hand-edited row, or one written before `auditContext()`
     * existed.
     *
     * Inserted with a raw `DB::table()` insert rather than through the model:
     * `App\Models\Activity` is `$guarded = ['*']`, so `Activity::create()` here
     * would throw — the read-only guarantee working, not a fixture problem.
     * Going below the model is also the honest way to simulate a legacy row,
     * because that is exactly how such a row would have been written.
     */
    #[Test]
    public function test_rows_without_a_source_still_render(): void
    {
        $subject = User::factory()->create();

        DB::table(config('activitylog.table_name', 'activity_log'))->insert([
            'log_name' => 'default',
            'description' => 'legacy.row',
            'event' => 'legacy.row',
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->id,
            'properties' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('activity-logs.index'))->assertOk()->assertSee('legacy.row');
    }

    /**
     * The read model refuses mass assignment.
     *
     * The defence behind "audit records are append-only". It is a property
     * assertion rather than a route assertion because the 405 assertion cannot
     * see a model that would happily accept the payload — a future write route
     * calling `Activity::create($request->validated())` fails here first.
     */
    #[Test]
    public function test_the_activity_model_refuses_mass_assignment(): void
    {
        $row = new Activity();

        $this->assertSame(['*'], $row->getGuarded());

        $this->expectException(MassAssignmentException::class);
        $row->fill(['event' => 'tampered', 'description' => 'tampered']);
    }

    /**
     * Rows sharing a created_at must not shuffle between pages.
     *
     * A bulk write produces many rows in one instant, so without the `id`
     * tiebreaker a row the operator already read can be missing from page 2.
     */
    #[Test]
    public function test_pagination_is_stable_when_rows_share_a_timestamp(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->record('user.locked', createdAt: '2026-10-05 12:00:00');
        }

        $firstPage = $this->get(route('activity-logs.index', ['per_page' => 10]))->assertOk()->getContent();
        $secondPage = $this->get(route('activity-logs.index', ['per_page' => 10, 'page' => 2]))->assertOk()->getContent();

        preg_match_all('#activity-logs/(\d+)"#', $firstPage, $first);
        preg_match_all('#activity-logs/(\d+)"#', $secondPage, $second);

        $ids = array_map('intval', $first[1]);
        $overlap = array_intersect($ids, array_map('intval', $second[1]));

        $this->assertCount(10, $ids, 'precondition: page one rendered ten rows');
        $this->assertSame([], array_values($overlap), 'a row appeared on two pages');
    }

    /**
     * The viewer is read-only: there is no write route to reach, at either verb.
     *
     * Asserted structurally rather than by inspecting the controller, because the
     * requirement is that no PATH exists — a method added to the controller
     * without a route would still be unreachable, and a route added without a
     * method would be a 405 rather than a 403. 405 is the answer here: the verb
     * is not registered at all.
     */
    #[Test]
    public function test_there_is_no_write_route_for_audit_records(): void
    {
        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            $this->{$verb}(route('activity-logs.index'))
                ->assertStatus(405);
        }
    }
}

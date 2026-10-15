<?php

namespace Tests\Feature\Audit;

use App\Models\Activity;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The viewer's query count does not grow with the table (Phase 10, P10-C8).
 *
 * ## What this proves that the render gate does not
 *
 * `AuditLogUiRenderTest` already asserts each view issues ZERO queries of its
 * own. That is the right guard for the template and it cannot see the failure
 * this one exists for: `causer` and `subject` are two `morphTo` relations, and a
 * template that reads `$row->causerLabel()` happily does so with ZERO queries —
 * because the lazy load happens on the model, not in Blade. Ten rows cost
 * twenty extra queries and the render gate stays green; the page renders
 * perfectly and nobody measures it.
 *
 * So this measures the whole HTTP render and asserts the count is the same at
 * one row and at five hundred. That is the assertion the eager load in
 * `AuditIndexAction::run()` exists to satisfy, and it is exact — an integer
 * that depends on the code and not on the machine.
 *
 * ## Query counts only, no timings
 *
 * The suite runs on SQLite `:memory:`, where a latency number says nothing
 * about production. `NotificationBenchmarkTest` measures wall clock because it
 * is profiling a real hot path; here the only thing worth pinning is the shape
 * of the query log.
 *
 * ## Why 500 rows and not 50
 *
 * At 50 rows with `per_page = 10` a missing eager load costs 20 queries, which
 * is noisy enough to notice by eye but small enough to look like framework
 * overhead in a diff. 500 makes the shape unmistakable and costs one `insert`.
 */
class AuditBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

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

    /**
     * Query count for one call, after a warm-up run outside the measured window.
     *
     * The warm-up matters because the FIRST render in a process pays one-off
     * costs (relation metadata, the permission cache, the layout's own
     * composers). Measuring that as the baseline would make the second
     * measurement look like a regression rather than the steady state.
     */
    private function queries(callable $fn): int
    {
        $fn();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $fn();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * Write $count rows spread across two events and two actors.
     *
     * Two of each so the filter dropdowns have real `distinct` work to do and
     * are not accidentally cheap because the fixture is uniform.
     *
     * @param  array<int, string>  $events
     * @param  array<int, User>  $actors
     */
    private function seedRows(int $count, array $events, array $actors): void
    {
        $subject = User::factory()->create();
        $context = User::auditContext();

        for ($i = 0; $i < $count; $i++) {
            activity()
                ->performedOn($subject)
                ->causedBy($actors[$i % count($actors)])
                ->withProperties($context)
                ->log($events[$i % count($events)]);
        }
    }

    /**
     * The index page: paginated, so its cost is a function of the page size and
     * not of how much has piled up.
     *
     * The audit table grows forever and nothing prunes it, so an index whose
     * query count scaled with its contents would be a page that got slower every
     * week and nobody could say when it started.
     */
    public function test_the_index_costs_the_same_at_one_row_and_at_five_hundred(): void
    {
        $actors = [$this->viewer, User::factory()->create()];

        $this->seedRows(1, ['user.updated'], $actors);
        $one = $this->queries(fn () => $this->get(route('activity-logs.index'))->assertOk());

        $this->seedRows(499, ['user.updated', 'user.locked'], $actors);
        $many = $this->queries(fn () => $this->get(route('activity-logs.index'))->assertOk());

        $this->assertSame(
            $one,
            $many,
            'the audit index costs more queries with 500 rows than with 1, so the page is not eager-loading its morph relations'
        );
    }

    /**
     * The detail page: one row, whatever the table holds.
     *
     * Weaker by construction than the index case — a single row has nothing to
     * scale — but it is the assertion that catches a future change which reaches
     * for the table: a "recent activity" strip on the detail page, or a filter
     * dropdown someone copies onto it. Both render perfectly and both would
     * otherwise arrive unmeasured.
     */
    public function test_the_detail_page_costs_the_same_at_one_row_and_at_five_hundred(): void
    {
        $actors = [$this->viewer, User::factory()->create()];

        $this->seedRows(1, ['user.updated'], $actors);
        $target = Activity::query()->firstOrFail();

        $one = $this->queries(fn () => $this->get(route('activity-logs.show', $target))->assertOk());

        $this->seedRows(499, ['user.updated', 'user.locked'], $actors);
        $many = $this->queries(fn () => $this->get(route('activity-logs.show', $target))->assertOk());

        $this->assertSame(
            $one,
            $many,
            'the audit detail page costs more queries with 500 rows in the table than with 1'
        );
    }
}

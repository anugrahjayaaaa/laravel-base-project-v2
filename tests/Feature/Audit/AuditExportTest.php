<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditExportStatus;
use App\Jobs\GenerateAuditExport;
use App\Models\Activity;
use App\Models\AuditExport;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\AuditExportReadyNotification;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The audit export, end to end (Phase 10, P10-E8).
 *
 * ## What this file is for
 *
 * The export is the one surface in this phase that puts the whole audit table
 * outside the application, so most of these cases are security cases: who may ask,
 * who may download somebody else's file, what happens when the job dies. The
 * happy path is one case; the rest are the ways this feature leaks.
 *
 * ## The failure case is the one that gets skipped
 *
 * A job that throws with the row stuck at `pending` is a permanent lie in the UI:
 * the spinner never resolves and nothing anywhere says the export failed. So the
 * failure case asserts the FILE as well as the status — a `failed` row next to an
 * orphaned CSV is worse than no row at all.
 *
 * ## Real roles, and the real flag
 *
 * Seeding the catalogue and the Pennant row keeps these from passing for the
 * wrong reason — see `AuditViewerAccessTest`, the same discipline one gate
 * further down.
 */
class AuditExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // A real disk, faked: `exists()`, `download()` and the sweep's `delete()`
        // all answer the way they do in production.
        Storage::fake('local');

        // CSRF is disabled because this project enforces it inside feature tests.
        // Left on, the POST is a 419 and the authorization cases prove nothing.
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function login(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Run the job the way the queue would.
     */
    private function runJob(AuditExport $export): void
    {
        (new GenerateAuditExport(
            $export->getKey(),
            $export->filters,
            'created_at',
            'desc',
        ))->handle();
    }

    /**
     * An export row as the job leaves it, with a file that exists.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function readyExport(User $owner, array $overrides = []): AuditExport
    {
        $export = AuditExport::query()->create(array_merge([
            'user_id' => $owner->getKey(),
            'filters' => [],
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
            'status' => AuditExportStatus::READY,
        ], $overrides));

        // A file only exists once the job has run, so the default row is the one
        // the job leaves behind — complete. `PENDING` is the case worth spelling
        // out, because that is the row with no file.
        if ($export->status === AuditExportStatus::READY) {
            $path = 'audit-exports/'.$owner->getKey().'/'.fake()->uuid().'.csv';

            $export->update([
                'path' => $path,
                'row_count' => 3,
                'completed_at' => now(),
            ]);

            Storage::disk('local')->put($path, "id,event\n1,user.updated\n");
        }

        return $export->fresh();
    }

    /**
     * The happy path: a POST creates one pending row and queues one job.
     */
    public function test_requesting_an_export_creates_a_row_and_queues_the_job(): void
    {
        Notification::fake();
        Queue::fake();

        $admin = $this->login(SystemRole::ADMIN);

        $this->post(route('activity-logs.exports.store'), ['format' => 'csv', 'event' => 'user.updated'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseCount('audit_exports', 1);

        $export = AuditExport::query()->sole();

        $this->assertSame($admin->getKey(), $export->user_id);
        $this->assertSame(AuditExportStatus::PENDING, $export->status);
        $this->assertNull($export->path);
        $this->assertSame('user.updated', $export->filters['event']);

        // The window is set at REQUEST time, not at completion: the download route
        // has to answer "expired?" without knowing when the job finished.
        $this->assertEqualsWithDelta(
            now()->addDays(AuditExport::RETENTION_DAYS)->timestamp,
            $export->expires_at->timestamp,
            5,
        );

        // The flag, not the convention: `Queueable` already declares
        // `$afterCommit`, so it can only be set at the dispatch site — and a
        // worker running this job before the row commits fails it on the row it
        // was created for.
        Queue::assertPushed(
            GenerateAuditExport::class,
            fn (GenerateAuditExport $job): bool => $job->afterCommit === true,
        );
    }

    /**
     * The flash REACHES the operator, which is the half that was broken.
     *
     * `assertSessionHas('status')` alone passed for a long time while the page
     * rendered nothing: the key was `success`, no admin view read it, and the
     * browser reloaded the page in silence. Asserting on the followed redirect's
     * body is the only assertion that can tell the two states apart — the flash
     * in the session and the flash on the screen are two different facts.
     */
    public function test_the_confirmation_is_rendered_on_the_audit_page(): void
    {
        Notification::fake();
        Queue::fake();

        $this->login(SystemRole::ADMIN);

        $response = $this->followingRedirects()
            ->from(route('activity-logs.index'))
            ->post(route('activity-logs.exports.store'), ['format' => 'csv']);

        $response->assertOk()
            ->assertSee('Your export is being prepared', false);
    }

    /**
     * The generated file holds the rows the filter matched, in the documented
     * header, with the same labels the viewer shows.
     */
    public function test_the_job_writes_a_csv_of_the_filtered_rows(): void
    {
        Notification::fake();

        $admin = $this->login(SystemRole::ADMIN);
        $target = User::factory()->create(['name' => 'Budi Santoso']);

        $target->audit('user.updated', $admin, ['changedFields' => ['email' => 'new@example.test']]);
        $target->audit('user.deleted', $admin);

        $export = AuditExport::query()->create([
            'user_id' => $admin->getKey(),
            'filters' => ['event' => 'user.updated'],
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
        ]);

        $this->runJob($export);

        $export->refresh();

        $this->assertSame(AuditExportStatus::READY, $export->status);
        $this->assertSame(1, $export->row_count, 'the filter is not applied — every row is exported');
        $this->assertNotNull($export->path);
        $this->assertNotNull($export->completed_at);

        Storage::disk('local')->assertExists($export->path);

        $rows = $this->csv(Storage::disk('local')->get($export->path));

        $this->assertSame('id', $rows[0][0]);
        $this->assertContains('actor', $rows[0]);
        $this->assertContains(
            'request_id',
            $rows[0],
            'the correlation ID is the column that ties a file back to an error trace'
        );

        // The actor is the admin who asked, and the target is the subject's NAME —
        // the same answer the viewer gives, because it is the same method.
        $this->assertSame($admin->name, $rows[1][4]);
        $this->assertSame('Budi Santoso', $rows[1][6]);
        $this->assertStringContainsString('changedFields', $rows[1][12]);
    }

    /**
     * The job never resolves a relation per row.
     *
     * Asserted on the QUERY COUNT rather than by reading the code: an unfiltered
     * export over a large table is the one query in this module that can take the
     * database down, and it only shows up as a scaling failure if something pins
     * the shape now. Both sizes fit in a single 500-row chunk, so the count is
     * expected to be identical — the margin is there so a chunk-boundary
     * difference is not a false failure.
     */
    public function test_the_export_query_count_does_not_grow_with_the_row_count(): void
    {
        Notification::fake();

        $this->login(SystemRole::ADMIN);

        $small = $this->exportQueryCount(3);
        $large = $this->exportQueryCount(40);

        $this->assertLessThanOrEqual($small + 1, $large, 'query count grew with the row count');
    }

    private function exportQueryCount(int $rows): int
    {
        $actor = User::factory()->create(['email_verified_at' => now()]);

        for ($i = 0; $i < $rows; $i++) {
            $actor->audit('user.updated', $actor);
        }

        $export = AuditExport::query()->create([
            'user_id' => $actor->getKey(),
            'filters' => [],
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->runJob($export);

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    /**
     * One operator cannot download another operator's file.
     *
     * The IDOR this route is built around: both of these users hold
     * `audit.export`. The permission says "may extract", not "may download
     * extract #41", and the id is attacker-supplied.
     */
    public function test_another_operator_cannot_download_the_export(): void
    {
        $owner = $this->login(SystemRole::ADMIN);
        $export = $this->readyExport($owner);

        $this->login(SystemRole::ADMIN);

        $this->get(route('activity-logs.exports.download', $export))->assertForbidden();
    }

    /**
     * The requester can download their own file.
     */
    public function test_the_requester_can_download_their_own_export(): void
    {
        $owner = $this->login(SystemRole::ADMIN);
        $export = $this->readyExport($owner);

        $this->get(route('activity-logs.exports.download', $export))
            ->assertOk()
            ->assertDownload($export->downloadName());
    }

    /**
     * Past the window the file is gone, and 410 is the honest answer.
     *
     * 404 would tell the requester they mistyped an id and send them hunting for a
     * typo in a link that was correct an hour ago.
     */
    public function test_an_expired_export_answers_gone_rather_than_not_found(): void
    {
        $owner = $this->login(SystemRole::ADMIN);
        $export = $this->readyExport($owner, ['expires_at' => now()->subMinute()]);

        $this->assertTrue($export->isExpired());
        $this->assertFalse($export->isDownloadable());

        $this->get(route('activity-logs.exports.download', $export))->assertStatus(410);
    }

    /**
     * An export that never finished has no file and no download link.
     */
    public function test_a_pending_export_cannot_be_downloaded(): void
    {
        $owner = $this->login(SystemRole::ADMIN);
        $export = $this->readyExport($owner, ['status' => AuditExportStatus::PENDING]);

        $this->assertFalse($export->isDownloadable());

        $this->get(route('activity-logs.exports.download', $export))->assertStatus(404);
    }

    /**
     * A row whose file the sweep unlinked answers 404, not 410: the window is
     * still open, so there is nothing expired to report.
     */
    public function test_an_export_whose_file_is_gone_answers_not_found(): void
    {
        $owner = $this->login(SystemRole::ADMIN);
        $export = $this->readyExport($owner);

        Storage::disk('local')->delete($export->path);

        $this->get(route('activity-logs.exports.download', $export))->assertNotFound();
    }

    /**
     * `audit.export` is not `audit.view`.
     *
     * `user` is seeded with an EMPTY permission set by design, so this is a real
     * role holding no audit capability — not a hand-built one. Both routes are
     * checked because they answer different questions: one is "may you ask", the
     * other is "may you take", and a viewer permission must never imply either.
     */
    public function test_a_user_without_the_export_permission_is_refused_both_routes(): void
    {
        $owner = User::factory()->create();
        $export = $this->readyExport($owner);

        $reader = $this->login(SystemRole::USER);

        // Assert the precondition rather than trusting the fixture: a test that
        // passes because the permission was never granted to anyone proves nothing.
        $this->assertFalse($reader->can('audit.export'));
        $this->assertFalse($reader->can('audit.view'));

        $this->post(route('activity-logs.exports.store'), ['format' => 'csv'])->assertForbidden();
        $this->get(route('activity-logs.exports.download', $export))->assertForbidden();
    }

    /**
     * A failed job leaves a state the UI can explain, and no file.
     */
    public function test_a_failed_job_marks_the_row_failed_and_leaves_no_file(): void
    {
        $admin = $this->login(SystemRole::ADMIN);

        $export = AuditExport::query()->create([
            'user_id' => $admin->getKey(),
            'filters' => [],
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
        ]);

        // A file that exists, as if the job wrote it and then died before
        // updating the row — the case where a `failed` row and an orphaned file
        // would both be left behind.
        $orphan = 'audit-exports/'.$admin->getKey().'/orphan.csv';
        $export->update(['path' => $orphan]);
        Storage::disk('local')->put($orphan, 'id,event');

        (new GenerateAuditExport($export->getKey(), [], 'created_at', 'desc'))
            ->failed(new RuntimeException('disk went away'));

        $export->refresh();

        $this->assertSame(AuditExportStatus::FAILED, $export->status);
        $this->assertFalse($export->isDownloadable());
        Storage::disk('local')->assertMissing($orphan);
    }

    /**
     * The job retries, and every attempt leaves its own trace.
     *
     * `tries = 1` made a Redis blip indistinguishable from a real failure and cost
     * the operator a second click. Raising it to 3 fixes that and introduces the
     * opposite problem: `failed()` fires once at the end and `failed_jobs` keeps
     * ONE row per job, so attempts one and two vanish and the surviving stack
     * trace is the one that did NOT explain the first two. These two assertions
     * are the contract that stops that — the declared policy, and the per-attempt
     * log line that outlives the row.
     */
    public function test_a_failed_export_retries_and_logs_every_attempt(): void
    {
        $admin = $this->login(SystemRole::ADMIN);

        $export = AuditExport::query()->create([
            'user_id' => $admin->getKey(),
            'filters' => [],
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
        ]);

        $job = new GenerateAuditExport($export->getKey(), [], 'created_at', 'desc');

        $this->assertSame(3, $job->tries, 'a transient failure must not be permanent');
        $this->assertSame([10, 30], $job->backoff(), 'three tries inside one outage is not three attempts');

        // A row that does not exist: `findOrFail()` throws, which is the cheapest
        // way to make `handle()` fail for real rather than assert on the wrapper.
        $missing = new GenerateAuditExport($export->getKey() + 9999, [], 'created_at', 'desc');

        Log::spy();

        $this->assertThrows(
            fn () => $missing->handle(),
            ModelNotFoundException::class,
        );

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $event, array $context) use ($export): bool {
                return $event === 'audit.export.attempt_failed'
                    && $context['export_id'] === $export->getKey() + 9999
                    && $context['attempt'] === 1
                    && $context['max_tries'] === 3
                    && filled($context['exception']);
            });
    }

    /**
     * The nightly sweep removes the row AND the file, and leaves live rows alone.
     */
    public function test_the_expiry_sweep_deletes_the_row_and_the_file(): void
    {
        $admin = $this->login(SystemRole::ADMIN);

        $expired = $this->readyExport($admin, ['expires_at' => now()->subDay()]);
        $live = $this->readyExport($admin);

        // `routes/console.php` is loaded when a console command boots, so the
        // schedule has to be resolved through one before its events exist.
        $this->artisan('schedule:list');

        $sweep = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'audit-export-retention');

        $this->assertNotNull($sweep, 'the export sweep is not scheduled');

        $sweep->run(app());

        $this->assertDatabaseMissing('audit_exports', ['id' => $expired->id]);
        Storage::disk('local')->assertMissing($expired->path);

        $this->assertDatabaseHas('audit_exports', ['id' => $live->id]);
        Storage::disk('local')->assertExists($live->path);
    }

    /**
     * A row still being generated is not swept out from under its own job.
     */
    public function test_the_sweep_leaves_a_pending_export_alone(): void
    {
        $admin = $this->login(SystemRole::ADMIN);

        $pending = $this->readyExport($admin, [
            'status' => AuditExportStatus::PENDING,
            'expires_at' => now()->subDay(),
        ]);

        // `routes/console.php` is loaded when a console command boots, so the
        // schedule has to be resolved through one before its events exist.
        $this->artisan('schedule:list');

        $sweep = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'audit-export-retention');

        $sweep->run(app());

        $this->assertDatabaseHas('audit_exports', ['id' => $pending->id]);
    }

    /**
     * The file lands on the PRIVATE disk, under the requester's own directory.
     */
    public function test_the_export_is_written_to_the_private_disk(): void
    {
        Notification::fake();

        $admin = $this->login(SystemRole::ADMIN);
        $admin->audit('user.updated', $admin);

        $export = AuditExport::query()->create([
            'user_id' => $admin->getKey(),
            'filters' => [],
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
        ]);

        $this->runJob($export);

        $path = $export->fresh()->path;

        $this->assertStringStartsWith('audit-exports/'.$admin->getKey().'/', $path);
        Storage::disk('local')->assertExists($path);
    }

    /**
     * The rate limit is five an hour.
     *
     * A permission answers who; only a limiter answers how often, and one export
     * streams the whole table. The sixth request is refused AND leaves no row —
     * a refused request that still queued a job is a limiter with a leak in it.
     */
    public function test_the_export_route_is_rate_limited(): void
    {
        Queue::fake();

        $admin = $this->login(SystemRole::ADMIN);

        $this->assertTrue($admin->can('audit.export'));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('activity-logs.exports.store'), ['format' => 'csv'])
                ->assertRedirect();
        }

        // The web channel answers a throttle with a redirect and an error on the
        // field, NOT a 429 — `LoginThrottle::responseFor()` serves the JSON
        // envelope only to a client that asked for JSON. Asserting 429 here
        // would pin the API shape onto a browser flow.
        $this->post(route('activity-logs.exports.store'), ['format' => 'csv'])
            ->assertRedirect()
            ->assertSessionHasErrors('format');

        $this->assertDatabaseCount('audit_exports', 5);
    }

    /**
     * The ceiling is the SETTING, not the five the test above happens to hit.
     *
     * Two facts in one, because they are the same fact seen from both sides: the
     * limiter stops honouring the default the moment an operator changes it, and
     * the number the Export button's tooltip prints is the same row. Before this
     * moved into `system_settings` (§11 P10-E9) the tooltip said "Maximum 5 per
     * hour" as a literal beside a limiter that could not be changed at all —
     * which meant the label could not have been true after the first edit.
     */
    public function test_the_export_ceiling_is_read_from_system_settings(): void
    {
        Queue::fake();

        $this->login(SystemRole::ADMIN);

        SystemSetting::set('audit_export_rate_limit_per_hour', '2');

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->post(route('activity-logs.exports.store'), ['format' => 'csv'])
                ->assertRedirect();
        }

        // The third is refused at 2, not at 5.
        $this->post(route('activity-logs.exports.store'), ['format' => 'csv'])
            ->assertRedirect()
            ->assertSessionHasErrors('format');

        $this->assertDatabaseCount('audit_exports', 2);

        $this->get(route('activity-logs.index'))
            ->assertOk()
            ->assertSee('Maximum 2 per hour.', false);
    }

    /**
     * An unknown format is rejected rather than reaching the job.
     */
    public function test_an_unsupported_format_is_rejected(): void
    {
        Queue::fake();

        $this->login(SystemRole::ADMIN);

        $this->post(route('activity-logs.exports.store'), ['format' => 'xlsx'])
            ->assertSessionHasErrors('format');

        $this->assertDatabaseCount('audit_exports', 0);
    }

    /**
     * The file name comes from the row, never from the request.
     *
     * A caller-supplied filename is a path traversal waiting for something to
     * concatenate it; the honest place to refuse is to have no such input at all.
     */
    public function test_the_download_name_is_derived_not_supplied(): void
    {
        $owner = $this->login(SystemRole::ADMIN);
        $export = $this->readyExport($owner);

        $this->assertMatchesRegularExpression('/^audit-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/', $export->downloadName());
        $this->assertStringNotContainsString('..', $export->downloadName());
    }

    /**
     * Neither reading the page nor taking a file writes an audit row.
     */
    public function test_reading_and_exporting_write_no_audit_row(): void
    {
        Notification::fake();

        $admin = $this->login(SystemRole::ADMIN);
        $admin->audit('user.updated', $admin);

        $before = Activity::query()->count();

        $export = $this->readyExport($admin);

        $this->get(route('activity-logs.index'))->assertOk();
        $this->get(route('activity-logs.exports.download', $export))->assertOk();

        $this->assertSame($before, Activity::query()->count());
    }

    /**
     * The completion notice goes to the requester, on both channels, with the
     * download link — and NOT to an audience of operators.
     */
    public function test_completion_notifies_the_requester_on_both_channels(): void
    {
        Notification::fake();

        $admin = $this->login(SystemRole::ADMIN);
        $admin->audit('user.updated', $admin);

        $export = AuditExport::query()->create([
            'user_id' => $admin->getKey(),
            'filters' => [],
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
        ]);

        $this->runJob($export);

        Notification::assertSentTo(
            $admin,
            AuditExportReadyNotification::class,
            function (AuditExportReadyNotification $notification, array $channels, User $notifiable) use ($export): bool {
                $this->assertSame(['database', 'mail'], $channels);

                $payload = $notification->toArray($notifiable);

                // A PATH, not an absolute URL: the inbox links this straight into
                // an `href`, and a stored URL its writer controls is only safe
                // because it can be checked for same-origin. See
                // `NotificationActionLinkTest` for the filter that depends on it.
                $this->assertSame(
                    route('activity-logs.exports.download', $export, absolute: false),
                    $payload['url'],
                );
                $this->assertSame('Download CSV', $payload['action_label']);
                $this->assertStringContainsString('row', implode(' ', $payload['lines']));

                return true;
            },
        );
    }

    /**
     * Parse a CSV string into rows, dropping the trailing blank line.
     *
     * @return array<int, array<int, string>>
     */
    private function csv(string $contents): array
    {
        return array_values(array_filter(array_map(
            'str_getcsv',
            preg_split('/\r\n|\r|\n/', trim($contents)) ?: []
        )));
    }
}

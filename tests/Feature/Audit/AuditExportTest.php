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
use Database\Seeders\SystemSettingSeeder;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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
        //
        // The path comes from the model's own `diskPath()` rather than being
        // invented here, so the fixture cannot drift from what the job writes —
        // and `failed()`'s second unlink only works because the two agree.
        if ($export->status === AuditExportStatus::READY) {
            $path = $export->diskPath();

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
     * The file holds every matching row, exactly once, past the chunk boundary.
     *
     * ## Why this test exists
     *
     * The query-count test below measures 3 rows and 40 rows. Both fit inside one
     * 500-row chunk, so it can never see the cursor. It passed green over a job
     * that wrote 999 lines for 1200 rows: 200 rows missing and 499 duplicated,
     * because the query ordered `created_at DESC` while `chunkById` filtered on
     * `id > <last id of the page>` and ordered `id ASC`. A compliance extract with
     * silently dropped rows is the worst failure this feature has.
     *
     * ## Why 1200
     *
     * Two full chunks and then some, so the second page is mid-stream rather than
     * landing exactly on the boundary.
     */
    public function test_the_export_holds_every_row_once_past_the_chunk_boundary(): void
    {
        Notification::fake();

        $admin = $this->login(SystemRole::ADMIN);

        // Inserted with `created_at` ascending alongside `id`, which is what the
        // table looks like in production — the bug needed this ordering to fire.
        $expected = 1200;

        $this->seedMonotonicRows($expected, $admin);

        $export = AuditExport::query()->create([
            'user_id' => $admin->getKey(),
            'filters' => [],
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
        ]);

        $this->runJob($export);

        $export->refresh();

        $ids = $this->csvIds($export);

        $this->assertSame($expected, $export->row_count, 'row_count disagrees with the file');
        $this->assertCount($expected, $ids, 'the file does not hold one line per matching row');
        $this->assertCount(
            $expected,
            array_unique($ids),
            'the file repeats rows — the chunk cursor re-read a page it had already written'
        );

        $sorted = $ids;
        rsort($sorted, SORT_NUMERIC);

        $this->assertSame(
            $sorted,
            $ids,
            'the file is not in descending id order, so it is not the sequence the viewer showed'
        );
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

    /**
     * Write $rows activity rows with `created_at` ascending alongside `id`.
     *
     * `DB::table()` rather than `$user->audit()` because the model's `created_at`
     * is `now()` for all of them — a table where every row shares a timestamp is
     * not what reproduces a cursor bug that depends on the two orderings
     * disagreeing.
     */
    private function seedMonotonicRows(int $rows, User $actor): void
    {
        $subject = User::factory()->create();

        for ($i = 0; $i < $rows; $i++) {
            DB::table('activity_log')->insert([
                'log_name' => 'default',
                'description' => 'updated',
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'causer_type' => $actor->getMorphClass(),
                'causer_id' => $actor->getKey(),
                'event' => 'user.updated',
                'properties' => json_encode(['ip' => '127.0.0.1']),
                'created_at' => now()->subSeconds($rows - $i),
                'updated_at' => now()->subSeconds($rows - $i),
            ]);
        }
    }

    /**
     * The `id` column of every data line in the generated file, in file order.
     *
     * @return array<int, int>
     */
    private function csvIds(AuditExport $export): array
    {
        $lines = array_values(array_filter(explode("\n", Storage::disk('local')->get($export->path))));

        // The first line is the header.
        array_shift($lines);

        return array_map(static fn (string $line): int => (int) strtok($line, ','), $lines);
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

        (new GenerateAuditExport($export->getKey(), []))
            ->failed(new RuntimeException('disk went away'));

        $export->refresh();

        $this->assertSame(AuditExportStatus::FAILED, $export->status);
        $this->assertFalse($export->isDownloadable());
        Storage::disk('local')->assertMissing($orphan);
    }

    /**
     * No file survives a failure, even the one the row never learned about.
     *
     * `$tries = 3` made this reachable and nothing covered it. The damaging
     * sequence is `writeStream()` succeeding and `$export->update()` then
     * throwing: a COMPLETE CSV of the whole audit trail — every actor's IP and
     * user agent — on disk, while the row's `path` is still null because the
     * update never committed. `failed()` unlinks only what the column says, so it
     * saw null and deleted nothing. Seven days later the nightly sweep removed
     * the ROW, still could not name the file, and the extract outlived its own
     * retention window indefinitely.
     *
     * Two properties are pinned, because the fix needs both. The path is
     * deterministic, so a retry rewrites rather than orphans — that is
     * `diskPath()`. And `failed()` unlinks the computed path as well as the
     * recorded one, so it does not depend on the write that may not have
     * landed.
     *
     * `writeStream()` writes no `path` because the update below is skipped on
     * purpose: this is the state that bug produced, reproduced exactly.
     */
    public function test_a_failed_export_leaves_no_file_behind_even_when_the_row_never_recorded_one(): void
    {
        $admin = $this->login(SystemRole::ADMIN);

        $export = AuditExport::query()->create([
            'user_id' => $admin->getKey(),
            'filters' => [],
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
        ]);

        // The file the job would have written, and the row still knows nothing
        // about it.
        $path = $export->diskPath();
        Storage::disk('local')->put($path, "id,event\n1,user.updated\n");

        $this->assertNull($export->path, 'precondition: the update never committed');
        $this->assertTrue(Storage::disk('local')->exists($path), 'precondition: the file did land');

        (new GenerateAuditExport($export->getKey(), []))
            ->failed(new RuntimeException('db blip between write and update'));

        $this->assertFalse(
            Storage::disk('local')->exists($path),
            'a complete audit extract survived a failed job, past its own retention window'
        );

        $export->refresh();

        $this->assertSame(AuditExportStatus::FAILED, $export->status);
        $this->assertFalse($export->isDownloadable());
    }

    /**
     * A retry rewrites the same path rather than leaving a second file.
     *
     * The other half of the same fix. With a fresh `Str::uuid()` per attempt,
     * three attempts wrote three files and only the last one was ever recorded
     * or reclaimed.
     */
    public function test_a_retry_rewrites_the_same_path(): void
    {
        $admin = $this->login(SystemRole::ADMIN);

        $export = AuditExport::query()->create([
            'user_id' => $admin->getKey(),
            'filters' => [],
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
        ]);

        $expected = 'audit-exports/'.$admin->getKey().'/'.$export->getKey().'.csv';

        $this->assertSame($expected, $export->diskPath());
        $this->assertSame($expected, $export->fresh()->diskPath(), 'the path must not depend on whether the row was reloaded');

        // No user-supplied segment, which is what F2's traversal probe relies on.
        $this->assertStringNotContainsString('..', $expected);
        $this->assertMatchesRegularExpression('#^audit-exports/\d+/\d+\.csv$#', $expected);
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

        $job = new GenerateAuditExport($export->getKey(), []);

        $this->assertSame(3, $job->tries, 'a transient failure must not be permanent');
        $this->assertSame([10, 30], $job->backoff(), 'three tries inside one outage is not three attempts');

        // A row that does not exist: `findOrFail()` throws, which is the cheapest
        // way to make `handle()` fail for real rather than assert on the wrapper.
        $missing = new GenerateAuditExport($export->getKey() + 9999, []);

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
     * A row whose job died, as `failed()` leaves it: no path, no count, no
     * completion stamp.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function failedExport(User $owner, array $overrides = []): AuditExport
    {
        return AuditExport::query()->create(array_merge([
            'user_id' => $owner->getKey(),
            'filters' => ['event' => 'user.updated', 'direction' => 'asc'],
            'status' => AuditExportStatus::FAILED,
            'path' => null,
            'row_count' => null,
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
        ], $overrides));
    }

    /**
     * `failed` had no way forward: the row said so, and the only remaining move
     * was the Export form — which makes a SECOND row for what the operator meant
     * as the same request, and leaves the failed one in the list forever beside a
     * file that does exist.
     *
     * The retry flips the SAME row back to `pending`. Asserted on every field that
     * has to move, because a `pending` row still carrying yesterday's `path` reads
     * as downloadable — `isDownloadable()` looks at status and path together, and
     * a stale path is a link to a file that is about to be overwritten.
     */
    public function test_a_failed_export_can_be_retried_by_its_requester(): void
    {
        Queue::fake();

        $admin = $this->login(SystemRole::ADMIN);
        $export = $this->failedExport($admin);

        $this->assertTrue($export->canRetry());

        $this->post(route('activity-logs.exports.retry', $export))
            ->assertRedirect()
            ->assertSessionHas('status');

        $fresh = $export->fresh();

        $this->assertSame(AuditExportStatus::PENDING, $fresh->status);
        $this->assertSame(1, $fresh->retry_count);
        $this->assertNull($fresh->path);
        $this->assertNull($fresh->row_count);
        $this->assertNull($fresh->completed_at);

        // The window is the REQUEST's seven days, not this click's — moving it
        // would be a retention decision made as a side effect of a button.
        $this->assertTrue($fresh->expires_at->isFuture());

        // One row, not two.
        $this->assertDatabaseCount('audit_exports', 1);

        Queue::assertPushed(
            GenerateAuditExport::class,
            function (GenerateAuditExport $job): bool {
                $this->assertTrue($job->afterCommit, 'a retry must land after the row is readable');

                // The direction comes back off the ROW rather than off the request
                // that is not being repeated. `Closure::call()` rebinds `$this` to
                // the job, which is how a private constructor property is read
                // without reflection in a test that exists to pin this branch.
                $this->assertSame(
                    'asc',
                    (fn (): string => $this->direction)->call($job),
                    'the retry must preserve the order the original request asked for',
                );

                return true;
            },
        );
    }

    /**
     * Three clicks, then the button is gone and the POST is refused.
     *
     * Asserted on the server because the Blade only hides the button: a cap
     * enforced in a template is a cap with a hole shaped like an HTTP request, and
     * the client that will find the hole is the one calling the API.
     */
    public function test_the_retry_is_refused_once_the_budget_is_spent(): void
    {
        Queue::fake();
        Notification::fake();

        $admin = $this->login(SystemRole::ADMIN);
        $export = $this->failedExport($admin, ['retry_count' => AuditExport::maxRetries()]);

        $this->assertFalse($export->canRetry());
        $this->assertTrue($export->isRetryExhausted());

        $this->post(route('activity-logs.exports.retry', $export))->assertStatus(409);

        $this->assertSame(AuditExportStatus::FAILED, $export->fresh()->status);
        $this->assertSame(AuditExport::maxRetries(), $export->fresh()->retry_count);

        Queue::assertNothingPushed();
    }

    /**
     * The window between the checks and the write, closed (pentest F2).
     *
     * `retry()` read the row, checked `failed`, then wrote. Two POSTs could both
     * pass those reads, and the loser dispatched a SECOND job for a row the
     * winner had already claimed: two workers building the same path, and the
     * loser's `retry_count + 1` written from a snapshot that missed the winner's
     * click. The row is moved inside the `retrieved` event — the exact moment this
     * request's snapshot goes stale — so the claim, not the read, has to refuse.
     */
    public function test_a_retry_loses_the_claim_when_the_row_moves_under_it(): void
    {
        Queue::fake();

        $admin = $this->login(SystemRole::ADMIN);
        $export = $this->failedExport($admin);

        // Whoever wins the race claims the row right after this request has read
        // it as `failed`. One shot, so the `fresh()` calls below cannot fire it.
        $moved = false;
        Event::listen('eloquent.retrieved: '.AuditExport::class, function (AuditExport $model) use (&$moved, $export): void {
            if ($moved || $model->getKey() !== $export->getKey()) {
                return;
            }

            $moved = true;

            AuditExport::query()->whereKey($export->getKey())->update([
                'status' => AuditExportStatus::PENDING,
                'retry_count' => 1,
            ]);
        });

        $response = $this->post(route('activity-logs.exports.retry', $export));

        $this->assertTrue($moved, 'the race window never opened, so this probe proves nothing');
        $response->assertStatus(409);
        $this->assertSame(AuditExportStatus::PENDING, $export->fresh()->status);
        $this->assertSame(1, $export->fresh()->retry_count, "the loser's N+1 must not erase the winner's click");
        Queue::assertNothingPushed();
    }

    /**
     * The IDOR this route would have if it trusted the permission alone: both
     * users hold `audit.export`, and the id is attacker-supplied.
     */
    public function test_another_operator_cannot_retry_someone_elses_export(): void
    {
        Queue::fake();

        $owner = $this->login(SystemRole::ADMIN);
        $export = $this->failedExport($owner);

        $this->login(SystemRole::ADMIN);

        $this->post(route('activity-logs.exports.retry', $export))->assertForbidden();

        $this->assertSame(AuditExportStatus::FAILED, $export->fresh()->status);
        Queue::assertNothingPushed();
    }

    /**
     * Only `failed` has anything to retry.
     *
     * The `pending` case is the dangerous one rather than the pointless one: a
     * second dispatch means two workers writing the same path concurrently, and
     * the `row_count` reported is whichever of the two finished last.
     *
     * @return array<string, array{0: AuditExportStatus}>
     */
    public static function unretriableProvider(): array
    {
        return [
            'still generating' => [AuditExportStatus::PENDING],
            'already finished' => [AuditExportStatus::READY],
        ];
    }

    #[DataProvider('unretriableProvider')]
    public function test_an_export_that_is_not_failed_cannot_be_retried(AuditExportStatus $status): void
    {
        Queue::fake();

        $admin = $this->login(SystemRole::ADMIN);
        $export = $this->readyExport($admin, ['status' => $status]);

        $this->post(route('activity-logs.exports.retry', $export))->assertStatus(409);

        $this->assertSame($status, $export->fresh()->status);
        $this->assertSame(0, $export->fresh()->retry_count);
        Queue::assertNothingPushed();
    }

    /**
     * `audit.export` is not `audit.view`, and a retry is an extract like any other.
     */
    public function test_a_user_without_the_export_permission_cannot_retry(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $export = $this->failedExport($owner);

        $reader = $this->login(SystemRole::USER);
        $this->assertFalse($reader->can('audit.export'));

        $this->post(route('activity-logs.exports.retry', $export))->assertForbidden();

        $this->assertSame(AuditExportStatus::FAILED, $export->fresh()->status);
        Queue::assertNothingPushed();
    }

    /**
     * A retry writes NO audit row, for the reason `store()` writes none: the row
     * that records "an administrator exported the audit trail" would be a row in
     * the file the administrator just extracted. `retry_count` on the export row is
     * the record, and it is the same number the cap reads.
     */
    public function test_a_retry_writes_no_audit_row(): void
    {
        Queue::fake();

        $admin = $this->login(SystemRole::ADMIN);
        $export = $this->failedExport($admin);

        $before = Activity::query()->count();

        $this->post(route('activity-logs.exports.retry', $export))->assertRedirect();

        $this->assertSame($before, Activity::query()->count());
        $this->assertSame(1, $export->fresh()->retry_count);
    }

    /**
     * A retry is an extract, so it shares the limiter with the request and the
     * download.
     *
     * Without `throttle:audit-export` on this route, the retry is the cheapest way
     * to produce a whole-table CSV — the route the limit exists to cover, taken
     * through the one door that was left open.
     *
     * SIX rows rather than six clicks on one, and that is the point: the per-row
     * cap is three and the hourly budget is five, so repeatedly retrying ONE row
     * would run into the cap long before the limiter and this test would pass
     * against a route with no limiter on it at all. Each click here is on a
     * different row, so nothing but the limiter can be what stops the last one.
     */
    public function test_the_retry_route_shares_the_export_limiter(): void
    {
        Queue::fake();

        $admin = $this->login(SystemRole::ADMIN);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('activity-logs.exports.retry', $this->failedExport($admin)))
                ->assertRedirect();
        }

        // The web channel answers a throttle with a redirect and an error, not a
        // 429 — the same shape the download route's shared limiter gives.
        $sixth = $this->failedExport($admin);

        $this->post(route('activity-logs.exports.retry', $sixth))
            ->assertRedirect()
            ->assertSessionHasErrors();

        // The row exists — the limiter runs BEFORE the action — but nothing spent
        // its budget, which is what tells the two apart.
        $this->assertSame(0, $sixth->fresh()->retry_count);
    }

    /**
     * The cap is an OPERATOR SETTING, not a constant — this is the case that
     * separates the two.
     *
     * An install whose exports keep dying on something environmental wants the
     * button gone (`0`), and one mid-incident wants it wider. Neither is reachable
     * without a deploy if the number lives in code, which is the same reason
     * `audit_export_rate_limit_per_hour` moved out of `AppServiceProvider`.
     *
     * Asserted through the ROUTE rather than the model, because the claim is not
     * "the getter reads the row" — it is "an operator's change takes effect
     * without a deploy". Zero is the case worth pinning: `getInt()` must not treat
     * `'0'` as absent, or the setting silently becomes 3 everywhere.
     */
    public function test_the_retry_cap_is_an_operator_setting(): void
    {
        Queue::fake();

        $this->seed(SystemSettingSeeder::class);

        $admin = $this->login(SystemRole::ADMIN);

        // The seeded default, and the fallback an install that never ran the
        // seeder gets — the same three, from two different places.
        $this->assertSame(3, AuditExport::maxRetries());

        SystemSetting::set('audit_export_max_manual_retries', '1');
        $this->assertSame(1, AuditExport::maxRetries());

        // A row the job failed on AFTER its one click at this setting: `failed`
        // and `retry_count === 1`, so the only thing left to refuse it is the cap.
        // At the default of 3 this row would still have two clicks in it.
        $spent = $this->failedExport($admin, ['retry_count' => 1]);

        $this->assertTrue($spent->isRetryExhausted());
        $this->post(route('activity-logs.exports.retry', $spent))->assertStatus(409);

        // Zero is a real answer, not an absent one: the button never appears and
        // the row goes straight to "Contact admin". This is the case where a
        // falsy check in `getInt()` would silently hand back the fallback of 3.
        SystemSetting::set('audit_export_max_manual_retries', '0');

        $this->assertSame(0, AuditExport::maxRetries());

        $untouched = $this->failedExport($admin);

        $this->assertFalse($untouched->canRetry());
        $this->assertTrue($untouched->isRetryExhausted());

        $this->post(route('activity-logs.exports.retry', $untouched))->assertStatus(409);

        // And the setting is reversible without a deploy, which is the whole
        // reason it is not a constant.
        SystemSetting::set('audit_export_max_manual_retries', '2');

        $reopened = $this->failedExport($admin, ['retry_count' => 1]);

        $this->assertTrue($reopened->canRetry());
        $this->post(route('activity-logs.exports.retry', $reopened))->assertRedirect();

        // Exactly one dispatch across the whole test, from the single click that
        // was allowed. Every refusal above would have dispatched too if the cap
        // were advisory.
        Queue::assertPushed(GenerateAuditExport::class, 1);
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
     * The DOWNLOAD is on the same limiter, not just the request that creates one.
     *
     * Plan P10-B4 put `throttle:audit-export` on both the export route and the
     * download route; the download shipped without it, so the limit covered half
     * the feature. A limiter that only counts the POST is walked around by
     * pressing Download instead of Export — and the download is the request that
     * actually reads a file off disk.
     *
     * Asserted by exhausting the allowance with POSTs and then asking for a file
     * that already exists: the file is the viewer's own and would otherwise answer
     * 200, so a 429 here can only be the limiter.
     */
    public function test_the_download_route_shares_the_export_limiter(): void
    {
        Queue::fake();

        $admin = $this->login(SystemRole::ADMIN);
        $export = $this->readyExport($admin);

        $this->get(route('activity-logs.exports.download', $export))->assertOk();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('activity-logs.exports.store'), ['format' => 'csv'])
                ->assertRedirect();
        }

        // The web channel answers a throttle with a redirect and an error, not a
        // 429 — the same shape `LoginThrottle::responseFor()` gives the POST
        // route, and pinning 429 here would pin the API envelope onto a
        // browser flow. The point of the assertion is that the limiter FIRED.
        $this->get(route('activity-logs.exports.download', $export))
            ->assertRedirect()
            ->assertSessionHasErrors();
    }

    /**
     * The export button disables itself, and still submits.
     *
     * Lives here rather than beside the other markup assertions in
     * `AuditLogUiRenderTest` because the claim is about how many ROWS AND JOBS one
     * click produces, which is this file's subject — not about how the page looks.
     *
     * ## Where the guard went
     *
     * It used to be an inline `onclick` that disabled the button and then called
     * `form.submit()` — the call was the only way it worked, because HTML evaluates
     * the submitter's state when the submission is triggered: a `type="submit"`
     * button disabled by the time its own click's default action runs aborts that
     * submission, so the disable alone greyed the button out and posted nothing —
     * the exact bug this shipped with once.
     *
     * `AddContentSecurityPolicy` now forbids inline handlers outright, so the
     * button carries `data-disable-on-submit` and the behaviour lives in the
     * listener delegated on `document` in `resources/js/helpers/form-guards.js`.
     * It fires on `submit`, which is after the submission is committed — the moment
     * the old `form.submit()` was reaching around for — and after constraint
     * validation, which the old version skipped. The auth forms carry the same
     * attribute for the same reason.
     *
     * A string assertion cannot prove what a click does. What it does is pin the
     * trap for the next editor, which is worth having here: this assertion was
     * green while the export was completely broken.
     */
    public function test_the_export_button_guards_itself_without_swallowing_the_submit(): void
    {
        // The button only renders for holders of `audit.export`, so the login is a
        // precondition and not a detail.
        $this->login(SystemRole::ADMIN);

        $html = $this->get(route('activity-logs.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<button[^>]*\bdata-disable-on-submit\b[^>]*>/',
            $html,
            'the double-click guard is gone, so one click can queue two exports'
        );
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

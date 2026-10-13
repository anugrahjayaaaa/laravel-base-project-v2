<?php

namespace App\Jobs;

use App\Enums\AuditExportStatus;
use App\Models\Activity;
use App\Models\AuditExport;
use App\Notifications\AuditExportReadyNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Writes one audit export to private storage (Phase 10, P10-E3).
 *
 * ## `chunkById`, never `get()`
 *
 * An unfiltered export is every row the audit table has ever accumulated, which
 * on an installation that has been running for a year is more rows than fit in
 * memory alongside everything else the worker is holding. `chunkById` walks the
 * primary key in slices, so the peak cost is one chunk.
 *
 * The filter comes from `Activity::scopeFiltered()` — the same implementation the
 * viewer reads, so the file is the list the operator was looking at rather than
 * a second approximation of it.
 *
 * ## The stream is assembled before anything is written to disk
 *
 * Rows go into a `php://temp` stream, which stays in memory until it grows past
 * its threshold and then spills to a temporary file of its own. Only a finished,
 * complete CSV is handed to the disk. The alternative — opening the destination
 * and writing rows into it as they arrive — leaves a truncated file behind every
 * failed attempt, and a half-written CSV is a file that opens, parses, and is
 * quietly short.
 *
 * ## `failed()` is the part that is easy to skip
 *
 * A job that throws with the row left at `pending` is a spinner that never
 * resolves: the UI cannot distinguish "still working" from "died four minutes
 * ago", and nobody is told. So the row is marked `failed` there, and a file that
 * landed before the failure is unlinked.
 */
class GenerateAuditExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Delivery follows the OUTERMOST commit.
     *
     * Every queue connection here runs `after_commit => false`, so a job
     * dispatched inside a transaction can run before the row that describes it
     * exists — and this job's first act is to read that row.
     *
     * Set at the DISPATCH site (`->afterCommit()` in
     * `AuditExportRequestAction`), not as a property here: `Illuminate\Bus\Queueable`
     * already declares `public $afterCommit`, and PHP refuses a redeclaration
     * whose default differs — a property here is a fatal at class-load, not a
     * lint error. `AuditExportTest` pins the dispatched job's flag, so the
     * guarantee is a test rather than a convention.
     *
     * @see \App\Actions\V1\Audit\AuditExportRequestAction::run() for the dispatch.
     */

    /**
     * Three attempts, not one.
     *
     * The earlier value was 1, on the reasoning that retrying a file write that
     * failed halfway bills the same answer twice against the rate limiter. That
     * reasoning holds for a HALF-WRITTEN file — but the dominant causes of a
     * failed export are not half-written files. A Redis blip, a database lock
     * timeout, a momentary filesystem error: nothing reached disk at all, and
     * `tries = 1` turned a hiccup into a row the operator sees as `Failed` and
     * has to press the button for again.
     *
     * The partial-write concern is answered where it belongs instead: the stream
     * is assembled in `php://temp` and handed to `writeStream()` only once
     * complete, so there is no truncated file for a retry to find. A retry writes
     * a path keyed on the export's own id and a row that is still `pending`, not
     * a duplicate download — and `$path` is overwritten, never appended to.
     *
     * `failed()` fires only after the FINAL attempt, so the `failed` status and
     * the unlink stay correct as written below.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Long enough for a large extract, short enough that a worker is not held by
     * a job that will be killed anyway. `retry_after` on the queue connection is
     * 90 seconds by default (`REDIS_QUEUE_RETRY_AFTER` is 180 on this install), so
     * a timeout above that would let the job be released and run twice at once.
     *
     * 80 + 15 + 30 = 125 s of wall clock across all three attempts, which stays
     * inside a 180 s retry window with room to spare. See `backoff()`.
     *
     * @var int
     */
    public $timeout = 80;

    /**
     * Seconds to wait before attempts two and three.
     *
     * Without this, a Redis outage burns all three tries inside the backoff the
     * driver itself applies between re-reservations and reports "failed" for what
     * was one incident. With it, the second attempt lands after the first gap has
     * passed. Ten seconds is the Laravel default; raised because the first two
     * causes above — a lock timeout and a transient network error — are usually
     * already clearing by then.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    /**
     * The row to fill in.
     *
     * The id rather than the model: `SerializesModels` would restore the whole
     * row from the database at dispatch time anyway, and a serialized snapshot
     * that the job then overwrites is a second version of the truth. `$filters`
     * and `$direction` travel as values precisely because they are the request as
     * it was made — a filter re-derived from the row at run time would silently
     * change under a deploy.
     *
     * There is no `$sort`. The file is ordered by `id` alone, because that is the
     * column the chunk cursor walks; see `generate()`.
     *
     * @param  int  $exportId
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly int $exportId,
        private readonly array $filters,
        private readonly string $direction = 'desc',
    ) {
    }

    /**
     * Generate the file, record it on the row, and tell the requester.
     *
     * ## Why the body is wrapped
     *
     * `failed()` below fires ONCE, after the last of three attempts. On its own
     * that leaves attempts one and two invisible: `failed_jobs` keeps a single row
     * per job and overwrites its payload on each retry, so when the third attempt
     * fails too, the first two failures — the ones that actually explain WHY — are
     * gone. An operator looking at `failed` then has a stack trace from the last
     * try and no way to see that tries one and two died on a lock timeout while
     * this one died on something else.
     *
     * So every attempt logs itself before rethrowing, keyed by the attempt
     * number. `attempts()` comes from `InteractsWithQueue` and is 1 on the first
     * run. The log is the record; the row is the state.
     *
     * @throws Throwable rethrown unchanged — this is a log, not a recovery
     */
    public function handle(): void
    {
        try {
            $this->generate();
        } catch (Throwable $e) {
            Log::warning('audit.export.attempt_failed', [
                'export_id' => $this->exportId,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * The work itself: build the CSV, write it once, flip the row, notify.
     *
     * Separate from `handle()` so the try/catch above wraps the whole of it
     * rather than a fragment — a failure in the notify step is as much a failed
     * attempt as one in the write, and both belong in the same log line.
     *
     * @throws Throwable
     */
    private function generate(): void
    {
        $export = AuditExport::query()->findOrFail($this->exportId);

        $handle = fopen('php://temp/maxmemory:'.(4 * 1024 * 1024), 'w+');

        if ($handle === false) {
            throw new RuntimeException('could not open a temporary stream for the audit export');
        }

        try {
            fputcsv($handle, $this->header());

            $written = 0;

            // Ordered by `id` and ONLY `id`, because `chunkById` cursors on `id`:
            // it rewrites the ordering to `id ASC` and filters
            // `WHERE id > <last row of the previous page>`. A leading
            // `created_at` survives that rewrite as the primary sort key while the
            // cursor still walks `id`, and the two disagree — measured on 1200 rows
            // sorted `created_at desc`, the file held 999 lines of which only 500
            // were distinct: 200 rows never reached the CSV and 499 appeared twice.
            //
            // On an append-only audit table `id` order IS `created_at` order — `id`
            // is already the tiebreaker the viewer sorts on — so the file is the
            // sequence the operator was looking at.
            $chunk = $this->direction === 'desc' ? 'chunkByIdDesc' : 'chunkById';

            Activity::filtered($this->filters)
                ->orderBy('id', $this->direction)
                // Two morphTo relations per row. Without this the export runs one
                // query PER ROW to resolve a name that is already in the table,
                // which on an unfiltered export is a self-inflicted denial of
                // service against the database and the worker alike.
                ->with(['causer', 'subject'])
                ->{$chunk}(500, function ($rows) use ($handle, &$written): void {
                    foreach ($rows as $activity) {
                        fputcsv($handle, $this->row($activity));
                        $written++;
                    }
                });

            rewind($handle);

            $path = $export->diskPath();

            // The private disk, never `public`. An audit extract carries every
            // actor's IP and user agent; a file under `storage/app/public` is one
            // `php artisan storage:link` away from being world-readable.
            Storage::disk('local')->writeStream($path, $handle);
        } finally {
            fclose($handle);
        }

        $export->update([
            'path' => $path,
            'row_count' => $written,
            'status' => AuditExportStatus::READY,
            'completed_at' => now(),
        ]);

        $export->user->notify(new AuditExportReadyNotification($export));

        Log::notice('audit.export.completed', [
            'export_id' => $export->id,
            'user_id' => $export->user_id,
            'row_count' => $written,
        ]);
    }

    /**
     * The row is marked failed and the file is unlinked.
     *
     * Called by the queue only after the final attempt, so a retried job never
     * reports a failure it was about to recover from.
     *
     * ## Two paths, not one
     *
     * The recorded `path` AND the path this export would have used. The column is
     * written after the file lands, so on the most damaging sequence — write
     * succeeds, `update()` throws — it is still `null` while a complete extract
     * is sitting on disk. Unlinking only the column reclaimed nothing in exactly
     * that case, and the sweep that ran seven days later deleted the row and
     * still could not name the file, so it outlived its own retention window.
     *
     * `diskPath()` is deterministic, so the second unlink is computable from the
     * row alone. The first is kept for rows written before the path became
     * deterministic: those carry a UUID file name, and nothing else in the
     * application knows it.
     */
    public function failed(?Throwable $exception): void
    {
        $export = AuditExport::query()->find($this->exportId);

        if ($export === null) {
            return;
        }

        foreach (array_unique(array_filter([$export->path, $export->diskPath()])) as $path) {
            Storage::disk('local')->delete($path);
        }

        $export->update(['status' => AuditExportStatus::FAILED]);

        Log::error('audit.export.failed', [
            'export_id' => $export->id,
            'user_id' => $export->user_id,
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * The CSV header.
     *
     * Fixed order, and it answers in the order a reader of a log asks: what
     * happened, who, to what, from where, when — then the context the
     * application derived, which is what ties the file back to a request id in
     * an error trace.
     *
     * @return array<int, string>
     */
    private function header(): array
    {
        return [
            'id',
            'occurred_at',
            'event',
            'description',
            'actor',
            'actor_id',
            'target',
            'target_type',
            'target_id',
            'source',
            'ip',
            'request_id',
            'properties',
        ];
    }

    /**
     * One row.
     *
     * `causerLabel()` and `subjectLabel()` are the model's own resolvers, so the
     * file says "Budi Santoso" where the viewer says "Budi Santoso" — including
     * the two answers that are easy to get wrong: a deleted actor is named by id
     * rather than rendered as "Anonymous", and a subject-less event is an empty
     * cell rather than a zero.
     *
     * `properties` is the raw JSON, not the flattened map. A file is read by
     * something other than this application, and `{"changedFields":{"email":…}}`
     * survives a reader that does not know about a flattening convention. It is
     * the JSON column verbatim.
     *
     * ## Why the cast is read once into a local
     *
     * `properties` is cast `'collection'`, and Eloquent does NOT memoise a
     * primitive cast: `HasAttributes::transformModelValue()` calls
     * `castAttribute()` on every attribute read, and the `collection` case is
     * `new Collection(json_decode($value, true))` — a fresh decode every time.
     * Measured: a warm read costs the same as a cold one (16.3µs vs 15.1µs).
     *
     * Four columns here read it — `source()` internally reads `extra('source')`,
     * plus `ip`, `request_id` and the JSON dump — so the row paid four decodes.
     * One read into `$props` and four lookups on the result is a quarter of the
     * work for the same output.
     *
     * `$props` is null on exactly the rows where `extra()` returned its default,
     * so the null-tolerance `extra()` documents (`Activity::extra()` exists
     * because a NULL `properties` column used to be a fatal here) is unchanged.
     *
     * @return array<int, string>
     */
    private function row(Activity $activity): array
    {
        $props = $activity->properties;

        return [
            (string) $activity->id,
            (string) $activity->created_at,
            $activity->eventName(),
            (string) $activity->description,
            $activity->causerLabel(),
            (string) ($activity->causer_id ?? ''),
            $activity->subjectLabel(),
            (string) ($activity->subject_type ?? ''),
            (string) ($activity->subject_id ?? ''),
            (string) ($props?->get('source') ?? 'unknown'),
            (string) ($props?->get('ip') ?? ''),
            (string) ($props?->get('request_id') ?? ''),
            (string) json_encode($props?->all()),
        ];
    }
}

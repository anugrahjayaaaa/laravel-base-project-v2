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
use Illuminate\Support\Str;
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
     * One attempt.
     *
     * Retrying a file write that failed halfway bills the same answer twice
     * against the rate limiter for the same request. The row records the failure
     * and the requester presses the button again.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Long enough for a large extract, short enough that a worker is not held by
     * a job that will be killed anyway. `retry_after` on the queue connection is
     * 90 seconds by default, so a timeout above that would let the job be
     * released and run twice at once.
     *
     * @var int
     */
    public $timeout = 80;

    /**
     * The row to fill in.
     *
     * The id rather than the model: `SerializesModels` would restore the whole
     * row from the database at dispatch time anyway, and a serialized snapshot
     * that the job then overwrites is a second version of the truth. `$filters`,
     * `$sort` and `$direction` travel as values precisely because they are the
     * request as it was made — a filter re-derived from the row at run time would
     * silently change under a deploy.
     *
     * @param  int  $exportId
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly int $exportId,
        private readonly array $filters,
        private readonly string $sort = 'created_at',
        private readonly string $direction = 'desc',
    ) {
    }

    /**
     * Generate the file, record it on the row, and tell the requester.
     */
    public function handle(): void
    {
        $export = AuditExport::query()->findOrFail($this->exportId);

        $handle = fopen('php://temp/maxmemory:'.(4 * 1024 * 1024), 'w+');

        if ($handle === false) {
            throw new RuntimeException('could not open a temporary stream for the audit export');
        }

        try {
            fputcsv($handle, $this->header());

            $rows = 0;

            Activity::filtered($this->filters)
                ->orderBy($this->sort, $this->direction)
                ->orderBy('id', $this->direction)
                // Two morphTo relations per row. Without this the export runs one
                // query PER ROW to resolve a name that is already in the table,
                // which on an unfiltered export is a self-inflicted denial of
                // service against the database and the worker alike.
                ->with(['causer', 'subject'])
                ->chunkById(500, function ($chunk) use ($handle, &$rows): void {
                    foreach ($chunk as $activity) {
                        fputcsv($handle, $this->row($activity));
                        $rows++;
                    }
                });

            rewind($handle);

            $path = 'audit-exports/'.$export->user_id.'/'.Str::uuid().'.csv';

            // The private disk, never `public`. An audit extract carries every
            // actor's IP and user agent; a file under `storage/app/public` is one
            // `php artisan storage:link` away from being world-readable.
            Storage::disk('local')->writeStream($path, $handle);
        } finally {
            fclose($handle);
        }

        $export->update([
            'path' => $path,
            'row_count' => $rows,
            'status' => AuditExportStatus::READY,
            'completed_at' => now(),
        ]);

        $export->user->notify(new AuditExportReadyNotification($export));

        Log::notice('audit.export.completed', [
            'export_id' => $export->id,
            'user_id' => $export->user_id,
            'row_count' => $rows,
        ]);
    }

    /**
     * The row is marked failed and the file is unlinked.
     *
     * Called by the queue only after the final attempt, so a retried job never
     * reports a failure it was about to recover from.
     */
    public function failed(?Throwable $exception): void
    {
        $export = AuditExport::query()->find($this->exportId);

        if ($export === null) {
            return;
        }

        if ($export->path !== null) {
            Storage::disk('local')->delete($export->path);
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
     * @return array<int, string>
     */
    private function row(Activity $activity): array
    {
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
            $activity->source(),
            (string) ($activity->extra('ip') ?? ''),
            (string) ($activity->extra('request_id') ?? ''),
            (string) json_encode($activity->properties?->all()),
        ];
    }
}

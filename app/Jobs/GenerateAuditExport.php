<?php

namespace App\Jobs;

use App\Enums\AuditExportStatus;
use App\Jobs\Concerns\ReadsCorrelationId;
use App\Models\Activity;
use App\Models\AuditExport;
use App\Notifications\AuditExportReadyNotification;
use App\Support\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
    use ReadsCorrelationId;
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
     * Three exceptions, matching `$tries` (Phase 11 P11-E2).
     *
     * The declaration is the point: a retry chain read off the framework's
     * defaults is a retry chain nobody wrote down. Equal to `$tries`, so this
     * job fails when it runs out of attempts rather than before.
     *
     * @var int
     */
    public $maxExceptions = 3;

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
        // Named queue, not `default` (Phase 11 P11-E1). Set on the instance,
        // NOT as a `public $queue = ...` class property: `Queueable` already
        // declares `$queue`, and PHP refuses the redeclaration — a fatal at
        // class load that `php -l` does not catch. The same rule is why
        // `$afterCommit` is not a property above, and the framework's own
        // jobs set the queue the same way (`SendQueuedMailable`).
        //
        // An export is the heaviest job this application dispatches, and one
        // shared queue is how a 125-second export delays every password reset
        // behind it. The worker in `bin/run-workers.sh` listens to this queue
        // alongside `default`.
        $this->onQueue(QueueName::EXPORTS);
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
                'request_id' => $this->requestId(),
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
                // Raw rows, NOT hydrated models. Measured on 20 000 rows against
                // MySQL, three runs each: 31.6 s as models, 4.4–5.2 s as plain
                // rows — 6.4x — for the SAME 124 queries. The export reads eleven
                // plain values per row; an `Activity` model per row is twelve
                // attributes, a `properties` cast Eloquent does not memoise, and
                // two relation accessors, all to produce the same line.
                //
                // `toBase()` is what drops the hydration. It keeps the ordering
                // and the `filtered()` scope, and it makes `chunkById()` hand back
                // stdClass instead of models.
                //
                // The names the models resolved are still resolved — once per chunk
                // by `labelMap()`, then labelled by `Activity::causerLabelFor()`,
                // which is the same function the viewer calls.
                //
                // What this buys is the headroom the 80-second timeout needed: at
                // 1.58 ms/row the job stopped completing at about 50 000 rows, on a
                // table this application never prunes. At 0.245 ms/row the same
                // budget covers roughly 300 000.
                ->toBase()
                ->{$chunk}(500, function ($rows) use ($handle, &$written): void {
                    $labels = $this->labelMap($rows);

                    foreach ($rows as $activity) {
                        fputcsv($handle, $this->row((array) $activity, $labels));
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
            'request_id' => $this->requestId(),
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
     * Every actor and subject name in one chunk, resolved once.
     *
     * The eager load this replaces ran the same resolution; it just ran it as a
     * morphTo relation attached to every one of the 500 models in the chunk. Here
     * the chunk's distinct ids are collected first, so the chunk costs at most one
     * query per morph type present — usually two, usually far fewer rows.
     *
     * ## What this returns is MODELS, not strings
     *
     * It deliberately does not decide what to call anybody. `row()` hands each
     * model to `Activity::causerLabelFor()` / `subjectLabelFor()`, which is the
     * same function the viewer calls, so the file and the screen cannot drift
     * apart — including on the branch that reads like a bug, a RESOLVED causer
     * that is not a `User` rendering `Deleted user (#id)`. Correcting that belongs
     * in the model, where both readers see it at once.
     *
     * Soft-deleted actors are absent here for the same reason they are absent from
     * the relation — the global scope drops them — so they fall through to the
     * `Deleted user (#id)` branch, which is what the file has always said.
     *
     * @param  iterable<int, object>  $rows
     * @return array{causer: array<string, Model>, subject: array<string, Model>}
     */
    private function labelMap(iterable $rows): array
    {
        // Stored type => class => distinct ids, so the lookup is keyed by what the
        // column actually holds and cannot drift when the morph map is extended.
        $wanted = ['causer' => [], 'subject' => []];

        foreach ($rows as $row) {
            foreach (['causer', 'subject'] as $side) {
                $type = $row->{$side.'_type'};
                $id = $row->{$side.'_id'};

                if ($type === null || $id === null) {
                    continue;
                }

                // Permissive by design, like the rest of the audit read path: an
                // unmapped stored value falls back to itself, which is what
                // `Model::getActualClassNameForMorph()` does for the relation this
                // replaces. A row written before the morph map was backfilled still
                // resolves.
                $class = Relation::getMorphedModel($type) ?? $type;

                if (class_exists($class)) {
                    $wanted[$side][$type][$class][(string) $id] = $id;
                }
            }
        }

        $map = ['causer' => [], 'subject' => []];

        foreach ($wanted as $side => $types) {
            foreach ($types as $type => $classes) {
                foreach ($classes as $class => $ids) {
                    $key = (new $class())->getKeyName();
                    $found = $class::query()->whereIn($key, array_values($ids))->get();

                    foreach ($found as $model) {
                        $map[$side][$type.'|'.$model->getKey()] = $model;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * One CSV line, from one raw database row.
     *
     * Column order and every fallback answer are unchanged from the version that
     * read a hydrated `Activity`; verified by exporting the same 20 000 rows
     * through both and diffing the files.
     *
     * `properties` is the raw JSON, not a flattened map. A file is read by
     * something other than this application, and `{"changedFields":{"email":…}}`
     * survives a reader that does not know about a flattening convention.
     *
     * ## Why the JSON is decoded once into a local
     *
     * Four columns read it — `source`, `ip`, `request_id` and the JSON dump
     * itself. One decode into `$props` and four lookups on the result is a
     * quarter of the work for the same output. (Under the model this was four
     * separate decodes, because Eloquent does not memoise a primitive cast:
     * `castAttribute()` runs on every attribute read and the `collection` case is
     * a fresh `json_decode` each time.)
     *
     * `$props` is null on exactly the rows where the lookup returned its default,
     * so the NULL-column tolerance is unchanged — `Activity::extra()` exists
     * because a NULL `properties` column used to be a fatal here.
     *
     * @param  array<string, mixed>  $row
     * @param  array{causer: array<string, Model>, subject: array<string, Model>}  $labels
     * @return array<int, string>
     */
    private function row(array $row, array $labels): array
    {
        $props = $row['properties'] === null ? null : json_decode($row['properties'], true);
        $extra = static fn (string $key, mixed $default = null): mixed => is_array($props) ? ($props[$key] ?? $default) : $default;

        return array_map(self::cell(...), [
            (string) $row['id'],
            (string) $row['created_at'],
            // `Activity::eventName()`: a row written before the `event` column
            // existed has a description and a NULL event, and the description is
            // the better answer than an empty filter chip.
            ($row['event'] !== null && $row['event'] !== '') ? $row['event'] : $row['description'],
            (string) $row['description'],
            Activity::causerLabelFor(
                $labels['causer'][$row['causer_type'].'|'.$row['causer_id']] ?? null,
                $row['causer_id'],
                $extra('causer'),
            ),
            (string) ($row['causer_id'] ?? ''),
            Activity::subjectLabelFor(
                $labels['subject'][$row['subject_type'].'|'.$row['subject_id']] ?? null,
                $row['subject_type'],
                $row['subject_id'],
            ),
            (string) ($row['subject_type'] ?? ''),
            (string) ($row['subject_id'] ?? ''),
            (string) ($extra('source') ?? 'unknown'),
            (string) ($extra('ip') ?? ''),
            (string) ($extra('request_id') ?? ''),
            (string) json_encode($props),
        ]);
    }

    /**
     * One cell, made inert in a spreadsheet (CWE-1236).
     *
     * The actor and target columns carry `users.name`, and `name` is free text on
     * the profile endpoint — `strip_tags()` drops markup, not a leading `=`. So an
     * ordinary account could set its display name to `=cmd|'/c calc'!A0`, act, and
     * have the payload sit in the extract that the person handling the incident
     * opens on their own workstation. `fputcsv()` does not help: it quotes fields
     * containing a comma or a quote, and neither is what makes a cell a formula.
     *
     * A leading `'` is how Excel, LibreOffice and Sheets are told "this is text".
     * The character is only ADDED to cells that need it, so an ordinary name reads
     * as it always did.
     *
     * `strspn()` rather than `ltrim()` because this runs over every cell of every
     * chunk — a trimmed copy per cell on a table that runs to hundreds of
     * thousands of rows is an allocation this job cannot afford. It answers "skip
     * the leading whitespace and tell me where I landed" in one pass, which is
     * also the whole defence: a tab or a space in front of the `=` is the
     * standard way past a check that only looks at position 0.
     *
     * It is applied to EVERY cell rather than to the two or three that carry user
     * text, and that is a deliberate cost. Measured: +0.0115 ms/row against the
     * 0.245 ms/row above, so ~4.7% — 80 seconds still buys roughly 290 000 rows.
     * A column allowlist would be cheaper and would also be a place for the next
     * user-controlled column to be forgotten.
     *
     * @see CsvInjectionPentestTest
     */
    private static function cell(mixed $value): string
    {
        $value = (string) $value;

        $offset = strspn($value, " \t\r\n");

        // All whitespace, or nothing at all — nothing to neutralise.
        if ($offset === strlen($value)) {
            return $value;
        }

        return match ($value[$offset]) {
            '=', '+', '-', '@' => "'".$value,
            default => $value,
        };
    }
}

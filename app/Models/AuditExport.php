<?php

namespace App\Models;

use App\Enums\AuditExportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One requested audit export — the hand-off row between the queue and the HTTP
 * download (Phase 10, P10-E1).
 *
 * ## Why the whole row is fillable
 *
 * Every column here is written by this module and none of it is ever bound to a
 * request: `AuditExportRequestAction` creates the row with named keys, and the
 * job updates named keys. The attribute is what makes `create()` and `update()`
 * work at all — Eloquent's default is `$guarded = ['*']`, which throws on any
 * mass assignment — and it matches how every other model in `app/Models` declares
 * its writable surface.
 *
 * @property int $id
 * @property int $user_id
 * @property array<string, mixed> $filters
 * @property AuditExportStatus $status
 * @property string|null $path
 * @property int|null $row_count
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property-read \App\Models\User $user
 */
#[Fillable([
    'user_id',
    'filters',
    'status',
    'path',
    'row_count',
    'expires_at',
    'completed_at',
])]
class AuditExport extends Model
{
    /**
     * How long a generated file stays downloadable.
     *
     * `docs/base/operations/retention.md:12` — "Audit export files | 7 days
     * after generation". Seven, not the 24 hours on `:22`: that row is the generic
     * entry for `storage/tmp`, and this file is written to the private disk's
     * `audit-exports/` directory, not to `storage/tmp`.
     *
     * A constant rather than a `SystemSetting` — a setting here means a seeder
     * row, a form field, a validation rule and a UI control for a number no
     * operator has asked to move. The same reasoning `routes/console.php` uses
     * for the 90-day notification prune.
     */
    public const RETENTION_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'status' => AuditExportStatus::class,
            'row_count' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, AuditExport>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Has the retention window passed?
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Is there a file to serve?
     *
     * Every clause matters and none of them is decoration:
     *
     * - `ready` — a failed job has no file, and a pending one does not either;
     * - a `path` — the column is nullable, and the check lives here so no caller
     *   re-derives it from the two columns and forgets one;
     * - not expired — the download route answers 410 past this point, and the file
     *   is still on disk until the nightly sweep unlinks it.
     *
     * The file's existence on disk is deliberately NOT part of this: that is a
     * disk hit, and this is also read by the Blade that renders a status badge.
     * The download route checks the disk and answers 404 when a file has gone
     * while its row has not been swept yet.
     */
    public function isDownloadable(): bool
    {
        return $this->status === AuditExportStatus::READY
            && $this->path !== null
            && ! $this->isExpired();
    }

    /**
     * Where this export's file lives on the private disk.
     *
     * ## Deterministic, and that is the load-bearing part
     *
     * This was `audit-exports/{user_id}/{uuid}.csv` with a fresh `Str::uuid()`
     * per attempt. A retry therefore wrote a SECOND file while the first one
     * stayed on disk, and `failed()` could only unlink the path recorded in the
     * column — which is written *after* the write succeeds. So the exact sequence
     * `writeStream()` OK → `update()` throws left a complete CSV of the whole
     * audit trail, every actor's IP and user agent included, on disk with
     * `path = NULL`. `failed()` saw null and deleted nothing; the nightly sweep
     * later removed the ROW and still could not find the file. It survived
     * `retention.md`'s seven days indefinitely.
     *
     * Keyed on the row's own id instead, a retry rewrites the same path, and
     * `failed()` can COMPUTE the path from the row rather than trusting a column
     * that may never have been written. The id is an integer from the database,
     * so there is still no user-supplied path segment.
     *
     * ## It is a method, not a literal, because there are two readers
     *
     * The job that writes it and the cleanup that has to reclaim it. A second
     * copy of the shape is a second thing to forget — which is exactly what
     * happened when it lived inline in the job alone.
     */
    public function diskPath(): string
    {
        return 'audit-exports/'.$this->user_id.'/'.$this->getKey().'.csv';
    }

    /**
     * The filename handed to the browser.
     *
     * Derived from the row rather than stored, so it cannot disagree with the
     * status: a `pending` row has no filename to show, and a stored one would go
     * on showing yesterday's name for a file regenerated today.
     *
     * `audit-2026-10-10-1432.csv` — timestamped to the minute so two exports on
     * the same day are distinguishable in a downloads list.
     */
    public function downloadName(): string
    {
        $at = $this->completed_at ?? $this->created_at ?? Carbon::now();

        return 'audit-'.$at->format('Y-m-d-His').'.csv';
    }
}

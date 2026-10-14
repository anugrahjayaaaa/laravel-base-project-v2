<?php

namespace App\Models;

use App\Enums\AuditExportStatus;
use App\Support\SystemRole;
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
 * @property int $retry_count
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
    'retry_count',
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
     * How many times the requester may re-run this one export by hand.
     *
     * A `SystemSetting`, not a constant, for the reason
     * `audit_export_rate_limit_per_hour` is one: the number an operator is held to
     * and the number they can see are then the same number, and changing the cost
     * of one export does not need a deploy. It is read through ONE method so the
     * key appears once in the codebase — the Action, the resource, the Blade and
     * the tests all call `maxRetries()` and none of them repeats the literal.
     *
     * Three is a ceiling, not the encouragement — and it is counted in CLICKS while
     * `GenerateAuditExport::$tries = 3` already retries inside each one, so one row
     * at the default can cost nine whole-table CSV builds. What the number buys is
     * the opposite of another retry: past it the row says `Contact admin`, because
     * by then the failure is not a hiccup in the requester's patience. The dominant
     * causes are environmental — a worker that cannot reach a dependency — and
     * re-running the same query against the same broken thing is a way to look
     * busy. An operator who reads it that way sets it to `0` and the button never
     * appears, which is why the settings field allows zero.
     */
    public static function maxRetries(): int
    {
        return SystemSetting::getInt('audit_export_max_manual_retries', self::DEFAULT_MAX_RETRIES);
    }

    /**
     * The fallback for an install where the seeder never ran — and only that.
     *
     * Private so nobody reaches for it as the answer: a caller that wants the cap
     * wants the LIVE value, and a caller that wants the shipped default wants
     * `SystemSettingSeeder`, which is where the default is seeded.
     */
    private const DEFAULT_MAX_RETRIES = 3;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'status' => AuditExportStatus::class,
            'retry_count' => 'integer',
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
     * Is this the requester's export, or is the requester the break-glass role?
     *
     * ## Why it is here and not in the download action
     *
     * Two routes now take an id in the URL and must both answer it: the download
     * and the retry. A check that lives in one action is a check the other action
     * has to remember to grow, and the failure mode is not a 500 — it is a 200 on
     * somebody else's extract. So the fact about the row lives on the row.
     *
     * `isOwnedBy()`, not `belongsTo()`: `Model::belongsTo()` is Eloquent's own
     * relation builder and its first argument is a class name, so a method with
     * that name here is a fatal at class-load — not a lint error, and not
     * something a route test would show until a worker autoloaded the model.
     *
     * `hasRole(SUPERADMIN)` and not a Gate call, because this is an ownership
     * question about a row and not a permission question — `AppServiceProvider`'s
     * `Gate::before()` answers "can they", never "is it theirs", and borrowing it
     * would let a superadmin's bypass become the reason someone else's extract is
     * served.
     */
    public function isOwnedBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->getKey()
            || $user->hasRole(SystemRole::SUPERADMIN);
    }

    /**
     * May this row be run again, right now?
     *
     * Both halves are load-bearing. `failed` alone would re-dispatch a job that is
     * already running, so two workers write the same path and the second one's
     * `row_count` overwrites the first's. The cap alone would offer a retry on a
     * `ready` row, where the honest answer is the Download button one column away.
     */
    public function canRetry(): bool
    {
        return $this->status === AuditExportStatus::FAILED
            && $this->retry_count < static::maxRetries();
    }

    /**
     * Has this row spent its whole manual-retry budget?
     *
     * A separate question from `canRetry()` rather than its negation, because the
     * two are not complements: a `ready` row satisfies neither. The view has to
     * tell those three cases apart — retry, "contact admin", and no action at all —
     * and one boolean cannot carry three answers.
     */
    public function isRetryExhausted(): bool
    {
        return $this->status === AuditExportStatus::FAILED
            && $this->retry_count >= static::maxRetries();
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

<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Auditable trait: the single entry point for writing an audit record.
 *
 * ## This is the only place that knows what a row carries
 *
 * Callers pass only what is specific to their event — `identifier`,
 * `lock_duration_seconds`, `remember`. Everything that answers *where did this
 * come from* is derived here, so every row in the table answers it the same way.
 *
 * That matters because the alternative was already tried and failed: each caller
 * assembled its own context, and `request()->is('api/*') ? 'api' : 'web'` ended
 * up copied across six files under two different key names (`source` and
 * `channel`), with only some call sites carrying a user agent. A row written by
 * one path could not be compared with a row written by another.
 *
 * ## Overriding a derived value
 *
 * Pass the key in `$properties` and it wins — that is how a non-HTTP caller (a
 * queued job, say) states `source => system` rather than being mislabelled `web`
 * because there was no request in scope.
 */
trait Auditable
{
    /**
     * Log an audit event on the model.
     *
     * @param string $event Event name
     * @param Model|null $causer User who triggered the event
     * @param array<string, mixed> $properties Event-specific properties. May
     *        carry `source`, `ip` or `user_agent` to override the derived values.
     * @return void
     */
    public function audit(string $event, ?Model $causer = null, array $properties = []): void
    {
        $activity = activity()
            ->on($this)
            ->event($event)
            ->causedBy($causer);

        if ($causer === null) {
            $activity->causedByAnonymous();
        }

        $activity
            ->withProperties(array_merge(static::auditContext(), $properties))
            ->log($event);
    }

    /**
     * The context every audit row carries, derived once.
     *
     * Public and static so the one writer that has no model to call it through
     * — `Controller::audit()`, pending AUD-006 — takes the same derivation rather
     * than its own. It goes when that method does; the normal path is
     * `audit()` above, and no caller should assemble this inline.
     *
     * Merged first so a caller-supplied value overrides it, not the other way
     * round. That is the whole extension mechanism: a queued job passes
     * `source => system` and nothing else changes.
     *
     * @return array<string, mixed>
     */
    public static function auditContext(): array
    {
        $request = request();

        return [
            'source' => $request->is('api/*') ? 'api' : 'web',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            // The correlation ID, which `GenerateRequestCorrelationId` binds on
            // every request and echoes back on the `X-Request-ID` header. Without
            // it, an operator holding a 403 body with `meta.request_id` in it
            // cannot tie that request to any audit row — the one piece of
            // metadata that would make the log and the error trace agree was the
            // one piece never captured.
            //
            // `bound()` is load-bearing, not defensive: a queued job has no
            // request, and `app('request_id')` read blindly throws
            // `BindingResolutionException` from inside the audit write — turning
            // a scheduled job into a failure at the moment it tried to record
            // why something happened. A null here is the honest answer, and the
            // viewer already renders `—` for it.
            'request_id' => app()->bound('request_id') ? app('request_id') : null,
        ];
    }

    /**
     * Write one audit row per subject for a bulk mutation, in one insert.
     *
     * ## Why this bypasses the builder
     *
     * `audit()` above uses Spatie's builder, which logs one row per call. A bulk
     * action over 200 users would mean 200 round trips inside a transaction that
     * already holds locks on those rows. The raw insert writes them in one
     * statement.
     *
     * The cost is that nothing is derived for us — which is why the context is
     * built by `auditContext()` rather than assembled here. A hand-written
     * `['source' => $type]` is what this replaced: every caller passed the
     * default `'web'`, so an API bulk action recorded `source => web` on every
     * row, and no bulk row anywhere in the table carried an IP or a user agent.
     * A bulk row is now identical in shape to a single one.
     *
     * ## `event`, not just `description`
     *
     * The raw insert bypasses Spatie's builder, so nothing fills `event`. It used
     * to leave it NULL while writing `description` — and every reader in this
     * codebase filters on `event`. A bulk row was present in the table and
     * invisible to every filter built on that column.
     *
     * ## `batch_uuid`
     *
     * One UUID for the whole request, which is what makes the rows correlatable
     * as the single user action they were. Spatie does the same for its own batch
     * logging.
     *
     * ## `getMorphAlias()`, not the bare class name
     *
     * The raw insert bypasses Eloquent, so it also bypasses
     * `Model::getMorphClass()` — the one place that converts a class into its
     * morph alias. Writing `$causer::class` here stored `App\Models\User` while
     * every single-row audit (which goes through Spatie's builder) stored `user`,
     * so one bulk action left two spellings of the same type in the table. The
     * viewer accepts either so the divergence is invisible there, and loud in the
     * filter dropdown and in the export, which compare the stored value verbatim.
     *
     * `getMorphAlias()` returns the class unchanged when it is not in the map,
     * which is the correct answer for a model nobody has aliased.
     *
     * @param  string  $event
     * @param  array<int, array{subject_id: int|string, properties?: array<string, mixed>}>  $records
     * @param  Model|null  $causer
     * @param  class-string<Model>  $subjectType
     */
    public static function auditBulk(
        string $event,
        array $records,
        ?Model $causer = null,
        string $subjectType = User::class
    ): void {
        if ($records === []) {
            return;
        }

        $now = now()->toDateTimeString();
        $batchUuid = (string) Str::uuid();
        $context = static::auditContext();

        $subjectAlias = Relation::getMorphAlias($subjectType);
        $causerAlias = $causer === null ? null : Relation::getMorphAlias($causer::class);

        $rows = array_map(function (array $record) use ($event, $subjectAlias, $causerAlias, $causer, $now, $batchUuid, $context) {
            return [
                'log_name' => 'default',
                'description' => $event,
                'event' => $event,
                'subject_type' => $subjectAlias,
                'subject_id' => $record['subject_id'],
                'causer_type' => $causerAlias,
                'causer_id' => $causer?->getKey(),
                'properties' => json_encode(array_merge($context, $record['properties'] ?? [])),
                'batch_uuid' => $batchUuid,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $records);

        DB::table(config('activitylog.table_name', 'activity_log'))->insert($rows);
    }
}

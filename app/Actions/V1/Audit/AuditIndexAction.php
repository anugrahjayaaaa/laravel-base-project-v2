<?php

namespace App\Actions\V1\Audit;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The audit viewer's list query: search, filter, sort, paginate.
 *
 * ## Why the dropdown options live here and not in the view
 *
 * The filter bar lists every event name that exists, every actor who has done
 * anything, and every subject type present. All three are derived from the data,
 * so they are queries on the same table the page is reading — not hand-typed
 * arrays in Blade, which go stale the first time an action adds an event and fail
 * silently. That is the same failure the Phase 10 breakdown records as finding F4
 * for `log_name`.
 *
 * ## Search deliberately does not touch `properties`
 *
 * `Activity::scopeFiltered()` owns the filter and explains why search covers
 * `event` and `description` only. It lives on the model rather than here because
 * the export job walks the same rows (Phase 10 Group E) — see that scope.
 *
 * ## Eager loading is not optional here
 *
 * `causer` and `subject` are two `morphTo` relations. Lazy-loaded that is two
 * queries PER ROW — page 2 of the audit log is twenty queries to render what is
 * really one joined read. `AuditBenchmarkTest` is what proves the count stays
 * constant rather than trusting the code to look right.
 *
 * ## No cache — for the ROWS
 *
 * `UserIndexAction` caches its counts because the totals are one conditional
 * aggregate nobody wants to recompute. This page has no totals block, and the
 * alternative — a cached snapshot of a viewer-dependent result set — is the trap
 * `UserIndexAction::bustCache()` documents: the first caller's rows handed to
 * everyone else. Audit rows are also the least cacheable data in the application,
 * because an investigation wants the row that was just written, not the one that
 * was there a minute ago.
 *
 * That argument is about the ROW LIST and it is correct. It does not reach the
 * four OPTION LISTS, which are a different thing: they are derived from the whole
 * table rather than from a filter, so they are identical for every viewer, and
 * they change only when an event name, a source or a subject type appears for
 * the first time. Caching them is what `UserIndexAction` already does for its
 * counts, with the same TTL-and-bust shape.
 */
class AuditIndexAction
{
    /**
     * How long a resolved option list stays readable before it is recomputed.
     *
     * A safety net, not the freshness policy — `Auditable::audit()` and
     * `auditBulk()` bust the keys on every write, so a newly-written event name
     * appears in the dropdown on the next page load. This only bounds how long a
     * MISSED invalidation persists: without it the lists are
     * `rememberForever`, so one forgotten write would leave a stale option for
     * ever with the deploy green.
     *
     * ponytail: one hour is long enough that a busy day never pays to recompute,
     * and short enough that a missed bust cannot have shipped unnoticed.
     */
    private const TTL = 3600;

    /**
     * The option-list cache keys.
     *
     * Public for the same reason `UserIndexAction::cacheKeys()` is: four private
     * strings in four files are four unrelated literals, and renaming one of them
     * left the invalidators flushing keys nothing read.
     *
     * @return array<int, string>
     */
    public static function cacheKeys(): array
    {
        return [
            'audit_index_options.events',
            'audit_index_options.sources',
            'audit_index_options.subject_types',
            'audit_index_options.causers',
        ];
    }

    /**
     * Forget the option lists once the write that changed them is durable.
     *
     * Deferred to `DB::afterCommit` for the reason `UserIndexAction::bustCache()`
     * documents: a forget fired inside the transaction lets the next reader
     * repopulate from rows the database has not promised yet, and a rollback
     * cannot take that value back.
     */
    public static function bustCache(): void
    {
        DB::afterCommit(function (): void {
            foreach (static::cacheKeys() as $key) {
                Cache::forget($key);
            }
        });
    }
    /**
     * A paginated, filtered page of audit rows.
     *
     * @param  array<string, mixed>  $filters  as ActivityQueryRequest::filters()
     */
    public function run(array $filters): LengthAwarePaginator
    {
        $sort = $filters['sort'] ?? 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return Activity::filtered($filters)
            ->orderBy($sort, $direction)
            // Stable pagination. Without it, rows sharing a created_at — which a
            // bulk write produces in one batch — can swap between two page
            // requests, and a row the operator already read goes missing from
            // the next page.
            ->orderBy('id', $direction)
            ->with(['causer', 'subject'])
            ->paginate((int) ($filters['per_page'] ?? 10))
            ->withQueryString();
    }

    /**
     * Every distinct event name in the table, for the filter dropdown.
     *
     * Reads the `event` column rather than the descriptions: a row written
     * before that column existed has a description and a NULL event, and
     * including it would put an option in the dropdown that matches nothing.
     *
     * @return array<int, string>
     */
    public function eventOptions(): array
    {
        return Cache::remember('audit_index_options.events', static::TTL, function (): array {
            return Activity::query()
                ->whereNotNull('event')
                ->where('event', '!=', '')
                ->distinct()
                ->orderBy('event')
                ->pluck('event')
                ->all();
        });
    }

    /**
     * Every distinct `source` present, as stored.
     *
     * `source` lives inside the JSON `properties` column — `Auditable::auditContext()`
     * derives it — so this is the one option list that is not a plain string
     * column, and it gets its own query rather than being folded into a shared
     * builder.
     *
     * ## `select()`, never `pluck()` with a JSON path
     *
     * `pluck('properties->source')` puts the literal string in the column list
     * without passing it through the grammar's JSON wrapper, so on SQLite the
     * driver returns a row with no such property and reading it is an
     * `Undefined property: stdClass::$properties->source`. `select()` DOES wrap it
     * — `SQLiteGrammar::wrapJsonSelector` emits `json_extract(...)` — so the
     * expression resolves on both MySQL 8 and the SQLite the suite runs on. The
     * alias is what makes the hydrated attribute readable either way.
     *
     * @return array<int, string>
     */
    public function sourceOptions(): array
    {
        return Cache::remember('audit_index_options.sources', static::TTL, function (): array {
            return Activity::query()
                ->whereNotNull('properties')
                ->select('properties->source as source')
                ->distinct()
                ->get()
                ->pluck('source')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();
        });
    }

    /**
     * Every distinct subject type present, as `stored value => label`.
     *
     * The types that EXIST, not a fixed catalogue: a row whose type the
     * application does not recognise still appears in the filter, because an
     * operator chasing an unexplained row needs to be able to list the others.
     *
     * Keyed by the stored value and labelled through `Activity::labelForType()`
     * rather than labelled here, because the filter posts the KEY back and the
     * action compares it to `subject_type` verbatim. A prettified value would be
     * a filter that returns nothing.
     *
     * @return array<string, string>
     */
    public function subjectTypeOptions(): array
    {
        return Cache::remember('audit_index_options.subject_types', static::TTL, function (): array {
            $types = Activity::query()
                ->whereNotNull('subject_type')
                ->where('subject_type', '!=', '')
                ->distinct()
                ->orderBy('subject_type')
                ->pluck('subject_type')
                ->all();

            $options = [];

            foreach ($types as $type) {
                $options[$type] = Activity::labelForType($type);
            }

            return $options;
        });
    }

    /**
     * Actors who have written at least one row, as `id => name`.
     *
     * Built from the distinct causers IN THE AUDIT TABLE rather than from
     * `User::orderBy('name')`: on an installation with thousands of users who
     * never touched anything that is a dropdown nobody can read and an unbounded
     * result set. `causer_type` is compared against the class rather than
     * assumed, because a job row carries a null causer and a hand-edited row
     * could carry something else entirely.
     *
     * @return array<int|string, string>
     */
    public function causerOptions(): array
    {
        return Cache::remember('audit_index_options.causers', static::TTL, function (): array {
            return Activity::query()
                ->whereNotNull('causer_id')
                ->where('causer_type', User::class)
                ->join('users', 'users.id', '=', 'activity_log.causer_id')
                ->distinct()
                ->orderBy('users.name')
                ->pluck('users.name', 'users.id')
                ->all();
        });
    }
}

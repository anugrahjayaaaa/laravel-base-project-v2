<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\Audit\AuditIndexAction;
use App\Actions\V1\Audit\AuditShowAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Audit\ActivityQueryRequest;
use Illuminate\Contracts\View\View;

/**
 * The read-only audit trail viewer (Phase 10 Group A + C).
 *
 * ## No audit call in either method
 *
 * A read writes nothing — `docs/base/features/audit-trail.md` is explicit that
 * `RoleIndexAction` is the reference. The audit row for "an administrator looked
 * at the audit trail" would also be a row in the table being looked at, which is
 * how a log fills with its own readers.
 *
 * ## What the controller supplies, and what the row supplies
 *
 * The four filter-option lists are built here rather than in Blade, because
 * inside Blade they are either hand-typed arrays that go stale the first time an
 * action adds an event (`ui-architecture.md` rule 1), or `distinct` queries
 * executed per row.
 *
 * Everything derived from ONE row — its badges, its actor label, its flattened
 * properties — stays ON THE MODEL and is read in the view as
 * `$activity->causerLabel()`. That is the house pattern;
 * `pages/users/index.blade.php` reads `$user->getStatus()` the same way. Routing
 * those through the controller would add a hop and a second place to keep in
 * sync for no gain.
 *
 * The lists are passed alongside the paginator rather than through a view
 * composer because exactly one view reads them, and a composer registered on
 * this page would run on every render of it.
 */
class ActivityLogController extends Controller
{
    /**
     * The audit list.
     *
     * `ActivityQueryRequest` answers validation and normalisation only —
     * authorization is the route's (`->can('audit.view')` inside the
     * `feature:activity_logs` group in `routes/web.php`).
     */
    public function index(ActivityQueryRequest $request, AuditIndexAction $indexAction): View
    {
        $filters = $request->filters();

        return view('pages.activity-logs.index', [
            'activities' => $indexAction->run($filters),
            // The filters the view echoes back into the form. The paginator's
            // `withQueryString()` already carries them into the page links; this
            // is the same set for the control VALUES.
            'filters' => $filters,
            // Computed here rather than as a chain of `||` in the view. The index
            // page needs it twice — once for the Reset button in the filter
            // partial, once to choose between "no records yet" and "no records
            // match these filters" — and those two answers drifting apart is a
            // page offering to reset a filter it simultaneously claims none was
            // applied.
            'hasFilters' => self::hasActiveFilters($filters),
            'eventOptions' => $indexAction->eventOptions(),
            'sourceOptions' => $indexAction->sourceOptions(),
            'subjectTypeOptions' => $indexAction->subjectTypeOptions(),
            'causerOptions' => $indexAction->causerOptions(),
        ]);
    }

    /**
     * Is any narrowing filter applied?
     *
     * `sort` and `direction` are excluded on purpose: they reorder the list
     * rather than narrow it, so a page reached with only `sort=created_at` is not
     * "filtered" and should not offer a Reset that changes nothing.
     *
     * @param  array<string, mixed>  $filters
     */
    private static function hasActiveFilters(array $filters): bool
    {
        foreach (['search', 'event', 'causer_id', 'subject_type', 'source', 'date_from', 'date_to'] as $key) {
            if (($filters[$key] ?? null) !== null && $filters[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * One audit row.
     *
     * `AuditShowAction::run()` throws `ModelNotFoundException`, which Laravel
     * renders as 404 — the right answer for an id that is not there, and the same
     * answer whether it never existed or has since been pruned.
     */
    public function show(AuditShowAction $showAction, string $activity): View
    {
        return view('pages.activity-logs.show', [
            'activity' => $showAction->run($activity),
        ]);
    }
}

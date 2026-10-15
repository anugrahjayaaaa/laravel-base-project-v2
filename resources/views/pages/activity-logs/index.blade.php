{{--
    The audit trail list (Phase 10, P10-A2).

    Contract with ActivityLogController::index() — every value is read from the
    controller, none is computed here, and nothing queries (`ui-architecture.md`
    rule 1):

      $activities    paginator of App\Models\Activity, newest first
      $filters       validated filters, for the sort state
      ... plus the four option lists, which the filter partial consumes

    ## Read-only, and that is a structural property, not a styling choice

    No checkbox column and no bulk bar, unlike `pages/users/index`. The design
    system says so directly ("a list of read-only rows ... gets no checkbox
    column — a selection affordance with nothing to select against is dead UI"),
    and there is nothing to select: `App\Models\Activity` is `$guarded = ['*']`
    and no write route exists.

    The one control that leaves this page is the Export form, and it writes no
    audit row — it writes an `audit_exports` row, which is a hand-off to the queue.
    The audit table itself stays append-only.

    ## One way into the detail page, not two

    The event badge was the row's link, and the Actions column a second link to
    the same URL. Two affordance for one destination is the clutter
    `design-system.md` §Table Actions Column exists to avoid, so the badge is a
    plain badge and the Actions column owns the navigation — which is also where
    an operator looks for "show me more about this row" on every other list in
    the application.

    ## `When` sits after `Source`, not first

    Ordering by what happened, not when: Event, Actor, Target and Source are the
    four columns that answer "what", and `created_at` is a property of the row
    rather than part of the event. Leading with a timestamp puts the least
    discriminating value in the position the eye reads first — every row looks
    the same there.

    ## Zero queries from this file

    Labels, badges and colours all come off the model (`eventBadgeVariant()`,
    `sourceBadgeVariant()`, `causerLabel()`, `subjectLabel()`). A `match()` in
    Blade mapping event names to colours is exactly the presentation logic the
    house rule puts beside `label()` on the model, and it is the kind of thing
    that ends up duplicated in the detail page and the export.
--}}
@extends('layouts.app', ['title' => 'Activity Logs'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">Activity Logs</h1>
                <p class="page-description text-muted fs-7 mb-0">
                    Read-only record of every change made through the application.
                </p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Activity Logs</li>
            </ol>
        </div>
    </div>

    {{-- The read-only notice, stated once, at the top. The audit trail is the one
         page where an operator may legitimately expect to be able to "fix"
         something, and the absence of any control is the surprising part — so it
         is explained rather than left to be discovered as a missing button. --}}
    <div class="alert alert-info d-flex align-items-center gap-2 mb-3" role="status">
        <i class="fas fa-lock fs-7"></i>
        <span class="fs-7">
            Audit records are append-only. Nothing on this page can be edited or deleted,
            including by an administrator.
        </span>
    </div>

    @include('layouts.partials.alerts')

    {{-- The success flash after requesting an export. Its own block rather than
         a shared partial: `layouts.partials.alerts` renders `$errors` alone, and
         a form page already renders @error beside the field, so a layout-level
         banner would print the same message twice. The same six lines
         `pages/users/index` carries — the house position for "an action on this
         index page succeeded". Without it, clicking Export reloaded the page and
         said nothing, which reads as a broken button rather than as a queued job. --}}
    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
            <i class="fas fa-circle-check me-1"></i>
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        {{-- The card-header toolbar, the same shape `pages/users/index` uses for
             its Create button: a `justify-content-between` row that gives the
             page action somewhere to sit. Without it the export form floated on
             its own line under the filter bar, right-aligned against nothing —
             the button read as an orphan rather than as the page's action.

             The left side carries the one fact an operator needs BEFORE clicking:
             the file does not exist yet. The success flash after the click says
             it too, but only for the page load that follows the click. --}}
        <div class="card-header index-card-header bg-transparent border-bottom py-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <p class="text-muted fs-7 mb-0">
                    Exports are generated in the background — you will be notified when the file is ready.
                </p>

                {{-- Renders only for holders of `audit.export`, and carries the
                     filters below into its own POST. The one control on this page
                     that leaves the application; everything else is a read. --}}
                @include('pages.activity-logs._export')
            </div>
        </div>

        <div class="card-body p-4">
            @include('pages.activity-logs._filters')

            @if ($activities->isEmpty())
                {{-- Two different empty states, because "nothing has happened yet" and
                     "your filter matched nothing" are different facts and the operator
                     needs to know which one they are looking at. --}}
                <x-ui.empty-state
                    :icon="$hasFilters ? 'fas fa-filter-circle-xmark' : 'fas fa-clock-rotate-left'"
                    :message="$hasFilters ? 'No audit records match these filters.' : 'No audit records yet.'" />
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <colgroup>
                            <col style="width: 5%">
                            <col style="width: 22%">
                            <col style="width: 17%">
                            <col style="width: 21%">
                            <col style="width: 10%">
                            <col style="width: 16%">
                            <col style="width: 9%">
                        </colgroup>
                        <thead>
                            <tr>
                                <x-ui.sortable-th field="id" label="#" :current-sort="$filters['sort']"
                                    :current-direction="$filters['direction']" />
                                <th scope="col" class="align-middle">Event</th>
                                <th scope="col" class="align-middle">Actor</th>
                                <th scope="col" class="align-middle">Target</th>
                                <th scope="col" class="align-middle">Source</th>
                                <x-ui.sortable-th field="created_at" label="When" :current-sort="$filters['sort']"
                                    :current-direction="$filters['direction']" />
                                <th scope="col" class="align-middle text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($activities as $row)
                                <tr>
                                    <td class="text-muted">{{ ($activities->currentPage() - 1) * $activities->perPage() + $loop->iteration }}</td>
                                    <td>
                                        <x-ui.badge :variant="$row->eventBadgeVariant()" :text="$row->eventName()" />
                                    </td>
                                    <td class="fs-7">
                                        {{-- "System" and "Anonymous" are different facts and the
                                             model distinguishes them: a job row has no causer at
                                             all, while a hand-authored row is marked anonymous. --}}
                                        @if ($row->isSystemGenerated())
                                            <span class="text-muted"><i class="fas fa-gear me-1"></i>{{ $row->causerLabel() }}</span>
                                        @else
                                            {{ $row->causerLabel() }}
                                        @endif
                                    </td>
                                    <td class="fs-7">
                                        {{ $row->subjectLabel() }}
                                        <span class="text-muted d-block">{{ $row->typeLabel() }}</span>
                                    </td>
                                    <td>
                                        <x-ui.badge :variant="$row->sourceBadgeVariant()" :text="$row->source()" />
                                    </td>
                                    {{-- The full timestamp, not `diffForHumans()`. An audit log
                                         is read while reconstructing a sequence of events, and
                                         "2 hours ago" cannot be placed against another row's
                                         "3 hours ago" when the operator needs the ORDER the
                                         changes actually happened in — which is the one thing
                                         the table exists to answer. Seconds included: two
                                         rows written inside one bulk write share a minute,
                                         and the tiebreaker is only visible at second
                                         resolution. --}}
                                    <td class="text-muted fs-7 text-nowrap">
                                        {{ $row->created_at->format('Y-m-d H:i:s') }}
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center justify-content-end gap-1 flex-wrap flex-md-nowrap">
                                            {{-- Icon-only, with the label living in the tooltip.
                                                 `data-bs-title` rather than a bare `title=`
                                                 because Bootstrap 5.3 takes `title` only as a
                                                 FALLBACK and strips it once the tooltip is
                                                 constructed; the explicit attribute is the one the
                                                 driver reads. `aria-label` stays, and it names
                                                 WHICH record — the visible button is identical
                                                 across twenty rows, so without it a screen-reader
                                                 user hears the same thing twenty times.

                                                 `btn-outline-secondary`, deliberately NOT solid: a
                                                 filled primary button repeated down the column
                                                 makes the table shout at a control an operator
                                                 uses occasionally, and it competes with the event
                                                 badge sitting in the same row. --}}
                                            <a href="{{ route('activity-logs.show', $row->id) }}"
                                               class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center justify-content-center"
                                               style="width: 2rem; height: 2rem; padding: 0;"
                                               data-bs-toggle="tooltip" data-bs-placement="top"
                                               data-bs-title="Detail"
                                               aria-label="View full audit record for {{ $row->eventName() }}">
                                                <i class="fas fa-magnifying-glass"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Pagination: the shared convention, inside card-body after
                     .table-responsive (design-system.md §Pagination). --}}
                <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                    @if ($activities->total() > 0)
                        <small class="text-muted">
                            Showing {{ $activities->firstItem() }} to {{ $activities->lastItem() }} of {{ $activities->total() }} entries
                        </small>
                    @endif
                    <div class="d-flex">
                        {{ $activities->links() }}
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- The viewer's own exports, below the log they came from. --}}
    @include('pages.activity-logs._exports')

    {{-- The live region the poller below writes into, and WHY it cannot live
         inside the partial it announces.

         `#audit-exports` is replaced wholesale on every swap, so anything inside it
         is a different element five seconds later — and `aria-live` only announces
         CHANGES to a node that was already in the accessibility tree. A live
         region added to `_exports.blade.php` would be destroyed and recreated on
         the first poll, which announces nothing: the node the screen reader was
         watching is gone, and a fresh node whose content arrived with it is read
         only if focus happens to land on it.

         So the region lives HERE, outside the swapped subtree, and the poller
         writes a sentence into it on the transition. `visually-hidden` rather than
         `d-none`: an element with `display:none` is removed from the accessibility
         tree entirely and cannot be a live region at all.

         `polite`, not `assertive`: a resolved export is information, not an
         emergency, and `assertive` would interrupt whatever the operator was
         actually reading.

         The detail page includes the same partial and needs none of this — nothing
         on that page changes without a navigation. --}}
    <span id="audit-exports-live" class="visually-hidden" role="status" aria-live="polite"></span>

    @push('scripts')
        <script @nonce>
            {{-- The Detail control is icon-only, so its tooltip IS its label and
                 nothing appears on hover without this. Bootstrap 5.3 does not
                 auto-initialise: `data-bs-toggle` is only the marker this matches.

                 The same two lines as `pages/notifications/inbox`,
                 `pages/notifications/index` and `pages/settings/index` — a fourth
                 copy of a snippet, not a shared helper. Consolidated properly it
                 belongs in the layout, and that would re-initialise the three
                 pages carrying their own copy, which is the double-init that leaves
                 a tooltip firing twice. Four copies is cheaper than a shared change
                 with that blast radius. --}}
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                new bootstrap.Tooltip(el);
            });
        </script>
    @endpush

    @push('scripts')
        <script @nonce>
            {{-- Keeps "Your exports" current while a row is still being generated.

                 ## Why polling and not a push channel

                 The audit page is rendered once per request, so a `pending` row
                 reads `Preparing` until somebody reloads — while the notification
                 that the job finished has already moved the bell. The operator
                 gets the signal and then has to reload to act on it. Reverb would
                 close the whole page live, and it was declined: a Composer package,
                 two npm dependencies, a second long-running daemon, and a new
                 silent failure mode (a dead socket reads as a working page) — all
                 to make one table refresh before the next manual reload. The
                 polling shape below is the one `layouts/partials/
                 notification-poll.blade.php` already proves in this codebase.

                 ## It stops. That is the difference from the bell.

                 The bell polls for the life of the page because a notification can
                 arrive at any moment. This one only has work to do while a row is
                 `pending`, and the loop sets its own interval to null the moment
                 the last one resolves. An export table that keeps polling 24 hours
                 a day would be the load generator the bell's back-off comment warns
                 about.

                 ## One request per swap, not one per change

                 The endpoint returns the re-rendered partial rather than a JSON
                 summary, so there is ONE renderer of this table — the same Blade
                 the page used. A status→DOM map written in JavaScript would be a
                 second copy of what `_exports.blade.php` already decides, and the
                 two would disagree the first time a badge colour moved.

                 Failure is silent and backs off for five minutes, matching the
                 bell: a poll that keeps retrying a throttled or unreachable
                 endpoint turns a cosmetic table into load.

                 ## It also nudges the bell, once

                 Resolving a row means the job just notified the requester. The bell
                 polls on its own 30-second cadence, which is right for an ambient
                 badge and wrong for an operator watching the page that caused the
                 notification — so the moment this loop sees a row leave `pending`,
                 it dispatches `notifications:refresh` and the bell checks once,
                 immediately. Firing on the TRANSITION, not on every swap, is what
                 keeps it to one request per export.
            --}}
            (function () {
                var container = document.getElementById('audit-exports');

                if (!container) {
                    return;
                }

                var url = '{{ route('activity-logs.exports.recent') }}';
                var interval = 5000;
                var timer = null;
                var backoffUntil = 0;

                // The loop condition, read off the DOM rather than tracked in a
                // variable: after a swap the markup is the truth, and a boolean
                // kept alongside it is one more thing that can disagree.
                function workInFlight() {
                    return container.querySelector('[data-status="pending"]') !== null;
                }

                function poll() {
                    if (document.hidden || Date.now() < backoffUntil) {
                        return;
                    }

                    fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin'
                    })
                        .then(function (response) {
                            return response.ok ? response.text() : Promise.reject(response);
                        })
                        .then(function (html) {
                            // Read BEFORE the swap: "did this poll resolve work"
                            // is a question about the two states either side of it,
                            // and asking afterwards answers it too late.
                            var wasInFlight = workInFlight();

                            // Re-resolve: the swapped node is a different element,
                            // and every later poll has to ask the new one.
                            container.outerHTML = html;
                            container = document.getElementById('audit-exports');

                            if (!container || !workInFlight()) {
                                window.clearInterval(timer);
                                timer = null;
                            }

                            // A row just resolved, which means the job just notified
                            // the requester. The bell polls on its own 30-second
                            // cadence, and the operator is watching THIS page — so ask
                            // it to check now instead of making them wait out the
                            // interval for a notification this page already knows
                            // about. One request, fired only on the transition, not
                            // on every swap.
                            if (wasInFlight) {
                                announce();
                                window.dispatchEvent(new CustomEvent('notifications:refresh'));
                            }
                        })
                        .catch(function () {
                            backoffUntil = Date.now() + 300000;
                        });
                }

                // The sentence the screen reader hears.
                //
                // Counts the STATUS the Blade just rendered rather than mapping
                // states to sentences in JavaScript — that map would be a second
                // copy of what `_exports.blade.php` already decides, and the two
                // would disagree the first time a state was added. Reading
                // `data-status` is the same trick `workInFlight()` uses, and it
                // cannot drift for the same reason.
                //
                // Only ever called on a transition, never per poll: a live region
                // that re-announces the same table every five seconds is noise,
                // and noise in a live region is worse than silence.
                function announce() {
                    var live = document.getElementById('audit-exports-live');

                    if (!live) {
                        return;
                    }

                    var failed = container.querySelectorAll('[data-status="failed"]').length;

                    live.textContent = failed > 0
                        ? failed + ' of your exports failed. Contact an administrator.'
                        : 'An export is ready to download.';
                }

                if (workInFlight()) {
                    timer = window.setInterval(poll, interval);
                }

                // Coming back to the tab is also the case where a row resolved
                // while it was hidden — the first visible paint would otherwise
                // still say `Preparing`.
                document.addEventListener('visibilitychange', function () {
                    if (!document.hidden && timer !== null) {
                        poll();
                    }
                });
            })();
        </script>
    @endpush
@endsection
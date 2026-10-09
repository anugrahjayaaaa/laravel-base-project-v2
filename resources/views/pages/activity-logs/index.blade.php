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

    <div class="card border-0 shadow-sm mb-4">
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

    @push('scripts')
        <script>
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
@endsection
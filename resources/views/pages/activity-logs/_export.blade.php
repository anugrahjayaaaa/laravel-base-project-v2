{{--
    The export form (Phase 10, P10-A5).

    Lives in the card-header toolbar beside `pages/users/index`'s Create button —
    the house position for a page action on an index. Rendered INSIDE the
    `justify-content-between` row rather than bringing its own: a second flex row
    here is the orphan line this placement replaced.

    Contract with ActivityLogController::index() — the two values read are the
    `$filters` array the GET form below echoes back and `$auditExportRateLimit`,
    the resolved export ceiling the tooltip quotes.

    ## Its own POST form, not the filter form with a changed method

    The filter bar is a GET so a filtered URL is shareable and survives a reload.
    Making THAT form post would lose the URL, lose the reload, and turn every
    keystroke in the search box into a queued export. Two forms, one filter array.

    ## The current filters travel as hidden inputs

    So the file is "the list you are looking at" rather than "everything". The key
    list is the request's, which is the same list `ActivityQueryRequest::rules()`
    validates and `AuditExportRequest` inherits — the loop cannot submit a field
    the export request would reject.

    Empty values are omitted rather than sent as empty strings: `search=""` and an
    absent `search` validate the same, but a form full of empty fields is a form
    that renders a POST body nobody can read.

    ## `@can`, not a disabled button

    `audit.export` is a different permission from `audit.view`, and an operator who
    may read the trail may not take it out of the application. A disabled button
    with no explanation is a control that advertises an action this account does not
    have; hiding it is the same answer the sidebar gives.

    ## `btn-outline-secondary`, and why not primary

    The filter bar's Filter button is already `btn-primary` on this page. A second
    solid primary in the same viewport is two primary actions, and the filter is
    the one an operator uses to READ. The export is an occasional action that costs
    a queue job and writes a file, which is exactly what §8 Button Hierarchy calls
    secondary. It matches the Reset button's weight, which is the honest signal:
    both are refinements of the list, not new work.

    ## The tooltip names the limiter

    `data-bs-title` rather than a bare `title=` — Bootstrap 5.3 takes `title` only
    as a FALLBACK and strips it once the tooltip is constructed. The text quotes
    the `audit-export` limiter's resolved ceiling (`$auditExportRateLimit`, read by
    `ActivityLogController::index()` from the same `system_settings` row the
    limiter reads), because a control with an invisible ceiling is one an operator
    discovers by being refused. It was a literal here before the limit moved into
    settings (§11 P10-E9), and a hardcoded label beside a configurable number is a
    label that lies the first time somebody edits the setting. No `aria-label`:
    the button has visible text, so labelling it again would override what a
    screen reader reads with a shorter phrase.

    The tooltip is initialised by the page's existing `[data-bs-toggle="tooltip"]`
    sweep in `@push('scripts')` — this partial adds no JS.
--}}
@can('audit.export')
    <form method="POST" action="{{ route('activity-logs.exports.store') }}">
        @csrf
        @foreach (['search', 'event', 'source', 'causer_id', 'subject_type', 'date_from', 'date_to', 'sort', 'direction'] as $key)
            @if (filled($filters[$key] ?? null))
                <input type="hidden" name="{{ $key }}" value="{{ $filters[$key] }}">
            @endif
        @endforeach
        {{-- The one format the export writes. Sent explicitly rather than defaulted
             in the Request so the row's own record of what was asked for is visible
             in the POST body during a pentest. --}}
        <input type="hidden" name="format" value="csv">

        <button type="submit"
            class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2"
            data-bs-toggle="tooltip" data-bs-placement="top"
            data-bs-title="Exports the rows matched by the current filters as CSV. Maximum {{ $auditExportRateLimit }} per hour.">
            <i class="fas fa-file-arrow-down"></i> Export CSV
        </button>
    </form>
@endcan

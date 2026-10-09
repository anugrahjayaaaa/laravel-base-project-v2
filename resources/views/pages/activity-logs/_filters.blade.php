{{--
    The audit viewer's filter bar (Phase 10, P10-A4).

    Contract with ActivityLogController::index() — every value below is read, none
    is computed, and nothing here queries:

      $filters             validated filters, for the control VALUES
      $eventOptions        distinct event names in the table
      $sourceOptions       distinct `source` values in the table
      $subjectTypeOptions  distinct subject types, as stored
      $causerOptions        id => name, for actors who have written a row

    ## Why an @include and not a Blade component

    A component would need `@props` for five arrays and would then be a variant
    that exists for one caller. `@include` shares the parent's scope, so the
    partial is a bare variable read with no prop ceremony — the same choice the
    notifications inbox made for its alerts.

    ## No `input-group` anywhere on this form

    Bootstrap shows a validation message through `.is-invalid ~ .invalid-feedback`,
    a SIBLING selector. Wrapping an input in `.input-group` makes the feedback a
    sibling of the GROUP rather than of the input, the rule never matches, and a
    rejected filter paints a red border and shows nothing. `pages/settings/index`
    shipped 18 of 22 messages in exactly that invisible state before Phase 8 fixed
    it. These controls are a GET form with no server-side error messages, so the
    simplest correct answer is the plain `.form-control` with no group wrapper.

    ## Every control submits via GET and echoes its current value

    The list is a GET form so a filtered URL is shareable and survives a reload —
    which is the point of a log an operator is reading during an incident. Sort
    and direction ride as hidden fields so changing a filter does not silently
    discard the ordering the operator picked.
--}}
<form method="GET" action="{{ route('activity-logs.index') }}" class="d-flex align-items-end gap-2 mb-3 flex-wrap">
    {{-- Sort/direction travel with every submission, so narrowing a filter keeps
         the ordering the reader chose instead of snapping back to the default. --}}
    <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
    <input type="hidden" name="direction" value="{{ $filters['direction'] }}">

    <div class="d-flex flex-column gap-1">
        <label for="filter-search" class="form-label fs-7 text-muted mb-0">Search</label>
        <input type="text" id="filter-search" name="search" class="form-control form-control-sm"
            style="max-width: 240px" placeholder="Event or description..." value="{{ $filters['search'] }}">
    </div>

    <div class="d-flex flex-column gap-1">
        <label for="filter-event" class="form-label fs-7 text-muted mb-0">Event</label>
        <select id="filter-event" name="event" class="form-select form-select-sm" style="max-width: 220px">
            <option value="">All events</option>
            @foreach ($eventOptions as $eventOption)
                <option value="{{ $eventOption }}" @selected($filters['event'] === $eventOption)>
                    {{ $eventOption }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="d-flex flex-column gap-1">
        <label for="filter-actor" class="form-label fs-7 text-muted mb-0">Actor</label>
        <select id="filter-actor" name="causer_id" class="form-select form-select-sm" style="max-width: 200px">
            <option value="">All actors</option>
            @foreach ($causerOptions as $causerId => $causerName)
                <option value="{{ $causerId }}" @selected((int) $filters['causer_id'] === (int) $causerId)>
                    {{ $causerName }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="d-flex flex-column gap-1">
        <label for="filter-subject" class="form-label fs-7 text-muted mb-0">Target type</label>
        <select id="filter-subject" name="subject_type" class="form-select form-select-sm" style="max-width: 180px">
            <option value="">All types</option>
            {{-- Keyed by the stored value and labelled by the controller through
                 Activity::labelForType(): the filter posts the KEY back and the
                 action compares it to `subject_type` verbatim, so a prettified
                 value here would be a filter that silently returns nothing. --}}
            @foreach ($subjectTypeOptions as $subjectType => $subjectTypeLabel)
                <option value="{{ $subjectType }}" @selected($filters['subject_type'] === $subjectType)>
                    {{ $subjectTypeLabel }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="d-flex flex-column gap-1">
        <label for="filter-source" class="form-label fs-7 text-muted mb-0">Source</label>
        <select id="filter-source" name="source" class="form-select form-select-sm" style="max-width: 140px">
            <option value="">All sources</option>
            @foreach ($sourceOptions as $sourceOption)
                <option value="{{ $sourceOption }}" @selected($filters['source'] === $sourceOption)>
                    {{ ucfirst($sourceOption) }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="d-flex flex-column gap-1">
        <label for="filter-from" class="form-label fs-7 text-muted mb-0">From</label>
        <input type="date" id="filter-from" name="date_from" class="form-control form-control-sm"
            style="max-width: 160px" value="{{ $filters['date_from'] }}">
    </div>

    <div class="d-flex flex-column gap-1">
        <label for="filter-to" class="form-label fs-7 text-muted mb-0">To</label>
        <input type="date" id="filter-to" name="date_to" class="form-control form-control-sm"
            style="max-width: 160px" value="{{ $filters['date_to'] }}">
    </div>

    <button type="submit" class="btn btn-primary btn-sm d-inline-flex align-items-center gap-2">
        <i class="fas fa-filter"></i> Filter
    </button>

    {{-- Only when a filter is actually narrowing the list. A "Reset" button on an
         unfiltered table is a control that does nothing, which is the same defect
         as a disabled button: it advertises an action that does not exist.
         `$hasFilters` is computed once in the controller because this page needs
         the same answer twice — here, and to pick the empty state. --}}
    @if ($hasFilters)
        <a href="{{ route('activity-logs.index') }}" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2">
            <i class="fas fa-rotate-left"></i> Reset
        </a>
    @endif
</form>
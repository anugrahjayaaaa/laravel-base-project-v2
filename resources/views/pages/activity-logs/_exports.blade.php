{{--
    The viewer's recent exports, with the download button (Phase 10, P10-A5/E).

    Contract with ActivityLogController::index() — the only value read is
    `$myExports`, a Collection of the VIEWER'S OWN `App\Models\AuditExport`, and
    nothing here queries.

    ## Why this exists at all

    The request button and the notification were both working, and neither of them
    put the file one click away: pressing Export produced a row and a notice, and
    the only route to the file was the notification — on another page, in a
    dropdown, or in mail. An operator who asked for a file and then wanted it
    again had to go looking. This is that answer, on the page that produced it.

    ## Only the download when there is one

    `isDownloadable()` is the model's single rule, so this partial cannot disagree
    with the download route about what is available. A `pending` row and an
    `expired` row render their status and no button, which is the honest state —
    a disabled download button advertises an action that answers 404 or 410.

    ## The download button is `btn-outline-primary`

    Not `btn-outline-secondary`: `theme.css` paints that variant `color:
    var(--lbp-muted)` on `border-color: var(--lbp-border)`, and in dark mode a
    muted-on-muted outline reads as a disabled control rather than as the one
    thing this row can do. `-primary` holds contrast in both themes, and an
    OUTLINE keeps the data the loudest thing in the table — a solid primary per
    row would make five buttons compete with the audit log.

    ## The status is a word, not only a colour

    `design-system.md` §Accessibility: the badge is not the message. `Preparing`
    and `Failed` are readable without colour, and `Failed` is the state that has
    to be legible at a glance — a spinner that resolved into an error nobody saw
    is the failure this whole feature exists to avoid.

    ## The wrapper exists so the poller has something to replace

    `id="audit-exports"` is rendered ALWAYS, including when the viewer has no
    exports at all — an empty, unstyled `<div>`, so the card heading stays absent
    (`test_another_operators_export_is_never_listed` asserts that) while the
    element the poller re-finds after a swap still exists. The card itself is
    still conditional; only the box is not.

    `data-status` on each row is the poller's "is there work in flight" test. The
    enum VALUE is the attribute, not the label: `Preparing` is a word that could be
    reworded, `pending` is the state.

    The poller lives in `pages/activity-logs/index`, not in this file — a script
    inside the swapped subtree is removed with it, so the second poll would never
    run. Same reason `layouts/partials/notification-poll.blade.php` sits outside
    the fragment it replaces.
--}}
<div id="audit-exports">
@if ($myExports->isNotEmpty())
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent border-bottom py-3">
            <h5 class="card-title mb-0 fw-semibold">Your exports</h5>
        </div>
        {{-- `p-4`, always: design-system.md pins it on every card body, and an
             edge-to-edge variant here is one more card nobody maintains. The
             `.table-responsive` inside absorbs the width that p-4 would waste. --}}
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <colgroup>
                        <col style="width: 14%">
                        <col style="width: 34%">
                        <col style="width: 20%">
                        <col style="width: 18%">
                        <col style="width: 14%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="align-middle">Requested</th>
                            <th scope="col" class="align-middle">Filters</th>
                            <th scope="col" class="align-middle">Rows</th>
                            <th scope="col" class="align-middle">Expires</th>
                            <th scope="col" class="align-middle text-end">File</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($myExports as $export)
                            @php($status = $export->status)
                            <tr data-status="{{ $status->value }}">
                                <td class="align-middle text-nowrap">
                                    {{ $export->created_at->format('Y-m-d H:i') }}
                                    <span class="d-block fs-7">
                                        <span
                                            class="badge {{ $status->badgeClass() }}">{{ $status->label() }}</span>
                                    </span>
                                </td>
                                <td class="align-middle fs-7">
                                    {{-- The filters, not the ids: "event=user.updated,
                                         2026-10-01 → 2026-10-10" is what the operator
                                         needs to recognise which file this is. A row
                                         with no filters says so rather than rendering
                                         an empty cell.

                                         `$filterKeys` is the SAME list
                                         `hasActiveFilters()` uses, handed in by the
                                         controller — this view reads what it was
                                         given and never reaches for the list itself.
                                         Two copies of the key set drifted, and the
                                         card answered "filtered" differently from the
                                         Reset button above it. --}}
                                    @php($applied = array_filter(
                                        array_intersect_key($export->filters, array_flip($filterKeys)),
                                        fn ($value): bool => $value !== null && $value !== '',
                                    ))
                                    @if ($applied === [])
                                        <span class="text-muted">Everything</span>
                                    @else
                                        {{ collect($applied)->map(fn ($value, $key): string => $key.'='.(is_scalar($value) ? $value : json_encode($value)))->implode(', ') }}
                                    @endif
                                </td>
                                <td class="align-middle text-muted">
                                    {{ $export->row_count === null ? '—' : number_format($export->row_count) }}
                                </td>
                                <td class="align-middle text-muted fs-7 text-nowrap">
                                    {{ $export->expires_at->format('Y-m-d H:i') }}
                                    @if ($export->isExpired())
                                        <span class="d-block">Expired</span>
                                    @endif
                                </td>
                                <td class="align-middle text-end">
                                    @if ($export->isDownloadable())
                                        <a href="{{ route('activity-logs.exports.download', $export) }}"
                                           class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-2">
                                            <i class="fas fa-file-arrow-down"></i> Download
                                        </a>
                                    @else
                                        {{-- A disabled button would advertise an action
                                             that answers 404 or 410. The status column
                                             already says why there is none. --}}
                                        <span class="text-muted fs-7">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
</div>

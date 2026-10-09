{{--
    One audit record, in full (Phase 10, P10-A3).

    Contract with ActivityLogController::show():

      $activity       App\Models\Activity, causer + subject eager loaded

    Labels, badges and flattened properties are read off the record itself —
    `$activity->causerLabel()`, `$activity->detailProperties()` — rather than
    passed in pre-resolved. `pages/users/index.blade.php` reads `$user->getStatus()`
    the same way; a second hop through the controller would be a second place to
    keep in sync for no gain.

    ## There is no edit affordance on this page, and that is the requirement

    `docs/base/features/audit-trail.md:19` — "Audit records must be read-only
    through the UI" — and DEP-003:73, "the audit table is append-only". So: no
    form, no submit button, no `x-ui.confirm-action`, no delete. The only control
    is the back link in the content header. `AuditLogUiRenderTest` asserts the
    absence, because an audit viewer that grew an edit button would be a
    catastrophic regression that renders perfectly and raises nothing.

    ## Why nothing here is re-derived

    The view could recompute anything it displays. It does not, because this page
    IS the record — a detail view that recalculates the context it is showing is a
    view whose answer cannot be trusted when the derivation and the stored value
    disagree. Every label below is read off the row as stored.

    ## Properties are escaped, never dumped

    `properties` carries caller-supplied data, and a caller is a human being who
    chose a username — so a row can contain anything the application will accept
    as a name. `displayProperties()` returns flattened strings and this page
    renders them through `{{ }}`, which escapes. There is no `{!! !!}` anywhere on
    this page, and that is deliberate rather than incidental.
--}}
@extends('layouts.app', ['title' => 'Audit Record'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">Audit Record</h1>
                <p class="page-description text-muted fs-7 mb-0">
                    <a href="{{ route('activity-logs.index') }}" class="text-muted text-decoration-none">
                        <i class="fas fa-arrow-left me-1"></i>Back to Activity Logs
                    </a>
                </p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('activity-logs.index') }}">Activity Logs</a></li>
                <li class="breadcrumb-item active">#{{ $activity->id }}</li>
            </ol>
        </div>
    </div>

    {{-- The event as the page's subject line, not just a cell in a card: an
         operator who lands here from a search result needs to know what happened
         before they read anything else. --}}
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <x-ui.badge :variant="$activity->eventBadgeVariant()" :text="$activity->eventName()" />
        <span class="text-muted fs-7">
            {{ $activity->created_at->format('Y-m-d H:i:s') }}
            ({{ $activity->created_at->diffForHumans() }})
        </span>
    </div>

    <div class="row g-4">
        {{-- Left column: WHO and WHAT. --}}
        <div class="col-lg-7 col-12">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h5 class="card-title mb-0 fw-semibold">What happened</h5>
                </div>
                <div class="card-body p-4">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted fs-7 fw-normal">Event</dt>
                        <dd class="col-sm-8"><code>{{ $activity->eventName() }}</code></dd>

                        <dt class="col-sm-4 text-muted fs-7 fw-normal">Description</dt>
                        <dd class="col-sm-8">{{ $activity->description }}</dd>

                        <dt class="col-sm-4 text-muted fs-7 fw-normal">Actor</dt>
                        <dd class="col-sm-8">
                            {{ $activity->causerLabel() }}
                            @if ($activity->causer_id !== null)
                                <span class="text-muted d-block fs-7">User #{{ $activity->causer_id }}</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4 text-muted fs-7 fw-normal">Target</dt>
                        <dd class="col-sm-8">
                            {{ $activity->subjectLabel() }}
                            @if ($activity->subject_id !== null)
                                <span class="text-muted d-block fs-7">
                                    {{ $activity->typeLabel() }} #{{ $activity->subject_id }}
                                </span>
                            @endif
                        </dd>

                        <dt class="col-sm-4 text-muted fs-7 fw-normal">Recorded</dt>
                        <dd class="col-sm-8">{{ $activity->created_at->format('Y-m-d H:i:s') }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h5 class="card-title mb-0 fw-semibold">Recorded properties</h5>
                </div>
                <div class="card-body p-4">
                    @if ($activity->detailProperties() === [])
                        {{-- Stated rather than left blank: "no properties" reads as a
                             rendering failure, when it is the normal shape of most rows
                             (an unlock, a logout, a role assignment). --}}
                        <p class="text-muted mb-0 fs-7">
                            This event recorded no additional properties.
                        </p>
                    @else
                        <dl class="row mb-0">
                            @foreach ($activity->detailProperties() as $key => $value)
                                <dt class="col-sm-4 text-muted fs-7 fw-normal text-break">{{ $key }}</dt>
                                <dd class="col-sm-8 text-break">{{ $value }}</dd>
                            @endforeach
                        </dl>
                    @endif
                </div>
            </div>
        </div>

        {{-- Right column: WHERE it came from. --}}
        <div class="col-lg-5 col-12">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h5 class="card-title mb-0 fw-semibold">Request context</h5>
                </div>
                <div class="card-body p-4">
                    <dl class="row mb-0">
                        <dt class="col-sm-5 text-muted fs-7 fw-normal">Source</dt>
                        <dd class="col-sm-7">
                            <x-ui.badge :variant="$activity->sourceBadgeVariant()" :text="$activity->source()" />
                        </dd>

                        <dt class="col-sm-5 text-muted fs-7 fw-normal">IP address</dt>
                        <dd class="col-sm-7">
                            {{-- `extra()` and not the package's `getExtraProperty()`:
                                 that one calls `->toArray()` on a NULL `properties`
                                 column and 500s the page. A system job writes no IP,
                                 and a row written outside this application may carry no
                                 properties at all — so "none" must render, not fatal, on
                                 the page whose whole job is to report what happened. --}}
                            {{ $activity->extra('ip') ?? '—' }}
                        </dd>

                        <dt class="col-sm-5 text-muted fs-7 fw-normal">Request ID</dt>
                        <dd class="col-sm-7">
                            {{-- Em-dash until Phase 10 Group D adds `request_id` to
                                 `Auditable::auditContext()`. Rows written before then
                                 have no such property; showing the value empty is
                                 honest and self-correcting, whereas omitting the row
                                 would make the field appear and disappear as the table
                                 fills with new rows. --}}
                            {{ $activity->extra('request_id') ?? '—' }}
                        </dd>

                        <dt class="col-sm-5 text-muted fs-7 fw-normal">User agent</dt>
                        <dd class="col-sm-7 text-break fs-7">
                            {{ $activity->extra('user_agent') ?? '—' }}
                        </dd>

                        @if ($activity->batch_uuid !== null)
                            <dt class="col-sm-5 text-muted fs-7 fw-normal">Batch</dt>
                            {{-- One UUID for every row a single bulk action wrote, which is
                                 what makes "what else happened in this one request"
                                 answerable. --}}
                            <dd class="col-sm-7"><code class="fs-7">{{ $activity->batch_uuid }}</code></dd>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-shield-halved fs-7 text-muted mt-1"></i>
                        <p class="text-muted fs-7 mb-0">
                            Audit records cannot be edited or deleted from anywhere in the
                            application. If this record looks wrong, the change that produced it
                            is the thing to investigate.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
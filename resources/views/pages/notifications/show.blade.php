{{--
    One notification, opened (Phase 10, Group E follow-up).

    Contract with NotificationInboxController::show() — both values are read, none
    is computed here, and nothing queries:

      $notification  DatabaseNotification, already marked read by the action
      $callToAction  ['url' => path, 'label' => string] | null — named for the
                    same reason as $rowAction in the inbox: `action` is taken by the
                    confirmation modal in the layout scope.

    ## Why there is a page at all

    Both the bell and the inbox used to link every row at the inbox, so clicking a
    notification dropped the reader into a list of thirty rows with no indication
    of which one they clicked. This page is that one row — and opening it marks it
    read, so "I have seen this" stops needing a second click.

    ## The action button is the page's primary action

    `btn-primary`, not the `btn-outline-secondary` the inbox row carries: this
    page has exactly one thing to DO, and design-system.md §8 puts a page's one
    primary action on `btn-primary`. The inbox row cannot, because a table with a
    solid primary button per row shouts over the data.

    ## `btn-primary` is also the contrast answer

    `btn-outline-secondary` renders `color: var(--lbp-muted)` on
    `border-color: var(--lbp-border)` (`theme.css`), which in dark mode is a
    muted-on-muted button that reads as disabled. `btn-primary` sets both its
    background AND its ink from tokens, so it holds contrast in either theme —
    which is why every download affordance in this feature is primary-coloured
    rather than neutral.
--}}
@extends('layouts.app', ['title' => 'Notification'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">{{ $notification->data['subject'] ?? 'Notification' }}</h1>
                <p class="page-description text-muted fs-7 mb-0">
                    <a href="{{ route('notifications.inbox') }}" class="text-muted text-decoration-none">
                        <i class="fas fa-arrow-left me-1"></i>Back to Notifications
                    </a>
                </p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('notifications.inbox') }}">Notifications</a></li>
                <li class="breadcrumb-item active">Detail</li>
            </ol>
        </div>
    </div>

    @include('layouts.partials.alerts')

    <div class="row g-4">
        <div class="col-lg-8 col-12">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h5 class="card-title mb-0 fw-semibold">Message</h5>
                </div>
                <div class="card-body p-4">
                    {{-- The lines are plain text and escaped. `data` is a JSON column
                         its writer controls, so nothing here is treated as markup. --}}
                    @forelse ((array) ($notification->data['lines'] ?? []) as $line)
                        <p class="mb-2">{{ $line }}</p>
                    @empty
                        <p class="text-muted mb-0">This notification carries no further detail.</p>
                    @endforelse
                </div>
                @if ($callToAction !== null)
                    {{-- In `card-body`, not `card-footer`: the design system reserves
                         `card-footer` for form actions, and this is a navigation
                         control inside a read-only card. --}}
                    <div class="card-body p-4 pt-0">
                        <a href="{{ $callToAction['url'] }}"
                           class="btn btn-primary d-inline-flex align-items-center gap-2">
                            <i class="fas fa-file-arrow-down"></i> {{ $callToAction['label'] }}
                        </a>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-lg-4 col-12">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h5 class="card-title mb-0 fw-semibold">Details</h5>
                </div>
                <div class="card-body p-4">
                    <dl class="row mb-0">
                        <dt class="col-sm-5 text-muted fs-7 fw-normal">Received</dt>
                        <dd class="col-sm-7">
                            {{ $notification->created_at->format('Y-m-d H:i') }}
                            <span class="text-muted d-block fs-7">
                                {{ $notification->created_at->diffForHumans() }}
                            </span>
                        </dd>

                        {{-- The state as a WORD, not only a colour — the same rule the
                             inbox rows follow (design-system.md §Accessibility). --}}
                        <dt class="col-sm-5 text-muted fs-7 fw-normal">State</dt>
                        <dd class="col-sm-7">
                            @if ($notification->read_at === null)
                                <span class="badge bg-primary-subtle text-primary">Unread</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Read</span>
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
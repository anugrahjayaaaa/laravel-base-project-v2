{{--
    The viewer's own notification inbox (Phase 9 Group C, P9-C5).

    Contract with NotificationInboxController::index() (no queries, no route()
    logic here):
      $notifications   paginator of DatabaseNotification — paginated, newest first
      $unreadCount     int  a COUNT over ALL unread, not the page's length
      $canConfigure    bool whether to link to /notifications at all

    Rows are read through `$notification->data`, never a project-specific
    accessor: the inbox is generic over notification classes, and a view that
    switched on `$notification->type` to read a key each class happens to store
    would need editing every time a class is added — which is the moment nobody
    remembers this file.

    `data['subject']` and `data['lines']` are the shape `toArray()` on the
    notification classes produces (P9-C6). Both are read with a fallback, and
    `??` rather than a required key, because a row written by a class that
    predates this page must render as an unreadable placeholder rather than an
    undefined-variable fatal on someone's inbox.

    The unread count is passed in, not counted here: an inline
    `auth()->user()->unreadNotifications()->count()` in a view queries on every
    admin page, and this partial is inside `layouts.app`.
--}}
@extends('layouts.app', ['title' => 'Notifications'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">Notifications</h1>
                <p class="page-description text-muted fs-7 mb-0">
                    @if ($unreadCount > 0)
                        {{ $unreadCount }} unread.
                    @else
                        You are all caught up.
                    @endif
                </p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Notifications</li>
            </ol>
        </div>
    </div>

    <div class="d-flex justify-content-end align-items-center gap-2 mb-3 flex-wrap">
        {{-- Offered only to whoever can actually reach them. The inbox has no
             permission of its own, so a link here to a permission-gated page is
             a 403 waiting to be clicked. --}}
        @if ($canConfigure)
            <a href="{{ route('notifications.index') }}"
               class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2">
                <i class="fas fa-gear"></i> Notification Settings
            </a>
        @endif

        {{-- Hidden rather than disabled when there is nothing unread: a disabled
             button is the standard way to tell a user an action exists when it
             does not, and the header would still be the honest one. --}}
        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('notifications.inbox.read-all') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-2">
                    <i class="bi bi-check2-all"></i> Mark all as read
                </button>
            </form>
        @endif
    </div>

    <div class="card border-0 shadow-sm mb-4">
        {{-- `card-body p-4`, always. An edge-to-edge `p-0` was tried and reverted in
             Group A for the channels table: the design system pins p-4 on every
             card body, and one card that differs is a variant nobody maintains. --}}
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <colgroup>
                        <col style="width: 10%">
                        <col style="width: 60%">
                        <col style="width: 20%">
                        <col style="width: 10%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="align-middle">State</th>
                            <th scope="col" class="align-middle">Notification</th>
                            <th scope="col" class="align-middle">Received</th>
                            <th scope="col" class="align-middle text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($notifications as $notification)
                            <tr @class(['table-light' => $notification->read_at === null])>
                                <td class="align-middle">
                                    {{-- Colour is not the only signal (design system
                                         §Accessibility): the state is a word, so the row
                                         is readable without it. --}}
                                    @if ($notification->read_at === null)
                                        <span class="badge bg-primary-subtle text-primary">Unread</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary">Read</span>
                                    @endif
                                </td>
                                <td class="align-middle">
                                    <span class="fw-medium">{{ $notification->data['subject'] ?? 'Notification' }}</span>
                                    @foreach ((array) ($notification->data['lines'] ?? []) as $line)
                                        <div class="text-muted fs-7">{{ $line }}</div>
                                    @endforeach
                                </td>
                                <td class="align-middle text-muted fs-7">
                                    {{ $notification->created_at->diffForHumans() }}
                                </td>
                                <td class="align-middle text-center">
                                    @if ($notification->read_at === null)
                                        <form method="POST" action="{{ route('notifications.inbox.read', $notification->id) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
                                                <i class="bi bi-check-lg"></i> Mark read
                                            </button>
                                        </form>
                                    @else
                                        {{-- An already-read row has nothing to do, and says so
                                             rather than showing a button that would report
                                             "already read" as though the user had done something
                                             wrong. --}}
                                        <span class="text-muted fs-7">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x text-muted mb-2 d-block"></i>
                                    No notifications yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Pagination, the one footer every index page uses (design system). --}}
    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        @if ($notifications->total() > 0)
            <small class="text-muted">
                Showing {{ $notifications->firstItem() }} to {{ $notifications->lastItem() }} of {{ $notifications->total() }} notifications
            </small>
        @endif
        <div class="d-flex">
            {{ $notifications->links() }}
        </div>
    </div>
@endsection

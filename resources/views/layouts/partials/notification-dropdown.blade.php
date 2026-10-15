<li class="nav-item dropdown">
  <a class="nav-link text-secondary position-relative d-flex align-items-center"
     href="{{ route('notifications.inbox') }}"
     data-bs-toggle="dropdown"
     aria-expanded="false"
     title="Notifications"
     id="notification-bell"
     aria-label="Notifications, {{ $unreadNotificationCount }} unread">
    <i class="bi bi-bell fs-6" aria-hidden="true"></i>
    {{-- Rendered server-side AND updated by the poll script, so a notification
         that arrives while somebody is on the page shows up without a reload.
         The element always exists (empty when there is nothing unread) because the
         poll ADDS and REMOVES it — a badge that only appears on render cannot be
         removed by script that has nothing to remove.
         `aria-label` on the bell carries the count too, so the number is not
         colour-only and the script updates that as well. --}}
    <span id="notification-badge"
          class="position-absolute top-200 start-100 translate-middle badge rounded-pill bg-danger badge-notification-unread"
          data-empty="{{ $unreadNotificationCount < 1 ? 'true' : 'false' }}"
          @if ($unreadNotificationCount < 1) hidden @endif
          aria-hidden="true">{{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}</span>
  </a>
  <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end notifications-preview border-0 shadow-sm p-0 rounded-3 mt-2">
    <div class="dropdown-header notifications-preview-header bg-transparent border-bottom py-3 px-3 d-flex justify-content-between align-items-center">
      <h6 class="fw-semibold mb-0">Notifications</h6>
      <form method="POST" action="{{ route('notifications.inbox.read-all') }}">
        @csrf
        <button type="submit" class="text-decoration-none small text-primary bg-transparent border-0 p-0" style="line-height: 1;">
          Mark all as read
        </button>
      </form>
    </div>

    @include('layouts.partials.notification-dropdown-list')

    <div class="dropdown-footer bg-body-tertiary border-top py-2 text-center">
      <a href="{{ route('notifications.inbox') }}" class="text-decoration-none small fw-semibold text-primary">
        View all notifications <i class="bi bi-arrow-right ms-1"></i>
      </a>
    </div>
  </div>
</li>

@push('scripts')
    @include('layouts.partials.scripts.notification-poll')
@endpush

<li class="nav-item dropdown">
  <a class="nav-link text-secondary position-relative d-flex align-items-center"
     href="{{ route('notifications.inbox') }}"
     data-bs-toggle="dropdown"
     aria-expanded="false"
     title="Notifications"
     aria-label="Notifications, {{ $unreadNotificationCount }} unread">
    <i class="bi bi-bell fs-6" aria-hidden="true"></i>
    @if ($unreadNotificationCount > 0)
      <span class="position-absolute top-200 start-100 translate-middle badge rounded-pill bg-danger badge-notification-unread"
            aria-hidden="true">{{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}</span>
    @endif
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

    <div class="list-group list-group-flush overflow-auto" style="max-height: 320px;">
      @forelse ($recentNotifications as $notification)
        @php
          $type = $notification->data['type'] ?? '';
          $parts = explode('\\', $type);
          $class = end($parts) ?: '';
          $color = match ($class) {
              'UserCreatedNotification', 'UserRegisteredNotification', 'RegisterNotification' => 'primary',
              'ChangeEmailVerificationNotification' => 'info',
              'AccountStateChangedNotification', 'ConfigurationChangedNotification' => 'warning',
              'RolesChangedNotification' => 'success',
              default => 'secondary',
          };
          $circle = "bg-{$color}-subtle text-{$color}";
        @endphp
        <a href="{{ route('notifications.inbox') }}"
           class="notifications-preview-item list-group-item list-group-item-action py-3 px-3 border-bottom d-flex align-items-start gap-3 bg-body-tertiary">
          <div class="rounded-3 {{ $circle }} p-2" style="width: 36px; height: 36px; flex-shrink: 0;">
            @if ($notification->read_at === null)
              <i class="bi bi-check2-circle fs-6"></i>
            @else
              <i class="bi bi-check fs-6"></i>
            @endif
          </div>
          <div class="flex-grow-1 min-w-0">
            <div class="fw-semibold small text-body mb-1">
              {{ $notification->data['subject'] ?? 'Notification' }}
            </div>
            <div class="text-muted small">
              <i class="bi bi-clock me-1"></i> {{ $notification->created_at->diffForHumans() }}
            </div>
          </div>
        </a>
      @empty
        <div class="dropdown-item-text text-muted py-3 text-center">
          No notifications yet.
        </div>
      @endforelse
    </div>

    <div class="dropdown-footer bg-body-tertiary border-top py-2 text-center">
      <a href="{{ route('notifications.inbox') }}" class="text-decoration-none small fw-semibold text-primary">
        View all notifications <i class="bi bi-arrow-right ms-1"></i>
      </a>
    </div>
  </div>
</li>

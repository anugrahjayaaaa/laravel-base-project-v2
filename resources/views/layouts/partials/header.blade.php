<header class="app-header navbar navbar-expand bg-body border-bottom">
  <div class="container-fluid">
    <!-- LEFT: Sidebar toggle + Search -->
    <div class="d-none d-md-flex align-items-center">
      <button type="button" class="nav-link text-secondary"
              data-lte-toggle="sidebar" title="Toggle sidebar">
        <i class="fas fa-chevron-left" id="sidebar-toggle-icon"></i>
      </button>
      <form class="feature-search" role="search">
        <div class="input-group">
          <input type="search" name="q" class="form-control border-0" placeholder="Search features" aria-label="Search features">
          <span class="input-group-text bg-transparent border-0">
            <i class="fas fa-search text-muted"></i>
          </span>
        </div>
      </form>
    </div>

    <!-- RIGHT: Notification + Theme + User -->
    <div class="navbar-nav ms-auto d-flex flex-row align-items-center">
      <!-- Notification: a real link, gated by the same flag as the sidebar item, so
           a switched-off module leaves no icon pointing at a 403. The unread
           badge and the inbox target land with P9-C2; until then it points at
           the module root, which is the configuration page. -->
      <!-- Notification: bell opens the viewer's recent-notification list.
           Bootstrap 5 data-api owns the toggle (no custom JS here). Combined
           option 1 + 2: single column list, icon circle + subject, timestamp
           below the text. -->
      @if ($notificationsVisible ?? false)
        <li class="nav-item dropdown">
          <a class="nav-item nav-link px-2 position-relative"
             href="{{ route('notifications.inbox') }}"
             data-bs-toggle="dropdown" aria-expanded="false"
             title="Notifications"
             @class(['text-decoration-none' => ! $notificationsVisible ?? false])>
            <i class="far fa-bell"></i>
            @if ($unreadNotificationCount > 0)
              <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger fs-8 badge-notification-unread">
                {{ $unreadNotificationCount }} unread notifications
              </span>
            @endif
          </a>
          <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end border-0 shadow-sm p-0 rounded-3 mt-2">
            <div class="dropdown-header bg-transparent border-bottom py-3 px-3 d-flex justify-content-between align-items-center">
              <h6 class="fw-semibold mb-0">Notifications</h6>
              <a href="/notifications/inbox" class="text-decoration-none small text-primary">Mark all as read</a>
            </div>

            <div class="list-group list-group-flush overflow-auto" style="max-height: 320px;">
              @forelse ($recentNotifications as $notification)
                @php
                  $type = $notification->data['type'] ?? '';
                  $parts = explode('\\', $type);
                  $class = end($parts) ?: '';
                  $color = match ($class) {
                      'UserCreatedNotification', 'UserRegisteredNotification' => 'primary',
                      'ChangeEmailVerificationNotification' => 'info',
                      'AccountStateChangedNotification', 'ConfigurationChangedNotification' => 'warning',
                      'RolesChangedNotification' => 'success',
                      default => 'secondary',
                  };
                  $circle = "bg-{$color}-subtle text-{$color}";
                @endphp
                <a href="#"
                   class="list-group-item list-group-item-action py-3 px-3 border-bottom d-flex align-items-start gap-3 bg-body-tertiary">
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
                    <div class="text-muted fs-8">
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
              <a href="/notifications/inbox" class="text-decoration-none small fw-semibold text-primary">
                View all notifications <i class="bi bi-arrow-right ms-1"></i>
              </a>
            </div>
          </div>
        </li>
      @endif

      @include('layouts.partials.scripts.theme-toggle')

      <!-- User menu (dropdown) -->
      <div class="dropdown">
        <a href="#" class="nav-link text-secondary d-flex align-items-center"
           role="button" data-bs-toggle="dropdown" aria-expanded="false"
           title="Admin user menu">
          <i class="fas fa-user-circle"></i>
          <span class="d-none d-md-inline text-muted ms-2">{{ $currentUserName }}</span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <a class="dropdown-item" href="{{ Route::has('profile.show') ? route('profile.show') : '#' }}">
              <i class="fas fa-user me-2"></i> Profile
            </a>
          </li>
          <li>
            {{-- Was 'Settings' pointing at settings.preferences, a route that
                 does not exist — so it silently rendered a dead '#'. Sessions is
                 self-service and always reachable, like Profile.
                 `$sessionsVisible` comes from AppMenuComposer, which already owns
                 "may this viewer reach this module". A view must not call an app
                 class itself, and duplicating the flag check here would give the
                 dropdown a second answer to a question the composer already
                 answers — the two disagreeing is the exact bug this guards. --}}
            @if ($sessionsVisible ?? false)
              <a class="dropdown-item" href="{{ route('sessions') }}">
                <i class="fas fa-laptop me-2"></i> Sessions
              </a>
            @endif
          </li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <form method="POST" action="{{ Route::has('logout') ? route('logout') : '#' }}">
              @if (Route::has('logout'))
                @csrf
              @endif
              <button type="submit" class="dropdown-item">
                <i class="fas fa-right-from-bracket me-2"></i> Logout
              </button>
            </form>
          </li>
        </ul>
      </div>
    </div>
  </div>
</header>
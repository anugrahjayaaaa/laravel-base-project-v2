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
      @if ($notificationsVisible ?? false)
        <a href="{{ Route::has('notifications.index') ? route('notifications.index') : '#' }}"
           class="nav-link text-secondary" title="Notifications">
          <i class="far fa-bell"></i>
        </a>
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

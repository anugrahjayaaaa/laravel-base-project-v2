<header class="app-header navbar navbar-expand bg-body border-bottom">
  <div class="container-fluid">
    <!-- LEFT: Sidebar toggle -->
    <a href="#" class="nav-link text-secondary d-none d-md-inline-flex"
       data-lte-toggle="sidebar" title="Toggle sidebar">
      <i class="fas fa-chevron-left"></i>
    </a>

    <!-- RIGHT: Notification + Theme + User -->
    <div class="navbar-nav ms-auto d-flex flex-row align-items-center">
      <!-- Notification -->
      <a href="#" class="nav-link text-secondary" title="Notifications">
        <i class="far fa-bell"></i>
      </a>

      <!-- Theme toggle (icon-only: sun when dark, moon when light) -->
      <a href="#" class="nav-link text-secondary"
         id="theme-toggle" title="Toggle theme">
        <i id="theme-icon" class="fas fa-moon"></i>
      </a>

      <!-- User menu (dropdown) -->
      <div class="dropdown">
        <a href="#" class="d-flex align-items-center text-decoration-none"
           role="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="fas fa-user-circle fa-2x text-secondary"></i>
          <span class="d-none d-md-inline text-muted ms-2">Admin</span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <a class="dropdown-item" href="{{ Route::has('profile.show') ? route('profile.show') : '#' }}">
              <i class="fas fa-user me-2"></i> Profile
            </a>
          </li>
          <li>
            <a class="dropdown-item" href="{{ Route::has('settings.preferences') ? route('settings.preferences') : '#' }}">
              <i class="fas fa-gear me-2"></i> Settings
            </a>
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

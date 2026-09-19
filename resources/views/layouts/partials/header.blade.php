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
      <!-- Notification -->
      <button type="button" class="nav-link text-secondary" title="Notifications">
        <i class="far fa-bell"></i>
      </button>

      @include('layouts.partials.theme-toggle')

      <!-- User menu (dropdown) -->
      <div class="dropdown">
        <a href="#" class="nav-link text-secondary d-flex align-items-center"
           role="button" data-bs-toggle="dropdown" aria-expanded="false"
           title="Admin user menu">
          <i class="fas fa-user-circle"></i>
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

<aside class="app-sidebar bg-body border-end">
  <div class="sidebar-brand">
    <a href="{{ route('dashboard') }}" class="d-flex align-items-center text-decoration-none">
      <i class="fas fa-cube text-primary me-2"></i>
      <span class="brand-text fw-semibold">{{ config('app.name', 'Laravel Base Project') }}</span>
    </a>
  </div>
  <div class="sidebar-wrapper">
    <nav class="mt-3">
      <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="false">
        @foreach ($menuGroups ?? [] as $group)
          @if (isset($group['label']))
            <li class="nav-header">{{ $group['label'] }}</li>
          @endif
          @if (isset($group['items']))
            @foreach ($group['items'] as $item)
              @php($route = $item['route'] ?? null)
              @php($href = $route ? (Route::has($route) ? route($route) : '#') : '#')
              @php($active = isset($item['active']) && $item['active']
                  ? (request()->route() && $route && fnmatch($item['active'], request()->route()->getName()))
                  : false)
              <li class="nav-item">
                <a href="{{ $href }}"
                   class="nav-link{{ $active ? ' active' : '' }}">
                  @if (isset($item['icon']))
                    <i class="{{ $item['icon'] }} me-2"></i>
                  @endif
                  <p class="mb-0">{{ $item['label'] ?? '' }}</p>
                </a>
              </li>
            @endforeach
          @endif
        @endforeach
      </ul>
    </nav>
  </div>
</aside>

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
              @php($routeName = request()->route()?->getName())
              @php($active = $route && $routeName && isset($item['active'])
                  && fnmatch($item['active'], $routeName)
                  // A prefix pattern covers a sibling screen's routes too: `members.*`
                  // is true for `members.tiers`, which would light two entries at once.
                  // The item that owns the sub-tree names it here.
                  && ! (isset($item['except']) && fnmatch($item['except'], $routeName)))
              <li class="nav-item">
                <a href="{{ $href }}"
                   @if (! empty($item['target'])) target="{{ $item['target'] }}" rel="noopener" @endif
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

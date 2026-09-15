@props([
    'field',
    'label',
    'sortable' => true,
    'currentSort' => null,
    'currentDirection' => null,
])

@php
    $direction = $currentSort === $field ? ($currentDirection === 'asc' ? 'asc' : 'desc') : null;
    $isActive = $currentSort === $field;
    $nextDirection = $isActive && $currentDirection === 'asc' ? 'desc' : 'asc';
    $icon = match(true) {
        !$sortable => 'fas fa-arrows-alt-vs opacity-25',
        $isActive && $currentDirection === 'asc' => 'fas fa-arrow-up',
        $isActive && $currentDirection === 'desc' => 'fas fa-arrow-down',
        default => 'fas fa-sort',
    };
@endphp

<th class="{{ $sortable ? 'sortable' : '' }}">
    @if($sortable)
        @php($queryString = http_build_query(array_merge(request()->except(['sort', 'direction', 'page']), ['sort' => $field, 'direction' => $nextDirection])))
        <a href="?{{ $queryString }}" class="nav-link d-flex align-items-center gap-1">
            {{ $label }}
            <i class="{{ $icon }}"></i>
        </a>
    @else
        {{ $label }}
    @endif
</th>

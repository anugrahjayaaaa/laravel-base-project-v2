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

{{-- `scope="col"` and `aria-sort`, and the reason this component is the right
     place for both rather than the four pages that call it.

     `scope="col"` is what tells a screen reader that a header cell labels the
     COLUMN rather than the row, and it was missing here while every literal
     `<th scope="col">` on the same page carried it — so the audit trail's own
     exports table announced its headers correctly and the activity log's own
     header row did not.

     `aria-sort` goes on the ACTIVE column only, and that is the part worth
     stating: the attribute describes the table's current sort order, so putting
     `aria-sort="none"` on all seven cells announces "not sorted" seven times and
     drowns the one cell that carries the answer. One column owns the state, the
     rest simply do not claim it. --}}
<th scope="col" @if ($isActive) aria-sort="{{ $direction === 'asc' ? 'ascending' : 'descending' }}" @endif
    class="{{ $sortable ? 'sortable' : '' }}">
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

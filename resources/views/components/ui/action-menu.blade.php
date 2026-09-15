@props([
    'label' => 'Actions',
])

<div class="dropdown">
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fas fa-ellipsis-vertical"></i> {{ $label }}
    </button>
    <ul class="dropdown-menu">
        {{ $slot }}
    </ul>
</div>

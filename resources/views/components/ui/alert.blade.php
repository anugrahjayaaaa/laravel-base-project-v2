@props([
    'variant' => 'info',
    'dismissible' => true,
])

@php
    $alertClass = match($variant) {
        'success' => 'alert alert-success',
        'warning' => 'alert alert-warning',
        'danger' => 'alert alert-danger',
        'info' => 'alert alert-info',
        default => 'alert alert-info',
    };
@endphp

<div class="{{ $alertClass }} {{ $dismissible ? 'alert-dismissible fade show' : '' }}" role="alert">
    {{ $slot }}
    @if($dismissible)
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    @endif
</div>

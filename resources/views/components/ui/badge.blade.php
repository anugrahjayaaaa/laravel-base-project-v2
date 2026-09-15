@props([
    'variant' => 'info',
    'text' => '',
])

@php
    $badgeClass = match($variant) {
        'success' => 'bg-success',
        'warning' => 'bg-warning text-dark',
        'danger' => 'bg-danger',
        'info' => 'bg-info text-dark',
        'neutral' => 'bg-secondary',
        default => 'bg-secondary',
    };
@endphp

<span class="badge {{ $badgeClass }}">{{ $text ?: $slot }}</span>

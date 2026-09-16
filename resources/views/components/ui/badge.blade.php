@props([
    'variant' => 'info',
    'text' => '',
])

@php
    // Subtle (soft) background per design-system.md §105 and style-guide.md §12.
    // Theme.css color-mix rules provide the soft tint for each token.
    $badgeClass = match($variant) {
        'primary' => 'bg-primary-subtle',
        'success' => 'bg-success-subtle',
        'warning' => 'bg-warning-subtle',
        'danger' => 'bg-danger-subtle',
        'info'    => 'bg-info-subtle',
        'neutral' => 'bg-secondary-subtle',
        default   => 'bg-secondary-subtle',
    };
@endphp

<span class="badge {{ $badgeClass }}">{{ $text ?: $slot }}</span>

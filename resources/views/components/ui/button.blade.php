@props([
    'variant' => 'primary',
    'size' => 'sm',
    'outline' => false,
    'class' => '',
])

@php
    $baseClass = 'btn btn-' . $size;
    if ($outline) {
        $baseClass .= ' btn-outline-' . $variant;
    } else {
        $baseClass .= ' btn-' . $variant;
    }
@endphp

<button type="button" class="{{ $baseClass }} {{ $class }}" {{ $attributes }}>
    {{ $slot }}
</button>

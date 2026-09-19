@props([
    'action' => null,
    'method' => 'POST',
    'variant' => 'danger',
    'label' => 'Delete',
    'title' => 'Confirm',
    'message' => 'Are you sure?',
    'cancelLabel' => 'Cancel',
    'confirmLabel' => null,
    'callback' => null,
    'class' => 'dropdown-item',
])

@php($confirmLabel = $confirmLabel ?? $label)
@php($modalId = 'confirmModal')

<button type="button"
        data-bs-toggle="modal"
        data-bs-target="#{{ $modalId }}"
        data-action="{{ $action }}"
        data-method="{{ $method }}"
        data-variant="{{ $variant }}"
        data-title="{{ $title }}"
        data-message="{{ $message }}"
        data-label="{{ $confirmLabel }}"
        @if($callback) data-callback="{{ $callback }}" @endif
        class="{{ $class }}">
    {{ $slot }}
</button>

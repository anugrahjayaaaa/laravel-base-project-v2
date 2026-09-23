@props([
    'action' => null,
    'method' => 'POST',
    'actionType' => 'delete',
    'itemName' => '',
    'label' => 'Delete',
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
        data-action-type="{{ $actionType }}"
        data-item-name="{{ $itemName }}"
        data-label="{{ $confirmLabel ?? $label }}"
        @if($callback) data-callback="{{ $callback }}" @endif
        class="{{ $class }}">
    {{ $slot }}
</button>

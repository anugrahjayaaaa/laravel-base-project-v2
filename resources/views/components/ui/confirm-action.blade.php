@props([
    'action' => null,
    'method' => 'POST',
    // null, not 'delete': a default here is not a harmless fallback. The driver
    // reads data-action-type and looks it up in ACTION_CONFIG, so a component
    // that omits action-type but inherits a default claims somebody else's copy
    // — an Unlock button would offer "Move X to trash?". Omit it and the legacy
    // branch takes over, which is the honest signal that this trigger brings
    // its own title/message.
    'actionType' => null,
    'itemName' => '',
    'label' => 'Delete',
    'cancelLabel' => 'Cancel',
    'confirmLabel' => null,
    'callback' => null,
    'class' => 'dropdown-item',
    'title' => null,
    // Legacy overrides. Per design-system.md every action should read
    // ACTION_CONFIG, but the user form's Unlock has always carried its own
    // copy, and changing the words a user reads is not a formatting fix. Omit
    // actionType and these take over, exactly as the raw attributes did.
    'message' => null,
    'variant' => null,
    'icon' => null,
])

@php($confirmLabel = $confirmLabel ?? $label)
@php($modalId = 'confirmModal')

<button type="button"
        @if($title) title="{{ $title }}" @endif
        data-bs-toggle="modal"
        data-bs-target="#{{ $modalId }}"
        data-action="{{ $action }}"
        data-method="{{ $method }}"
        @if($actionType) data-action-type="{{ $actionType }}" @endif
        data-item-name="{{ $itemName }}"
        data-label="{{ $confirmLabel ?? $label }}"
        @if($callback) data-callback="{{ $callback }}" @endif
        @if(!$actionType && $title) data-title="{{ $title }}" @endif
        @if($message) data-message="{{ $message }}" @endif
        @if($variant) data-variant="{{ $variant }}" @endif
        @if($icon) data-icon="{{ $icon }}" @endif
        class="{{ $class }}">
    {{ $slot }}
</button>

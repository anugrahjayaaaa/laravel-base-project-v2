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
    'callback' => null,
    'class' => 'dropdown-item',
    // 'button' | 'input'. See the @if above the <button>.
    'tag' => 'button',
    'title' => null,
    // Member deactivation only: the modal has to ask whether this is a sanction
    // before it submits, because that answer decides whether the paid period is
    // forfeited or paused. Every other trigger leaves it false and the block
    // stays hidden and disabled, so it submits nothing.
    'deactivationFields' => false,
    // Legacy overrides. Per design-system.md every action should read
    // ACTION_CONFIG, but the user form's Unlock has always carried its own
    // copy, and changing the words a user reads is not a formatting fix. Omit
    // actionType and these take over, exactly as the raw attributes did.
    'message' => null,
    'variant' => null,
    'icon' => null,
])

@php($modalId = 'confirmModal')

{{-- `tag` exists for one caller: the feature-flag switch, which has to BE a
     checkbox rather than a button. The modal driver reads the same six data-*
     attributes off any element, so the tag is the only thing that differs.
     Everything below is unchanged, so the eight existing button triggers
     render byte-identically. --}}
@if ($tag === 'input')
    <input
        @if($title) title="{{ $title }}" @endif
        data-bs-toggle="modal"
        data-bs-target="#{{ $modalId }}"
        data-action="{{ $action }}"
        data-method="{{ $method }}"
        @if($actionType) data-action-type="{{ $actionType }}" @endif
        data-item-name="{{ $itemName }}"
        data-label="{{ $label }}"
        @if($callback) data-callback="{{ $callback }}" @endif
        @if(!$actionType && $title) data-title="{{ $title }}" @endif
        @if($message) data-message="{{ $message }}" @endif
        @if($variant) data-variant="{{ $variant }}" @endif
        @if($icon) data-icon="{{ $icon }}" @endif
        {{ $attributes->merge(['class' => 'form-check-input']) }}>
@else
<button type="button"
        @if($title) title="{{ $title }}" @endif
        data-bs-toggle="modal"
        data-bs-target="#{{ $modalId }}"
        data-action="{{ $action }}"
        data-method="{{ $method }}"
        @if($actionType) data-action-type="{{ $actionType }}" @endif
        data-item-name="{{ $itemName }}"
        data-label="{{ $label }}"
        @if($callback) data-callback="{{ $callback }}" @endif
        @if(!$actionType && $title) data-title="{{ $title }}" @endif
        @if($message) data-message="{{ $message }}" @endif
        @if($variant) data-variant="{{ $variant }}" @endif
        @if($icon) data-icon="{{ $icon }}" @endif
        @if($deactivationFields) data-deactivation-fields="1" @endif
        class="{{ $class }}">
    {{ $slot }}
</button>
@endif

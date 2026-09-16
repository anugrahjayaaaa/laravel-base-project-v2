@props([
    'type' => 'text',
    'name' => null,
    'id' => null,
    'placeholder' => '',
    'value' => null,
    'required' => false,
    'class' => '',
])

@if($type === 'select')
    <select
        name="{{ $name }}"
        id="{{ $id }}"
        @if($required) required @endif
        class="form-select {{ $class }}"
        {{ $attributes }}
    >
        @isset($placeholder)
            <option value="" disabled selected>{{ $placeholder }}</option>
        @endisset
        {{ $slot }}
    </select>
@else
    <input
        type="{{ $type }}"
        name="{{ $name }}"
        id="{{ $id }}"
        value="{{ $value ?? '' }}"
        placeholder="{{ $placeholder }}"
        @if($required) required @endif
        class="form-control {{ $class }}"
        {{ $attributes }}
    >
@endif

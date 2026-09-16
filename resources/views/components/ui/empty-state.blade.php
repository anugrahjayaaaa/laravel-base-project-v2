@props([
    'icon' => 'fas fa-inbox',
    'message' => 'No records found.',
])

<div class="ui-state-wrapper text-center py-5">
    <div class="mb-3">
        <i class="{{ $icon }} fa-3x text-muted"></i>
    </div>
    <p class="text-muted">{{ $message }}</p>
</div>

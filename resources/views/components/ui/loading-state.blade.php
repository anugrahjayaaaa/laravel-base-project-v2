@props([
    'message' => 'Loading...',
])

<div class="ui-state-wrapper text-center py-5" data-loading>
    <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">{{ $message }}</span>
    </div>
    <p class="text-muted mt-2">{{ $message }}</p>
</div>

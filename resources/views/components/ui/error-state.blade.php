@props([
    'message' => 'Something went wrong.',
    'details' => null,
])

<div class="ui-state-wrapper alert alert-danger" role="alert">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <strong>{{ $message }}</strong>
    @if($details)
        <p class="mb-0 mt-1">{{ $details }}</p>
    @endif
</div>

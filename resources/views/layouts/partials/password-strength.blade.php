{{-- Password rule checklist, driven by the same settings the validator uses.
     $passwordMinLength is passed by the register controller. The fallback keeps
     the older callers (reset, profile, password-expired) rendering as before. --}}
@php
    $passwordMinLength = $passwordMinLength ?? 12;
@endphp
<div class="password-strength mt-2 d-none" id="password-strength-container" data-policy="password-strength">
    <div class="progress" style="height: 4px;">
        <div class="progress-bar strength-bar" role="progressbar" style="width: 0%"></div>
    </div>
    <div class="strength-label small mt-1"></div>
    <ul class="rules list-unstyled small text-muted mb-0 mt-1">
        <li data-rule="length" data-min="{{ $passwordMinLength }}"><i class="bi bi-circle me-1"></i>At least {{ $passwordMinLength }} characters</li>
        <li data-rule="upper"><i class="bi bi-circle me-1"></i>One uppercase letter</li>
        <li data-rule="lower"><i class="bi bi-circle me-1"></i>One lowercase letter</li>
        <li data-rule="digit"><i class="bi bi-circle me-1"></i>One number</li>
        <li data-rule="symbol"><i class="bi bi-circle me-1"></i>One symbol</li>
    </ul>
</div>

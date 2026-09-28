{{-- Password rule checklist, driven by the same settings the validator uses.
     $passwordMinLength and $passwordActiveRules come from
     PasswordStrengthComposer (registered in AppServiceProvider), so every
     caller gets the current policy without repeating the lookup.

     Only the rules the policy currently enforces are listed. It used to render
     all five unconditionally, so an admin who turned a rule off still saw the
     form demanding it — and the strength bar divided by five regardless. The
     active list goes out as data-rules so the JS scores the same set.

     Pwned Check is deliberately absent: it is the one rule that leaves the
     server, and telling the browser to ask a third party about a typed
     candidate is not a trade worth making. The server reports it on submit. --}}
<div class="password-strength mt-2 d-none" id="password-strength-container" data-policy="password-strength"
     data-rules="{{ implode(',', $passwordActiveRules) }}">
    <div class="progress" style="height: 4px;">
        <div class="progress-bar strength-bar" role="progressbar" style="width: 0%"></div>
    </div>
    <div class="strength-label small mt-1"></div>
    <ul class="rules list-unstyled small text-muted mb-0 mt-1">
        <li data-rule="length" data-min="{{ $passwordMinLength }}"><i class="bi bi-circle me-1"></i>At least {{ $passwordMinLength }} characters</li>
        @if (in_array('upper', $passwordActiveRules, true))
            <li data-rule="upper"><i class="bi bi-circle me-1"></i>One uppercase letter</li>
        @endif
        @if (in_array('lower', $passwordActiveRules, true))
            <li data-rule="lower"><i class="bi bi-circle me-1"></i>One lowercase letter</li>
        @endif
        @if (in_array('digit', $passwordActiveRules, true))
            <li data-rule="digit"><i class="bi bi-circle me-1"></i>One number</li>
        @endif
        @if (in_array('symbol', $passwordActiveRules, true))
            <li data-rule="symbol"><i class="bi bi-circle me-1"></i>One symbol</li>
        @endif
    </ul>
</div>

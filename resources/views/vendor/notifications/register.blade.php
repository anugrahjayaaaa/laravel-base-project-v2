@component('mail::message')
# Welcome

Welcome aboard. Your account is ready, {{ $username }}.

Verify your email address to activate it. You can sign in once the link below is used.

@component('mail::button', ['url' => $url])
Verify Email
@endcomponent

This verification link expires in **{{ $expireMinutes }} minutes**.

If you did not create this account, no action is needed.

@endcomponent

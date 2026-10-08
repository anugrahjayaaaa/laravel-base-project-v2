@component('mail::message')
# Verify your email to activate your account

Open the link below to activate your account, then sign in as **{{ $username }}**.

@component('mail::button', ['url' => $url])
Verify email address
@endcomponent

This link expires in **{{ $expireMinutes }} minutes**.

If you did not create this account, no action is needed.
@endcomponent
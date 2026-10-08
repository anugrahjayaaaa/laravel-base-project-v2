@component('mail::message')
# Your new account is ready

Sign in with the temporary password below, then change it right away.

Username: **{{ $username }}**

Temporary password: **{{ $tempPassword }}**

@if ($causer)
    This account was created for you by **{{ $causer }}**.
@endif

@component('mail::button', ['url' => $url])
Verify email address
@endcomponent

This link expires in **{{ $expireMinutes }} minutes**.

You must change the temporary password at your first sign-in.
@endcomponent
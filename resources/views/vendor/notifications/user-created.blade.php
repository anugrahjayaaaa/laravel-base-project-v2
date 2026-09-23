@component('mail::message')
# Your Account Has Been Created

Your account has been created by an administrator.

Username: **{{ $username }}**

Temporary Password: **{{ $tempPassword }}**

Please verify your email before logging in.

@component('mail::button', ['url' => $url])
Verify Email
@endcomponent

You must change this password upon first login.

@endcomponent
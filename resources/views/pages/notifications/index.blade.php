{{--
    Notifications & Mail — SMTP configuration (Phase 9 Group A, P9-A1).

    Contract with NotificationController::index() (no queries, no route() here):
      $settings      ['mail_mailer' => 'smtp', 'mail_host' => …, …]  assoc array,
                     values already cast to strings by the controller
      $mailers       ['smtp' => 'SMTP', …]  the configured mailers, name => label
      $hasPassword   bool  whether a password is already stored — the value is
                     NEVER echoed back into this markup
      $updateUrl     string  the save endpoint (Group B swaps the stub for the
                     real route; building it here would be a URL in a view)
      $sendTestUrl   string  the send-test endpoint, same reason

    `$settings` arrives as an array, not as `SystemSetting::getAll()` read here:
    a lookup left in Blade is invisible until the request happens to run with a
    cold cache, which is why `NotificationUiRenderTest` counts queries around the
    render. Booleans are cast in the controller, never with `filter_var` here —
    a stored 'false' string is truthy in PHP, so an uncast value renders every
    switch as ON.
--}}
@extends('layouts.app', ['title' => 'Notifications & Mail'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">Notifications &amp; Mail</h1>
                <p class="page-description text-muted fs-7 mb-0">
                    Configure the mail transport used for every notification this application sends.
                </p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Notifications &amp; Mail</li>
            </ol>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
            <i class="fas fa-circle-check me-1"></i>
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <i class="fas fa-circle-exclamation me-1"></i>
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- The route is gated on notifications.view, so a holder of that alone gets a
         READ-ONLY page rather than a 403 — they can see what is configured but not
         change it. --}}
    @can('notifications.view')
        @can('notifications.manage')
            <div class="row g-4">
                {{-- Main Content Area: the SMTP configuration form --}}
                <div class="col-12 col-lg-8">
                    <form method="POST" action="{{ $updateUrl }}">
                        @csrf

                        <div class="card border-0 shadow-sm mb-4" id="section-transport">
                            <div class="card-header bg-transparent border-bottom py-3 d-flex justify-content-between align-items-center gap-2">
                                <h5 class="card-title mb-0 fw-semibold">Mail Transport</h5>
                                {{-- The two pages link both ways. The channels page
                                     breadcrumbs back here and cancels back here; without
                                     this the return trip needs the sidebar, and the module
                                     has two pages of unequal access.

                                     `ms-auto` pins it right even though the header is
                                     already justify-content-between: the read-only badge
                                     shares this row, and without it the link drifts to
                                     the middle of three elements.

                                     `btn-primary` + these sizing classes are the design
                                     system's card-header action (design-system.md
                                     §Index Page), so it matches every other module's
                                     action button instead of inventing a variant.

                                     No extra @can: this branch is already inside
                                     @can('notifications.view'), which is the permission the
                                     channels route checks — a second check on the same
                                     ability would be a second place to disagree. --}}
                                <a href="{{ route('notifications.channels') }}"
                                   class="btn btn-primary btn-sm ms-auto d-inline-flex align-items-center gap-2">
                                    <i class="bi bi-tower-broadcast"></i> Channels
                                </a>
                            </div>
                            <div class="card-body p-4">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="mail_mailer" class="form-label">
                                            Transport
                                            <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Mail transport used for every outgoing message. SMTP delivers over the network; log and array write to a local driver instead."></i>
                                        </label>
                                        <select name="mail_mailer" id="mail_mailer"
                                                class="form-select form-select-sm @error('mail_mailer') is-invalid @enderror" @error('mail_mailer') aria-invalid="true" aria-describedby="mail_mailer_error" @enderror>
                                            @foreach ($mailers as $value => $label)
                                                <option value="{{ $value }}" {{ old('mail_mailer', $settings['mail_mailer'] ?? '') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @error('mail_mailer')
                                            <div class="invalid-feedback d-block" id="mail_mailer_error">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-6">
                                        <label for="mail_encryption" class="form-label">
                                            Encryption
                                            <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Transport security layer. Choose TLS on port 587, SSL on port 465, or none only for a local relay."></i>
                                        </label>
                                        <select name="mail_encryption" id="mail_encryption"
                                                class="form-select form-select-sm @error('mail_encryption') is-invalid @enderror" @error('mail_encryption') aria-invalid="true" aria-describedby="mail_encryption_error" @enderror>
                                            @foreach (['smtp' => 'TLS', 'tls' => 'STARTTLS', 'ssl' => 'SSL', 'none' => 'None'] as $value => $label)
                                                <option value="{{ $value }}" {{ old('mail_encryption', $settings['mail_encryption'] ?? 'smtp') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @error('mail_encryption')
                                            <div class="invalid-feedback d-block" id="mail_encryption_error">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm mb-4" id="section-smtp">
                            <div class="card-header bg-transparent border-bottom py-3">
                                <h5 class="card-title mb-0 fw-semibold">SMTP Server</h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label for="mail_host" class="form-label">
                                            Host
                                            <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Hostname of the outgoing mail server, e.g. smtp.example.com."></i>
                                        </label>
                                        <input type="text" name="mail_host" id="mail_host"
                                               class="form-control form-control-sm @error('mail_host') is-invalid @enderror" @error('mail_host') aria-invalid="true" aria-describedby="mail_host_error" @enderror
                                               value="{{ old('mail_host', $settings['mail_host'] ?? '') }}" autocomplete="off">
                                        @error('mail_host')
                                            <div class="invalid-feedback d-block" id="mail_host_error">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-4">
                                        <label for="mail_port" class="form-label">
                                            Port
                                            <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Server port. 587 for STARTTLS, 465 for implicit SSL."></i>
                                        </label>
                                        <input type="number" name="mail_port" id="mail_port"
                                               class="form-control form-control-sm @error('mail_port') is-invalid @enderror" @error('mail_port') aria-invalid="true" aria-describedby="mail_port_error" @enderror
                                               value="{{ old('mail_port', $settings['mail_port'] ?? 587) }}" min="1" max="65535">
                                        @error('mail_port')
                                            <div class="invalid-feedback d-block" id="mail_port_error">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-6">
                                        <label for="mail_username" class="form-label">
                                            Username
                                            <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Account the mail server authenticates as. Leave empty for a relay that needs no authentication."></i>
                                        </label>
                                        <input type="text" name="mail_username" id="mail_username"
                                               class="form-control form-control-sm @error('mail_username') is-invalid @enderror" @error('mail_username') aria-invalid="true" aria-describedby="mail_username_error" @enderror
                                               value="{{ old('mail_username', $settings['mail_username'] ?? '') }}" autocomplete="off">
                                        @error('mail_username')
                                            <div class="invalid-feedback d-block" id="mail_username_error">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-6">
                                        <label for="mail_from_address" class="form-label">
                                            From Address
                                            <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Sender email address for outgoing notifications."></i>
                                        </label>
                                        <input type="email" name="mail_from_address" id="mail_from_address"
                                               class="form-control form-control-sm @error('mail_from_address') is-invalid @enderror" @error('mail_from_address') aria-invalid="true" aria-describedby="mail_from_address_error" @enderror
                                               value="{{ old('mail_from_address', $settings['mail_from_address'] ?? '') }}" autocomplete="off">
                                        @error('mail_from_address')
                                            <div class="invalid-feedback d-block" id="mail_from_address_error">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-6">
                                        <label for="mail_from_name" class="form-label">
                                            From Name
                                            <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Display name for outgoing notifications."></i>
                                        </label>
                                        <input type="text" name="mail_from_name" id="mail_from_name"
                                               class="form-control form-control-sm @error('mail_from_name') is-invalid @enderror" @error('mail_from_name') aria-invalid="true" aria-describedby="mail_from_name_error" @enderror
                                               value="{{ old('mail_from_name', $settings['mail_from_name'] ?? '') }}" autocomplete="off">
                                        @error('mail_from_name')
                                            <div class="invalid-feedback d-block" id="mail_from_name_error">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-md-6">
                                        <label for="mail_password" class="form-label">
                                            Password
                                            <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Stored credential. It is never sent back to the browser, so an empty field here leaves the current one unchanged."></i>
                                        </label>
                                        {{-- Never bound to a value: an SMTP credential rendered into
                                             the markup is readable by anyone who can view this page,
                                             which is exactly the audience the password hides from. The
                                             stored state is reported by the hint below instead. --}}
                                        <div class="position-relative">
                                            <input type="password" name="mail_password" id="mail_password"
                                               class="form-control pe-5 form-control-sm @error('mail_password') is-invalid @enderror" @error('mail_password') aria-invalid="true" aria-describedby="mail_password_error" @enderror
                                               value="" autocomplete="new-password">
                                            <button type="button"
                                                class="btn btn-link text-muted text-decoration-none position-absolute top-50 translate-middle-y toggle-password p-0 border-0"
                                                data-password-toggle="mail_password" aria-label="Toggle password visibility"
                                                tabindex="-1" style="right: 0.75rem; z-index: 5;">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        @error('mail_password')
                                            <div class="invalid-feedback d-block" id="mail_password_error">{{ $message }}</div>
                                        @enderror
                                        <div class="form-text">
                                            @if ($hasPassword)
                                                A password is stored. Leave this empty to keep it.
                                            @else
                                                No password stored yet.
                                            @endif
                                        </div>
                                    </div>
                            </div>
                            <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
                                <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                                    <i class="bi bi-check-lg"></i> Save Mail Settings
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                {{-- Sidebar Area: send a test message through the transport above --}}
                <div class="col-12 col-lg-4">
                    {{-- Sending mail to an address a user types is an abuse vector, so it is a
                         separate permission from manage rather than a subset of it (P9-D1). --}}
                    @can('notifications.send_test')
                        <form method="POST" action="{{ $sendTestUrl }}">
                            @csrf
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-header bg-transparent border-bottom py-3">
                                    <h5 class="card-title mb-0 fw-semibold">Send Test Mail</h5>
                                </div>
                                <div class="card-body p-4">
                                    <p class="text-muted fs-7">
                                        Sends a single message through the transport configured on the left.
                                        Use it to confirm the server accepts your credentials before relying on it.
                                    </p>
                                    <div class="mb-3">
                                        <label for="test_mail_email" class="form-label">Recipient</label>
                                        <input type="email" name="email" id="test_mail_email"
                                               class="form-control form-control-sm @error('email') is-invalid @enderror" @error('email') aria-invalid="true" aria-describedby="test_mail_email_error" @enderror
                                               value="{{ old('email') }}" placeholder="ops@example.com">
                                        @error('email')
                                            <div class="invalid-feedback d-block" id="test_mail_email_error">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                                <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
                                    <button type="submit" class="btn btn-outline-primary d-inline-flex align-items-center gap-2">
                                        <i class="bi bi-send"></i> Send Test
                                    </button>
                                </div>
                            </div>
                        </form>
                    @endcan
                </div>
            </div>
        @else
            {{-- notifications.view without notifications.manage. The editable form is NOT
                 rendered: leaving inputs on screen and hiding only Save would let someone
                 fill them in and discover on submit that nothing happened. Read-only means
                 the values are visible and there is nothing to fill. The server re-checks
                 regardless — see UpdateMailSettingsRequest. --}}
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3 d-flex justify-content-between align-items-center gap-2">
                    <h5 class="card-title mb-0 fw-semibold">Mail Configuration</h5>
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <span class="badge bg-secondary-subtle text-secondary">Read-only</span>
                        {{-- Same link, same treatment as the editable branch. It sits
                             on BOTH because gating it to the editable one would
                             leave a viewer — who is exactly the person with least
                             navigation elsewhere — unable to reach the channels page
                             from here. --}}
                        <a href="{{ route('notifications.channels') }}"
                           class="btn btn-primary btn-sm d-inline-flex align-items-center gap-2">
                            <i class="bi bi-tower-broadcast"></i> Channels
                        </a>
                    </div>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted fs-7">You can view these settings but not change them.</p>
                    <div class="table-responsive">
                        <table class="table table-fixed align-middle mb-0">
                            <colgroup>
                                <col style="width: 34%">
                                <col>
                            </colgroup>
                            <thead>
                                <tr>
                                    <th scope="col" class="align-middle">Setting</th>
                                    <th scope="col" class="align-middle">Current value</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($settings as $key => $value)
                                    <tr>
                                        <td class="text-muted"><code>{{ $key }}</code></td>
                                        <td>{{ $value === '' ? '—' : $value }}</td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td class="text-muted"><code>mail_password</code></td>
                                    <td>{{ $hasPassword ? 'Stored' : 'Not set' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endcan
    @endcan
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));
        });
    </script>
@endpush
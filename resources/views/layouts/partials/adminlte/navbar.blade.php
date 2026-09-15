@php
    /**
     * AdminLTE 4 default navbar.
     *
     * This partial is intentionally thin — no business logic lives here.
     * All dynamic data must be passed from the controller or composed via
     * view composers at the application layer, not fetched inside the view.
     */
@endphp

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="{{ url('/') }}">
            {{ config('app.name', 'Laravel') }}
        </a>
    </div>
</nav>

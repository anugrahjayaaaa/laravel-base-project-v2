{{--
    The bell's recent list, as its own partial (Phase 9, extracted for the poll).

    Two callers and one renderer:
    - `layouts/partials/notification-dropdown` includes it inside the dropdown;
    - `GET /notifications/preview` returns it as an HTML fragment, and the poll
      script swaps it in when a NEW notification has arrived.

    ## Why the fragment comes from the server rather than being built in JS

    A second copy of this markup in JavaScript is a second renderer: it drifts the
    first time a badge colour or a timestamp format changes, and the drift is
    invisible until the two disagree on a screen. Asking the server for the same
    partial means there is exactly one of them. The poll only asks for this
    fragment WHEN something new arrived — the common case updates the badge number
    alone and never fetches.

    Data comes from the composer registered against BOTH this partial and the
    header (see `AppServiceProvider`), which is what lets the endpoint render it
    with no controller-supplied view data.
--}}
<div class="list-group list-group-flush overflow-auto" style="max-height: 320px;" id="notification-preview-list">
    @forelse ($recentNotifications as $notification)
        @php
            $type = $notification->data['type'] ?? '';
            $parts = explode('\\', $type);
            $class = end($parts) ?: '';
            $color = match ($class) {
                'UserCreatedNotification', 'UserRegisteredNotification', 'RegisterNotification' => 'primary',
                'ChangeEmailVerificationNotification' => 'info',
                'AccountStateChangedNotification', 'ConfigurationChangedNotification' => 'warning',
                'RolesChangedNotification' => 'success',
                default => 'secondary',
            };
            $circle = "bg-{$color}-subtle text-{$color}";
        @endphp
        {{-- The SPECIFIC notification, not the list. Every item used to point at
             `/notifications/inbox`, so clicking one dropped the reader into thirty
             rows with nothing marking which one they chose — and the row stayed
             unread, so "I clicked it" and "I read it" were two different clicks.
             The detail page marks it read. --}}
        <a href="{{ route('notifications.inbox.show', $notification->id) }}"
            class="notifications-preview-item list-group-item list-group-item-action py-3 px-3 border-bottom d-flex align-items-start gap-3 bg-body-tertiary">
            <div class="rounded-3 {{ $circle }} p-2" style="width: 36px; height: 36px; flex-shrink: 0;">
                @if ($notification->read_at === null)
                    <i class="bi bi-check2-circle fs-6"></i>
                @else
                    <i class="bi bi-check fs-6"></i>
                @endif
            </div>
            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold small text-body mb-1">
                    {{ $notification->data['subject'] ?? 'Notification' }}
                </div>
                <div class="text-muted small">
                    <i class="bi bi-clock me-1"></i> {{ $notification->created_at->diffForHumans() }}
                </div>
            </div>
        </a>
    @empty
        <div class="dropdown-item-text text-muted py-3 text-center">
            No notifications yet.
        </div>
    @endforelse
</div>

{{--
    Keeps the bell's count and list current without a reload (Phase 9 follow-up).

    ## Why this is JavaScript and not Blade

    A Blade page is rendered once per request. The number in the header is true
    for that request and goes stale the moment the next notification lands — which
    is why the count "only appears after a reload". Something has to ask the
    server again, and the two ways are polling and push.

    Polling is the one that fits this project: a push channel (Reverb, Pusher,
    WebSockets) is infrastructure, a daemon, and a new deployment surface for a
    badge. A poll is one GET every 30 seconds per open tab, and the endpoint
    answers from the cache that already exists (`UnreadNotificationCount`).

    ## Three decisions that are not obvious

    **30 seconds, and only while the tab is visible.** `document.hidden` is true
    for a background tab, a minimised window and a locked screen — the exact
    moments where nobody is looking at a badge. Polling there is the same cost for
    no reader.

    **The badge element always exists.** Server-side it is rendered `hidden` when
    the count is zero, but it is never removed from the DOM: the script needs
    something to UNHIDE. A badge that only appears when non-zero cannot be shown
    by script that has nothing to show.

    **The list is only re-rendered when something NEW arrived.** The endpoint
    returns `latest_id` alongside the count; when it is unchanged the script
    updates the number and nothing else. When it has changed, the script asks for
    the SAME Blade partial as an HTML fragment and swaps it in — so there is one
    renderer, not a second copy of this markup in JavaScript.

    ## Failure is silent and backs off

    A poll that keeps retrying an endpoint which is down, rate-limited, or
    unreachable turns a cosmetic feature into a load generator. One failure stops
    the loop for five minutes; the next page load picks it up again.

    ## No CSRF token is sent

    The endpoints are GETs and read one authenticated user's own count, so there
    is no state to forge — which is also why the CSRF exemption a POST would need
    is not here at all.
--}}
<script>
    (function () {
        var bell = document.getElementById('notification-bell');

        if (!bell) {
            return;
        }

        var badge = document.getElementById('notification-badge');
        var list = document.getElementById('notification-preview-list');
        var interval = 30000;
        var backoffUntil = 0;

        // The newest id on screen, read from the rendered rows rather than tracked
        // in a variable, so there is no third copy of "what is the latest one" to
        // keep in step: the DOM already knows.
        //
        // `data-notification-id`, NOT `href`. This read `href` — which is
        // "/notifications/inbox/<uuid>" — and compared it against the endpoint's
        // `latest_id`, which is the bare "<uuid>". Those never matched, so the
        // check below fired on every poll and the list was re-fetched from the
        // server every 30 seconds for the life of every open tab. It only ever
        // agreed while the inbox was empty, when both sides were null.
        function latestId() {
            if (!list) {
                return null;
            }

            var item = list.querySelector('[data-notification-id]');
            return item ? item.getAttribute('data-notification-id') : null;
        }

        function render(count) {
            var label = count > 99 ? '99+' : String(count);

            if (badge) {
                badge.textContent = label;
                // The empty flag is what the server rendered, and the script must
                // agree with it — reading it back means the two renderers cannot
                // disagree about whether the badge is showing.
                badge.hidden = count < 1;
                badge.dataset.empty = count < 1 ? 'true' : 'false';
            }

            bell.setAttribute('aria-label', 'Notifications, ' + count + ' unread');
        }

        function refreshList() {
            if (!list) {
                return Promise.resolve();
            }

            return fetch('{{ route('notifications.preview') }}', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.ok ? response.text() : Promise.reject(response);
                })
                .then(function (html) {
                    list.outerHTML = html;
                    list = document.getElementById('notification-preview-list');
                })
                .catch(function () { /* the badge is still correct; the list waits for the next page */ });
        }

        function poll() {
            if (document.hidden || Date.now() < backoffUntil) {
                return;
            }

            fetch('{{ route('notifications.unread-count') }}', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('status ' + response.status);
                    }

                    return response.json();
                })
                .then(function (payload) {
                    render(parseInt(payload.count, 10) || 0);

                    // Only touch the DOM list when the newest notification differs.
                    // Re-rendering it every 30 seconds would fight the dropdown's own
                    // open/close state and replace elements the user may be hovering.
                    if (payload.latest_id !== latestId()) {
                        refreshList();
                    }
                })
                .catch(function () {
                    // One failure is enough to stop: a poll that retries into a 429 or
                    // a dead endpoint is a feature turning into a load generator.
                    backoffUntil = Date.now() + 300000;
                });
        }

        window.setInterval(poll, interval);

        // The count is also refreshed the moment somebody comes back to the tab:
        // a notification that arrived while it was hidden would otherwise wait out
        // the remainder of the interval.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                poll();
            }
        });

        // "Ask now, don't wait out the interval."
        //
        // 30 seconds is the right cadence for an AMBIENT badge — a notification
        // can arrive at any moment and nobody is waiting on it. It is the wrong
        // cadence for a feature that just CAUSED one: an export finishing emits a
        // notification within seconds, and the operator is sitting there watching
        // the very page that produced it. Lowering the interval globally would
        // make every admin page pay for that one case, six times as often.
        //
        // So the notification is the one that answers, not the interval: any
        // feature that causes a notification dispatches this event when the cause
        // resolves, and the badge is re-read once, immediately. Named after the
        // capability rather than the caller — the first caller is the export
        // table, but nothing about the event says "export".
        document.addEventListener('notifications:refresh', function () {
            poll();
        });
    })();
</script>

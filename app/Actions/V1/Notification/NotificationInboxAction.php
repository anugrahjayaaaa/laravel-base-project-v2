<?php

namespace App\Actions\V1\Notification;

use App\Models\User;
use App\Support\UnreadNotificationCount;
use Illuminate\Support\Str;

/** The viewer's own notifications, and the two ways they clear them. */
class NotificationInboxAction
{
    /**
     * The viewer's notifications, newest first, plus the unread count.
     *
     * The count comes from the cache the bell reads, not from a live query, and
     * that is the point: the two surfaces are the same number about the same
     * inbox. Counting live here meant that a row written between the header's
     * read and this one left the page header showing one figure and the page
     * body showing another, with nothing on screen to explain the difference.
     *
     * Safe for the same reason the bell's is: delivery invalidates on
     * `NotificationSent`, and both mark-read paths below invalidate here.
     *
     * ## `actions` — the call to action, resolved here rather than in the view
     *
     * Keyed by notification id, and EMPTY for every notification that does not
     * carry one. The inbox is generic over notification classes, so a notification
     * that finishes an action the viewer has to take — a generated file, a
     * confirmation — states it in `data['url']`, and this is where that becomes a
     * button.
     *
     * Resolving it in the Action is the security control, not an aesthetic
     * preference: `data` is a JSON column that whatever wrote the notification
     * controls, and `data['url']` lands in an `href`. A payload carrying
     * `javascript:alert(1)` or `//evil.example` would otherwise be one click
     * away from executing. Only a same-origin absolute path survives; anything
     * else yields no entry at all, which renders as an ordinary notification.
     *
     * The row itself is left untouched — this adds a control, it does not make
     * the row a link, because "mark as read" is a POST and a GET that changes
     * state is its own defect.
     *
     * @return array{notifications: \Illuminate\Contracts\Pagination\LengthAwarePaginator, unreadCount: int, actions: array<string, array{url: string, label: string}>}
     */
    public function inbox(User $viewer): array
    {
        $notifications = $viewer
            ->notifications()
            ->latest()
            ->paginate(30);

        return [
            'notifications' => $notifications,
            'unreadCount' => UnreadNotificationCount::for($viewer),
            'actions' => $this->actionsFor($notifications),
        ];
    }

    /**
     * The call to action for one notification, or null when it has none.
     *
     * Extracted from the map below so the detail page and the inbox cannot drift
     * on what counts as a safe link: both call THIS.
     *
     * `data` is a JSON column whatever wrote the notification controls, and this
     * URL reaches an `href`. A payload carrying `javascript:alert(1)` or
     * `//evil.example` would otherwise be one click from executing, so only a
     * same-origin absolute path survives — `//host` has the syntax of a path and
     * the destination of a link somewhere else.
     *
     * @return array{url: string, label: string}|null
     */
    private function actionFor(mixed $notification): ?array
    {
        $url = $notification->data['url'] ?? null;

        if (! is_string($url) || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }

        return [
            'url' => $url,
            // The label is the notification's own words — "Download CSV" rather
            // than a generic "Open" — length-bounded because it comes out of a
            // JSON column like everything else in `data`.
            'label' => Str::limit(trim((string) ($notification->data['action_label'] ?? '')), 40) ?: 'Open',
        ];
    }

    /**
     * The call-to-action for each notification on this page that has one.
     *
     * Scoped to the PAGE, not to the whole inbox: the map is read per row, and a
     * view that only ever renders 30 of them has no reason to make the other 4000
     * carry a decision this page cannot show.
     *
     * @param  iterable<int, \Illuminate\Notifications\DatabaseNotification>  $notifications
     * @return array<string, array{url: string, label: string}>
     */
    private function actionsFor(iterable $notifications): array
    {
        $actions = [];

        foreach ($notifications as $notification) {
            $action = $this->actionFor($notification);

            if ($action !== null) {
                $actions[(string) $notification->getKey()] = $action;
            }
        }

        return $actions;
    }

    /**
     * One notification, opened — and read, because that is what opening one is.
     *
     * The bell and the inbox both linked every row at the inbox, so "the notification
     * I clicked" was indistinguishable from "all the notifications". Opening one now
     * goes to one.
     *
     * ## Reading on open is a GET that writes, and it is the right trade here
     *
     * The alternative — a separate "mark as read" click — makes reading two clicks
     * and leaves an inbox that fills with things the user has demonstrably read. The
     * risk a GET-with-side-effect carries is a prefetch or a crawler marking rows
     * read without a human, so the write is bounded: it touches ONE row the viewer
     * owns, it is idempotent (a read row is left alone), and losing it costs a
     * re-click rather than data. A prefetched inbox with N rows pre-read would be a
     * problem; a prefetched detail page marks one row read.
     */
    public function show(User $viewer, string $id): array
    {
        $notification = $viewer->notifications()->whereKey($id)->first();

        // 404, not 403: the inbox carries no permission, so a wrong id here is
        // "no such notification for you" and a 403 would confirm the row exists.
        if ($notification === null) {
            abort(404, 'Notification not found.');
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();

            // The dropdown sits under the badge and caches the same rows this page
            // just changed; leaving that snapshot stale is how a row stays bold
            // after being read.
            UnreadNotificationCount::forgetAllFor($viewer);
        }

        // `callToAction`, NOT `action`: these keys become view data, and
        // `layouts/partials/modal` reads `$confirmLabel ?? $action` from the
        // layout scope. A view variable named `action` replaces that object with
        // this array and 500s every page that renders the modal.
        return [
            'notification' => $notification->fresh(),
            'callToAction' => $this->actionFor($notification),
        ];
    }

    /** Mark one of the viewer's notifications read. */
    public function markAsRead(User $viewer, string $id): bool
    {
        $notification = $viewer->notifications()->whereKey($id)->first();

        if ($notification === null || $notification->read_at !== null) {
            return false;
        }

        $notification->markAsRead();

        // Both cached views, not just the count: the dropdown list sits under
        // the badge, and a row that stays bold because only the count was
        // cleared is a read notification that still claims to be unread.
        UnreadNotificationCount::forgetAllFor($viewer);

        return true;
    }

    /** Mark every one of the viewer's unread notifications read. */
    public function markAllAsRead(User $viewer): int
    {
        $marked = $viewer->unreadNotifications()->update(['read_at' => now()]);
        UnreadNotificationCount::forgetAllFor($viewer);

        return $marked;
    }
}

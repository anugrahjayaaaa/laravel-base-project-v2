<?php

namespace App\Actions\V1\Notification;

use App\Support\UnreadNotificationCount;
use App\Models\User;

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
     */
    public function inbox(User $viewer): array
    {
        return [
            'notifications' => $viewer
                ->notifications()
                ->latest()
                ->paginate(30),
            'unreadCount' => UnreadNotificationCount::for($viewer),
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

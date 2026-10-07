<?php

namespace App\Actions\V1\Notification;

use App\Support\UnreadNotificationCount;
use App\Models\User;

/** The viewer's own notifications, and the two ways they clear them. */
class NotificationInboxAction
{
    /** The viewer's notifications, newest first, plus the unread count. */
    public function inbox(User $viewer): array
    {
        return [
            'notifications' => $viewer
                ->notifications()
                ->latest()
                ->paginate(30),
            'unreadCount' => $viewer->unreadNotifications()->count(),
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
        UnreadNotificationCount::forget($viewer);

        return true;
    }

    /** Mark every one of the viewer's unread notifications read. */
    public function markAllAsRead(User $viewer): int
    {
        $marked = $viewer->unreadNotifications()->update(['read_at' => now()]);
        UnreadNotificationCount::forget($viewer);

        return $marked;
    }
}

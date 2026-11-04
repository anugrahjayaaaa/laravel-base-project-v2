<?php

namespace App\Actions\V1\Notification;

use App\Support\NotificationAudience;
use App\Models\User;
use App\Notifications\ConfigurationChangedNotification;
use App\Notifications\RolesChangedNotification;
use App\Notifications\UserRegisteredNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Announce an administrative event to the audience the Target Audience Rule defines for it. */
class NotificationAdminEventAction
{
    /** A user's roles or permissions changed — notify THAT user only. */
    public function rolesChanged(User $user, ?User $causer = null, array $added = [], array $removed = []): void
    {
        if ($added === [] && $removed === []) {
            return;
        }

        $this->dispatch(
            Collection::make([$user]),
            new RolesChangedNotification($user, $causer, $added, $removed)
        );
    }

    /**
     * A new account exists — notify every `users.create` holder.
     *
     * The account itself is not a recipient. It already receives
     * `RegisterNotification` or `UserCreatedNotification`, both of which carry
     * the verification link; a second, status-only "Registration received" row
     * beside the one with the action was noise, so the owner removed it.
     */
    public function userRegistered(User $user, ?User $causer = null): void
    {
        $this->dispatch(
            NotificationAudience::forEvent('user.registered'),
            new UserRegisteredNotification($user, $causer)
        );
    }

    /** Configuration changed — notify everyone who could have changed it. */
    public function configurationChanged(string $event, ?string $detail = null, ?User $causer = null): void
    {
        $this->dispatch(
            NotificationAudience::forEvent($event),
            new ConfigurationChangedNotification($event, $detail, $causer)
        );
    }

    /** Send to a resolved set, once, swallowing a delivery failure. */
    private function dispatch(Collection $recipients, mixed $notification): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, $notification);
        } catch (Throwable $e) {
            Log::error('Administrative notification failed', [
                'notification' => $notification::class,
                'recipients' => $recipients->count(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

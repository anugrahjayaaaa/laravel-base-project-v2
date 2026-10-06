<?php

namespace App\Actions\V1\Notification;

use App\Models\NotificationAudience;
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

    /** A new account exists — notify every `users.create` holder and the account. */
    public function userRegistered(User $user, ?User $causer = null): void
    {
        $this->dispatch(
            $this->recipients('user.registered', $user),
            new UserRegisteredNotification($user, $causer)
        );
    }

    /** Configuration changed — notify everyone who could have changed it. */
    public function configurationChanged(string $event, ?string $detail = null, ?User $causer = null): void
    {
        $this->dispatch(
            $this->recipients($event),
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

    /** The audience for an event, plus an extra recipient, deduplicated. */
    private function recipients(string $event, ?User $also = null): Collection
    {
        $recipients = NotificationAudience::forEvent($event);

        if ($also !== null) {
            $recipients = $recipients->push($also);
        }

        return $recipients
            ->unique(fn (User $user): int|string => $user->getKey())
            ->values();
    }
}

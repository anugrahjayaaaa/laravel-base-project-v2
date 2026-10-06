<?php

namespace App\Models;

use Illuminate\Support\Facades\Cache;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Eloquent\Collection;

class UnreadNotificationCount
{
    private const RECENT_LIMIT = 5;

    public static function for(User $user): int
    {
        return (int) Cache::remember(
            self::key($user->getKey()),
            now()->addMinutes(5),
            fn (): int => $user->unreadNotifications()->count()
        );
    }

    public static function forget(User|int $user): void
    {
        $id = $user instanceof User ? $user->getKey() : $user;
        Cache::forget(self::key($id));
        Cache::forget(self::recentKey($id, self::RECENT_LIMIT));
    }

    public static function recent(User $user, ?int $limit = null): Collection
    {
        $limit ??= self::RECENT_LIMIT;

        // Cache the IDs only, not the model collection: a serialized Eloquent
        // Collection can resurrect as __PHP_Incomplete_Class when unserialized
        // in a process whose autoloader is not warm (queue workers, opcache
        // restarts), which throws a TypeError against the Collection return
        // type and 500s the page. Stocking ints survives any cache driver.
        $ids = Cache::remember(
            self::recentKey($user->getKey(), $limit),
            now()->addMinutes(5),
            fn (): array => $user->notifications()->latest()->limit($limit)->pluck('id')->all()
        );

        return $user->notifications()->whereIn('id', $ids)->latest()->get();
    }

    private static function key(int $userId): string
    {
        return 'notifications:unread:'.$userId;
    }

    private static function recentKey(int $userId, int $limit): string
    {
        return 'notifications:recent:'.$userId.':'.$limit;
    }

    public static function listen(): void
    {
        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            $notifiable = $event->notifiable;
            if ($notifiable instanceof User) {
                self::forget($notifiable);
            }
        });
    }
}

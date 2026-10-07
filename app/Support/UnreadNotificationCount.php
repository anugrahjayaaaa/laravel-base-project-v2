<?php

namespace App\Support;

use App\Models\User;
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

        // Cache the full collection, not IDs: `recent()` is read on every
        // authenticated render of the header bell dropdown, and a hydration
        // query after a cache hit defeats the cache. The earlier IDs-only
        // version kept that second query on every request even when warm.
        //
        // Stored as hydrated models, so the cache entry is read-only on the
        // collection — no `refresh()` is ever called on it.
        //
        // ponytail: a serialized Eloquent Collection on a driver that outlives
        // the process (redis) can unserialize as __PHP_Incomplete_Class if its
        // autoloader is cold, which throws a TypeError on the Collection return
        // type. The array + file drivers never hit this; redis/opcache in
        // production keeps models autoloaded, so the hazard is a stale worker
        // restarted without `composer dump-autoload`. Accepted for now: one
        // query on a truly cold cache beats a query on every render.
        return Cache::remember(
            self::recentKey($user->getKey(), $limit),
            now()->addMinutes(5),
            fn (): Collection => $user->notifications()->latest()->limit($limit)->get()
        );
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

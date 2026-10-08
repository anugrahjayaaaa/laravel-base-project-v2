<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use stdClass;

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
    }

    /**
     * The five most recent notifications for the bell dropdown.
     *
     * Cached as a plain array, not an Eloquent Collection: a serialized
     * Collection stored in the Redis driver unserializes to
     * __PHP_Incomplete_Class in any process whose autoloader has not
     * registered the framework model class yet (a queue worker restarted
     * without `composer dump-autoload`, a stale Octane worker, the long-
     * running `queue:work` that this app runs). The count stays as an int
     * (universally safe to cache); this reconstitutes lightweight stdClass
     * rows so no second query is paid on a warm cache.
     */
    public static function recent(User $user, ?int $limit = null): Collection
    {
        $limit ??= self::RECENT_LIMIT;

        return self::materialize(
            Cache::remember(
                self::recentKey($user->getKey(), $limit),
                now()->addMinutes(5),
                fn (): array => $user->notifications()->latest()->limit($limit)->get()->map(
                    fn ($n) => [
                        'id' => $n->id,
                        'data' => $n->data,
                        'read_at' => $n->read_at,
                        'created_at' => $n->created_at->toDateTimeString(),
                    ]
                )->all()
            )
        );
    }

    /**
     * Drop every cached view of this user's notifications.
     *
     * BOTH the count and the recent list, and both are read from the same page:
     * the badge is the count, the dropdown below it is `recent()`. Clearing only
     * the count is the worst version of this bug — the badge updates the instant
     * a notification arrives and the list next to it still shows the previous
     * five, which reads as the delivery having failed. `forget()` was clearing
     * the count alone for exactly that long.
     *
     * The list is keyed by limit, so there is no single key to forget. The
     * default limit covers the one caller; a future caller passing a different
     * limit must be added here, or its list goes stale until the TTL expires.
     */
    public static function forgetAllFor(User|int $user): void
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        self::forget($id);
        Cache::forget(self::recentKey($id, self::RECENT_LIMIT));
    }

    /**
     * Build a Collection of stdClass rows from cached arrays. No query — the
     * caller already has the data serialized as primitives.
     */
    private static function materialize(array $rows): Collection
    {
        $items = array_map(
            static function (array $r): stdClass {
                $n = new stdClass();
                $n->id = $r['id'];
                $n->data = $r['data'];
                $n->read_at = $r['read_at'];
                $n->created_at = Carbon::parse($r['created_at']);
                return $n;
            },
            $rows
        );

        return new Collection($items);
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
                self::forgetAllFor($notifiable);
            }
        });
    }
}

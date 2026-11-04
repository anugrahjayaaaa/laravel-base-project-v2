<?php

namespace Tests\Unit\Support;

use App\Models\User;
use App\Support\UnreadNotificationCount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Tests\TestCase;

/**
 * The bell dropdown reads two cached values — a count and a recent list — and
 * both are invalidated from one place.
 *
 * Two failure modes are pinned here, and both are silent:
 *
 * 1. **Only the count is cleared.** The badge updates the instant a
 *    notification arrives and the list beside it still shows the previous five,
 *    which reads as the delivery having failed. `forget()` was clearing the
 *    count alone for exactly that long.
 * 2. **The list is cached as an Eloquent model.** A Collection stored through
 *    the Redis driver unserializes to `__PHP_Incomplete_Class` in any process
 *    whose autoloader has not registered the model class — a restarted worker,
 *    a stale Octane process. The list is cached as primitives and rebuilt.
 */
class UnreadNotificationCountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // The array store is process-wide and RefreshDatabase rolls back the
        // database, not the cache — a user id reused next test would read the
        // previous test's count.
        Cache::flush();

        $this->user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
    }

    private function notify(?Carbon $createdAt = null, bool $read = false): string
    {
        $id = (string) Str::uuid();
        $at = $createdAt ?? now();

        $this->user->notifications()->create([
            'id' => $id,
            'type' => 'App\Notifications\TestNotification',
            'data' => ['url' => '/x', 'action_label' => 'View'],
            'read_at' => $read ? $at : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $id;
    }

    public function test_the_count_is_the_unread_rows_only(): void
    {
        $this->notify();
        $this->notify();
        $this->notify(read: true);

        $this->assertSame(2, UnreadNotificationCount::for($this->user));
    }

    /**
     * The count is a cache read, not a query per render: without the cache the
     * sidebar pays a COUNT on every page. `forget()` is the documented way to
     * make it see a change immediately.
     */
    public function test_the_count_is_cached_until_it_is_forgotten(): void
    {
        $this->notify();
        $this->assertSame(1, UnreadNotificationCount::for($this->user));

        $this->notify();

        $this->assertSame(1, UnreadNotificationCount::for($this->user), 'the count is not cached at all');

        UnreadNotificationCount::forget($this->user->id);

        $this->assertSame(2, UnreadNotificationCount::for($this->user), 'forget() did not clear the count');
    }

    /**
     * The list is five newest rows — newest first, all five, as plain objects.
     * `instanceof Model` is the assertion that matters: an Eloquent model here
     * is what becomes `__PHP_Incomplete_Class` in a worker that restarted.
     */
    public function test_recent_returns_the_newest_five_as_lightweight_rows(): void
    {
        $ids = [];

        for ($i = 0; $i < 6; $i++) {
            $ids[] = $this->notify(now()->subMinutes(10 - $i));
        }

        $recent = UnreadNotificationCount::recent($this->user);

        $this->assertCount(5, $recent);
        $this->assertSame(
            [$ids[5], $ids[4], $ids[3], $ids[2], $ids[1]],
            $recent->pluck('id')->all(),
            'the list is not the five newest, newest first'
        );

        foreach ($recent as $row) {
            $this->assertInstanceOf(stdClass::class, $row);
            $this->assertNotInstanceOf(Model::class, $row);
            $this->assertInstanceOf(Carbon::class, $row->created_at);
            $this->assertSame('/x', $row->data['url']);
        }
    }

    public function test_the_limit_is_honoured_when_one_is_given(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->notify(now()->subMinutes(4 - $i));
        }

        $this->assertCount(2, UnreadNotificationCount::recent($this->user, 2));
    }

    public function test_latest_id_is_null_when_the_inbox_is_empty(): void
    {
        $this->assertNull(UnreadNotificationCount::latestIdFor($this->user));
        $this->assertCount(0, UnreadNotificationCount::recent($this->user));
    }

    public function test_latest_id_is_the_newest_id(): void
    {
        $this->notify(now()->subMinute());
        $newest = $this->notify();

        $this->assertSame($newest, UnreadNotificationCount::latestIdFor($this->user));
    }

    /**
     * The poll endpoint calls this every 30 seconds per open tab. A second
     * uncached query here is a query per tab per interval that the list right
     * beside it already paid for.
     */
    public function test_latest_id_reads_the_cached_list_instead_of_querying_again(): void
    {
        $inbox = $this->notify();
        UnreadNotificationCount::recent($this->user); // warm

        DB::flushQueryLog();
        DB::enableQueryLog();
        // Also the proof the value came from the warm cache, not a fresh read.
        $this->assertSame($inbox, UnreadNotificationCount::latestIdFor($this->user));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $this->assertStringNotContainsString(
                'notifications',
                $query['query'],
                'latestIdFor() queried the inbox while the cached list was already warm'
            );
        }
    }

    /**
     * The documented bug, pinned from the outside: clearing only the count
     * leaves the badge and the list beside it disagreeing.
     */
    public function test_forget_all_clears_the_count_and_the_list_together(): void
    {
        $first = $this->notify(now()->subMinute());

        $this->assertSame(1, UnreadNotificationCount::for($this->user));
        $this->assertSame($first, UnreadNotificationCount::latestIdFor($this->user));

        $second = $this->notify();

        UnreadNotificationCount::forgetAllFor($this->user);

        $this->assertSame(2, UnreadNotificationCount::for($this->user), 'the count was not cleared');
        $this->assertSame(
            $second,
            UnreadNotificationCount::latestIdFor($this->user),
            'the recent list was not cleared — the badge moved and the dropdown beside it did not'
        );
    }

    /**
     * Both invalidations are wired to the one event the framework raises when a
     * notification is actually sent, so a new channel cannot forget to call
     * `forgetAllFor()` itself.
     */
    public function test_a_sent_notification_clears_both_caches_through_the_listener(): void
    {
        $notification = new class () extends Notification {
            /**
             * @return array<int, string>
             */
            public function via(object $notifiable): array
            {
                return ['database'];
            }
        };

        $this->notify(now()->subMinute());
        $this->assertSame(1, UnreadNotificationCount::for($this->user));

        $newest = $this->notify();

        event(new NotificationSent($this->user, $notification, 'database'));

        $this->assertSame(2, UnreadNotificationCount::for($this->user), 'the listener did not clear the count');
        $this->assertSame(
            $newest,
            UnreadNotificationCount::latestIdFor($this->user),
            'the listener did not clear the recent list'
        );
    }
}

<?php

namespace Tests\Unit\Actions;

use App\Actions\V1\BulkAction\BulkActionHandler;
use App\Actions\V1\BulkAction\BulkActionProcessor;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

/**
 * The bulk orchestrator, driven through a stub handler.
 *
 * The handler is a double because the processor's job is the contract around
 * one: filter, execute, sweep sessions, drop the cache, all inside the one
 * transaction that makes a partial bulk run impossible.
 */
class BulkActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_selection_reports_nothing_and_executes_nothing(): void
    {
        $handler = $this->handler();

        $result = app(BulkActionProcessor::class)->run('lock', [9999], User::factory()->create(), $handler);

        $this->assertSame(0, $result['count']);
        $this->assertSame('No valid items', $result['label']);
        $this->assertSame([], $handler->executed);
    }

    public function test_valid_items_are_executed_with_sessions_and_cache_swept(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        // A spy, not a partial mock: creating a user also drops a cache key, and
        // a strict expectation would reject that unrelated call.
        Cache::spy();

        $handler = $this->handler(
            sessionActions: ['lock'],
            cacheKeys: ['users:index'],
        );

        $result = app(BulkActionProcessor::class)->run(
            'lock',
            [$first->id, $second->id],
            User::factory()->create(),
            $handler,
        );

        $ids = [$first->id, $second->id];

        $this->assertSame(2, $result['count']);
        $this->assertSame($ids, $handler->executed[0][1]);
        $this->assertSame([$ids], $handler->sessionInvalidated);
        Cache::shouldHaveReceived('forget')->with('users:index')->once();
    }

    public function test_an_action_outside_the_invalidation_list_leaves_sessions_alone(): void
    {
        $user = User::factory()->create();

        $handler = $this->handler(sessionActions: ['lock']);

        app(BulkActionProcessor::class)->run('activate', [$user->id], User::factory()->create(), $handler);

        $this->assertSame([], $handler->sessionInvalidated);
    }

    public function test_a_failure_mid_run_rolls_back_the_work_already_done(): void
    {
        $user = User::factory()->create(['name' => 'Original']);

        $handler = $this->handler(execute: function (array $ids) use ($user): void {
            $user->update(['name' => 'Changed']);

            throw new RuntimeException('the second item failed');
        });

        try {
            app(BulkActionProcessor::class)->run('lock', [$user->id], User::factory()->create(), $handler);
            $this->fail('The processor swallowed the handler failure.');
        } catch (RuntimeException) {
            // Expected: the first item's write must not survive a failure on a
            // later one, which is what the processor's transaction buys.
        }

        $this->assertSame('Original', $user->fresh()->name);
    }

    /**
     * @param  array<int, string>  $sessionActions
     * @param  array<int, string>  $cacheKeys
     */
    private function handler(
        array $sessionActions = [],
        array $cacheKeys = [],
        ?\Closure $execute = null,
    ): BulkActionHandler {
        return new class ($sessionActions, $cacheKeys, $execute) implements BulkActionHandler {
            /** @var array<int, array{0: string, 1: array<int, int>}> */
            public array $executed = [];

            /** @var array<int, array<int, int>> */
            public array $sessionInvalidated = [];

            public function __construct(
                private readonly array $sessionActions,
                private readonly array $cacheKeys,
                private readonly ?\Closure $execute,
            ) {
            }

            public function getValidItems(array $ids, string $action): Collection
            {
                return User::query()->whereIn('id', $ids)->get();
            }

            public function executeBulk(string $action, array $ids): void
            {
                $this->executed[] = [$action, $ids];

                if ($this->execute !== null) {
                    ($this->execute)($ids);
                }
            }

            public function getAuditEvent(string $action): string
            {
                return '';
            }

            public function getAuditLabel(string $action): string
            {
                return ucfirst($action).' complete';
            }

            public function getCacheKeys(): array
            {
                return $this->cacheKeys;
            }

            public function batchInvalidateSessions(array $ids): void
            {
                $this->sessionInvalidated[] = $ids;
            }

            public function getSessionInvalidationActions(): array
            {
                return $this->sessionActions;
            }
        };
    }
}

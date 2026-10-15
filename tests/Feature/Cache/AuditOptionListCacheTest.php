<?php

namespace Tests\Feature\Cache;

use App\Actions\V1\Audit\AuditIndexAction;
use App\Models\Concerns\Auditable;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The viewer's four filter option lists are cached; these are the read paths.
 *
 * ## What this file is for
 *
 * `test_a_new_event_name_reaches_the_cached_filter_dropdown` in
 * `AuditLogUiRenderTest` pins the INVALIDATION. It says nothing about the four
 * `Cache::remember` reads themselves: that each one returns the right content,
 * that a warm read costs no query, and that what lands in the store is a plain
 * array rather than a Collection or a model.
 *
 * That last one is not a formality. `UserIndexCountCacheTest` exists because
 * the count cache once held a value the Redis store returned as
 * `__PHP_Incomplete_Class`, and the failure appears as a TypeError three calls
 * away from the cache.
 *
 * ## Why every mutation is wrapped in a real transaction
 *
 * `AuditIndexAction::bustCache()` defers to `DB::afterCommit`, and
 * `RefreshDatabase` holds a transaction open for the whole test — so
 * `afterCommit` never fires unless the work happens inside a committed
 * `DB::transaction()`. Every test here wraps its mutation in one and asserts on
 * the CONTENT rather than on whether a key happens to be absent. Asserting the
 * key's absence would pass against an invalidation that never runs at all.
 *
 * @see \App\Actions\V1\Audit\AuditIndexAction::bustCache()
 */
class AuditOptionListCacheTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actor = User::factory()->create([
            'name' => 'Ana Auditor',
            'email_verified_at' => now(),
        ]);
        $this->actor->assignRole(RoleLookup::find('admin'));
    }

    private function action(): AuditIndexAction
    {
        return app(AuditIndexAction::class);
    }

    private function forgetKeys(): void
    {
        foreach (AuditIndexAction::cacheKeys() as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Write a row the way the application does.
     *
     * Through `Auditable::audit()` rather than a builder, because the bust is
     * wired into that method and nowhere else — a fixture that bypassed it
     * would leave the cache holding whatever was there before.
     *
     * @param  array<string, mixed>  $properties
     */
    private function record(string $event, array $properties = []): void
    {
        $subject = User::factory()->create();

        DB::transaction(function () use ($event, $properties, $subject): void {
            $subject->audit($event, $this->actor, $properties);
        });
    }

    /**
     * The four read paths return what they claim to.
     *
     * One test rather than four because the defect these guard against is the
     * same in all four — a cache that answers from a stale or mistyped key — and
     * four near-identical tests would read as four separate concerns.
     */
    public function test_each_option_list_returns_its_own_content(): void
    {
        $this->record('user.updated');
        $this->record('role.assigned', ['source' => 'system']);

        $this->forgetKeys();
        $action = $this->action();

        $events = $action->eventOptions();
        $this->assertContains('user.updated', $events);
        $this->assertContains('role.assigned', $events);

        // `source` is derived, so a caller-supplied `properties` value wins over
        // the derived one — which is exactly the case where a filter must not
        // lose an option.
        $sources = $action->sourceOptions();
        $this->assertContains('system', $sources);

        $types = $action->subjectTypeOptions();
        $this->assertNotEmpty($types, 'the subject-type list is empty');
        $this->assertContains(
            'user',
            array_keys($types),
            'the stored morph alias must survive as the option VALUE — a prettified value is a filter that returns nothing'
        );

        $causers = $action->causerOptions();
        $this->assertSame(
            [$this->actor->getKey() => 'Ana Auditor'],
            $causers,
            'the actor list is built from the rows that exist and maps id => name'
        );
    }

    /**
     * A warm read costs no query, and a cold one costs exactly one.
     *
     * The whole reason the lists are cached is that `sourceOptions()` is a
     * `DISTINCT json_extract` over the whole table — the one filter no index
     * can serve. If a future change made the cache miss, this is what notices.
     */
    public function test_a_warm_read_issues_no_query(): void
    {
        $this->record('user.updated');
        $this->forgetKeys();

        // Prime all four.
        $this->action()->eventOptions();
        $this->action()->sourceOptions();
        $this->action()->subjectTypeOptions();
        $this->action()->causerOptions();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->action()->eventOptions();
        $this->action()->sourceOptions();
        $this->action()->subjectTypeOptions();
        $this->action()->causerOptions();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            0,
            $count,
            'the option lists issued '.$count.' queries on a warm cache — they are being recomputed'
        );
    }

    /**
     * What lands in the store is a plain array.
     *
     * The Redis store cannot round-trip a Collection or an Eloquent model; it
     * returns `__PHP_Incomplete_Class` instead, and the TypeError surfaces at
     * the first `->get()` three calls from the cache.
     */
    public function test_the_cached_values_are_primitives(): void
    {
        $this->record('user.updated');
        $this->forgetKeys();

        $this->action()->eventOptions();
        $this->action()->sourceOptions();
        $this->action()->subjectTypeOptions();
        $this->action()->causerOptions();

        foreach (AuditIndexAction::cacheKeys() as $key) {
            $this->assertIsArray(Cache::get($key), $key.' is not an array');
            $this->assertNotInstanceOf(
                Collection::class,
                Cache::get($key),
                $key.' holds a Collection, which the Redis store cannot return'
            );
        }
    }

    /**
     * A first-time event name reaches a warm cache.
     *
     * The invalidation is the half of a cache that is easy to get wrong: the
     * lists are derived from the whole table rather than from a filter, so a
     * missed bust leaves an event that the page lists and the filter cannot
     * find. Asserted on content, per the class docblock.
     */
    public function test_a_new_event_name_reaches_a_warm_cache(): void
    {
        $this->record('user.updated');

        // Warm every list, then write inside a REAL transaction so afterCommit
        // actually fires.
        $this->assertContains('user.updated', $this->action()->eventOptions());

        $this->record('billing.invoice_paid');

        $this->assertContains(
            'billing.invoice_paid',
            $this->action()->eventOptions(),
            'the cached event list did not see a row written after it was warmed'
        );
    }

    /**
     * The bulk write path busts too.
     *
     * `auditBulk()` writes raw rows and does not go through `audit()`. A bust
     * wired into only one of the two is a bust that misses every bulk action —
     * and a bulk action is the write most likely to introduce a new event name.
     */
    public function test_a_bulk_write_reaches_a_warm_cache(): void
    {
        $subject = User::factory()->create();
        $this->assertSame([], $this->action()->eventOptions());

        DB::transaction(function () use ($subject): void {
            Auditable::auditBulk('report.exported', [['subject_id' => $subject->getKey()]], $this->actor);
        });

        $this->assertContains(
            'report.exported',
            $this->action()->eventOptions(),
            'auditBulk() wrote a row with a first-time event and did not bust the cache'
        );
    }
}

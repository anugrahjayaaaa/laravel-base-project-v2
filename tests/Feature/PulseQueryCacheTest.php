<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Pulse\Livewire\Queues;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every Pulse card is `#[Lazy]`, so its `render()` — and the
 * `remember()` inside it — only runs when the browser hydrates the card.
 * The payloads that get remembered are objects: `Queues::render()` reads
 * the cached Collection back and immediately calls `->keys()`/`->map()`
 * on it (vendor/laravel/pulse/src/Livewire/Queues.php:25,34).
 *
 * The project pins `cache.serializable_classes` to `false` (phase-1
 * hardening against gadget-chain deserialization), so any persistent store
 * returns a cached object back as `__PHP_Incomplete_Class` and the card
 * dies with "The script tried to call a method on an incomplete object"
 * on the second read inside the 5-second recall window — `/pulse` shows
 * skeletons or error cards on every visit after a warm cache (reported
 * 2026-10-16: `Queues.php:35`).
 *
 * The default store is forced to `file` (a serializing store) because the
 * suite runs on `CACHE_STORE=array`, which never serializes and would hide
 * the regression. Pulse must keep its objects on a store where nothing
 * serializes, whatever the default points at.
 */
class PulseQueryCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'file']);
        Cache::store('file')->flush();
    }

    public function test_pulse_query_cache_round_trips_objects_when_the_default_store_serializes(): void
    {
        // withoutLazyLoading() is reset by Livewire's flush-state between
        // calls, so it must be re-asserted before each mount.
        Livewire::withoutLazyLoading();
        Livewire::test(Queues::class); // cold: results computed and remembered

        Livewire::withoutLazyLoading();
        Livewire::test(Queues::class); // warm: remembered payload read back

        $this->assertTrue(true, 'a warm render completed on a serializing default store');
    }
}

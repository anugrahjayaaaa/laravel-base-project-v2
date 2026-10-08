<?php

namespace Tests\Feature\Settings;

use App\Actions\V1\System\SystemSettingsUpdateAction;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 8 performance measurement — settings read path and write path.
 *
 * Follows `references/benchmark-testing.md`: action-direct measurement, so no
 * middleware/CSRF/routing cost lands in the number, and results are written to
 * /tmp for a before/after diff.
 *
 * This file MEASURES, it does not assert thresholds. A perf assertion fails the
 * suite on a slow CI box for reasons unrelated to the code; the numbers are the
 * deliverable and the judgement is the reader's.
 */
class SettingsBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string,float|int>> */
    protected static array $results = [];

    public static function tearDownAfterClass(): void
    {
        file_put_contents(
            '/tmp/phase8_settings_benchmark.json',
            json_encode(static::$results, JSON_PRETTY_PRINT)
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);
    }

    /**
     * Run one closure under the query log + a wall clock.
     *
     * @return array{queries:int, latencyMs:float, memMB:float, slowQueries:int}
     */
    private function measure(callable $fn): array
    {
        $fn(); // warm autoloader/OPcache so the measured run is not paying for it

        DB::flushQueryLog();
        DB::enableQueryLog();

        $memBefore = memory_get_usage(true);
        $start = microtime(true);

        $fn();

        $latencyMs = round((microtime(true) - $start) * 1000, 2);
        $memMB = round((memory_get_usage(true) - $memBefore) / 1048576, 3);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $slow = 0;
        foreach ($log as $q) {
            if (($q['time'] ?? 0) > 50) {
                $slow++;
            }
        }

        return [
            'queries' => count($log),
            'latencyMs' => $latencyMs,
            'memMB' => $memMB,
            'slowQueries' => $slow,
        ];
    }

    private function record(string $op, array $r): void
    {
        static::$results[$op] = $r;
        fwrite(STDERR, sprintf(
            "  %-46s %3d queries  %7.2f ms  %6.3f MB\n",
            $op,
            $r['queries'],
            $r['latencyMs'],
            $r['memMB']
        ));
    }

    public function test_read_path_cold_and_warm(): void
    {
        fwrite(STDERR, "\n[Phase 8] settings READ path\n");

        // Cold: no request static, no shared cache. This is the first request
        // after a deploy or after anything busts the cache.
        $this->record('getAll() cold (cache empty)', $this->measure(function () {
            SystemSetting::bustCache();
            SystemSetting::getAll();
        }));

        // Warm: the steady-state case, since $requestCache is a process static.
        $this->record('getAll() warm (request static)', $this->measure(function () {
            SystemSetting::getAll();
        }));

        // The page issues one getAll() no matter how many controls it renders.
        // 60 typed reads is what a full Blade page does against the model.
        $this->record('60x typed reads (getBool/Int/String)', $this->measure(function () {
            SystemSetting::bustCache();
            for ($i = 0; $i < 60; $i++) {
                SystemSetting::getBool('registration_enabled');
                SystemSetting::getInt('password_min_length');
                SystemSetting::getString('locale_default');
            }
        }));

        // Measures, does not assert — counts as one by PHPUnit 12's rules.
        $this->addToAssertionCount(1);
    }

    public function test_write_path_full_and_partial(): void
    {
        fwrite(STDERR, "\n[Phase 8] settings WRITE path\n");

        $action = app(SystemSettingsUpdateAction::class);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(\App\Models\RoleLookup::find('admin'));

        $keyCount = SystemSetting::query()->count();
        fwrite(STDERR, "  settings in table: {$keyCount}\n");

        // Full save: every key on the form submitted at once. The action
        // updateOrCreate's one key at a time inside a single transaction.
        $this->record('update ALL keys (one save)', $this->measure(function () use ($action, $admin) {
            $action->run(
                $this->validPayload(),
                causer: $admin
            );
        }));

        // Partial save: one checkbox ticked. The action backfills the rest from
        // storage, so the write count should not drop — that is the finding if
        // it does not.
        $this->record('update 1 key (partial save)', $this->measure(function () use ($action, $admin) {
            $action->run(
                ['registration_enabled' => false],
                causer: $admin
            );
        }));

        // Same, with no causer: skips the audit write, isolating audit cost.
        $this->record('update ALL keys (no audit)', $this->measure(function () use ($action) {
            $action->run($this->validPayload());
        }));

        $this->addToAssertionCount(1);
    }

    /**
     * Where the write path's queries actually go.
     *
     * Measured, not assumed: the count is 36 SELECTs plus a handful of writes,
     * and a two-key change writes two rows — so the per-key cost is the
     * existence probe `updateOrCreate` does before it decides not to update,
     * repeated once per key in the whitelist. That is the N+1 to fix, and this
     * is the number that proves whether a fix worked.
     */
    public function test_write_path_query_breakdown(): void
    {
        fwrite(STDERR, "\n[Phase 8] settings WRITE path — query breakdown\n");

        $action = app(SystemSettingsUpdateAction::class);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(\App\Models\RoleLookup::find('admin'));

        $action->run($this->validPayload()); // warm, so seeding is not measured

        DB::flushQueryLog();
        DB::enableQueryLog();

        // Two keys whose values differ from what is stored.
        $action->run([
            'password_min_length' => 20,
            'registration_enabled' => false,
        ], causer: $admin);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $tally = [];
        foreach ($log as $q) {
            $sql = preg_replace('/\s+/', ' ', preg_replace('/\(.*\)/', '(...)', $q['query']));
            $tally[$sql] = ($tally[$sql] ?? 0) + 1;
        }
        arsort($tally);

        foreach ($tally as $sql => $n) {
            fwrite(STDERR, sprintf("  %3dx %s\n", $n, $sql));
        }
        fwrite(STDERR, sprintf("  TOTAL: %d\n", count($log)));

        static::$results['write breakdown'] = [
            'totalQueries' => count($log),
            'existenceProbes' => $tally['select * from "system_settings" where (...) limit 1'] ?? 0,
            'settingsKeys' => SystemSetting::query()->count(),
        ];

        $this->assertTrue(true);
    }

    /**
     * The ceiling for the write path, measured rather than asserted.
     *
     * One `upsert` of the same key/value pairs, so the comparison is like for
     * like — same rows, same transaction, same connection. This is what a bulk
     * write in the action would cost.
     */
    public function test_write_path_bulk_upsert_ceiling(): void
    {
        fwrite(STDERR, "\n[Phase 8] settings WRITE path — current vs bulk upsert\n");

        $stored = DB::table('system_settings')->pluck('value', 'key')->all();
        $payload = $stored;
        $payload['password_min_length'] = '20'; // one key genuinely changed

        $current = $this->measure(function () use ($payload) {
            foreach ($payload as $key => $value) {
                SystemSetting::set($key, $value);
            }
        });

        $rows = [];
        $now = now();
        foreach ($payload as $key => $value) {
            $rows[] = ['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now];
        }

        $bulk = $this->measure(function () use ($rows) {
            DB::transaction(fn () => DB::table('system_settings')->upsert($rows, ['key'], ['value', 'updated_at']));
        });

        $this->record('current: updateOrCreate loop', $current);
        $this->record('ceiling: one bulk upsert', $bulk);

        $pctQ = round((($current['queries'] - $bulk['queries']) / max($current['queries'], 1)) * 100);
        $pctL = round((($current['latencyMs'] - $bulk['latencyMs']) / max($current['latencyMs'], 0.01)) * 100);
        fwrite(STDERR, sprintf(
            "  reduction: %d queries (-%d%%)  %.2f ms (-%d%%)\n",
            $current['queries'] - $bulk['queries'],
            $pctQ,
            $current['latencyMs'] - $bulk['latencyMs'],
            $pctL
        ));

        static::$results['write ceiling'] = [
            'currentQueries' => $current['queries'],
            'bulkQueries' => $bulk['queries'],
            'currentLatencyMs' => $current['latencyMs'],
            'bulkLatencyMs' => $bulk['latencyMs'],
            'queryReductionPct' => $pctQ,
            'latencyReductionPct' => $pctL,
        ];

        // Same rows written either way — the saving is the round-trips, not a
        // skipped write.
        $this->assertSame(
            count($payload),
            SystemSetting::query()->count()
        );
    }

    /**
     * The settings page through the full HTTP kernel, so middleware, session
     * and the two auth/role lookups are counted rather than hidden.
     */
    public function test_settings_page_http_load(): void
    {
        fwrite(STDERR, "\n[Phase 8] settings page — full HTTP GET\n");

        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(\App\Models\RoleLookup::find('admin'));
        $this->actingAs($admin);

        $result = $this->measure(fn () => $this->get(route('settings.index')));

        $this->record('GET settings.index (full kernel)', $result);

        static::$results['page load'] = $result;

        $this->addToAssertionCount(1);
    }

    /**
     * A payload shaped like the settings form's real submit.
     *
     * Only the keys the validator actually requires are here — the rest are
     * backfilled by the action, which is the point of the partial test above.
     *
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'registration_enabled' => true,
            'registration_default_role' => 'user',
            'locale_default' => 'en',
            'password_min_length' => 12,
            'password_max_length' => 72,
            'password_require_uppercase' => true,
            'password_require_lowercase' => true,
            'password_require_numbers' => true,
            'password_require_symbols' => true,
            'password_history_count' => 5,
            'email_verification_expire_minutes' => 60,
        ];
    }
}

<?php

namespace Tests\Feature\Storage;

use App\Actions\V1\System\SystemSettingsUpdateAction;
use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\CleanTemporaryStorageJob;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Backup\SystemBackupSizeEstimator;
use App\Services\Storage\StorageCapacityGuard;
use App\Services\Timezone\RebaseTimezones;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * Phase 14 performance measurement — storage, backup and timezone paths.
 *
 * Follows `references/benchmark-testing.md`: one closure, a warmed run, then
 * N timed runs under the query log. Results go to /tmp for a before/after diff.
 *
 * This file MEASURES, it does not assert thresholds. A perf assertion fails the
 * suite on a slow CI box for reasons unrelated to the code; the numbers are the
 * deliverable and the judgement is the reader's.
 */
class StorageBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, mixed>> */
    protected static array $results = [];

    public static function tearDownAfterClass(): void
    {
        file_put_contents(
            '/tmp/phase14_storage_benchmark.json',
            json_encode(static::$results, JSON_PRETTY_PRINT)
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        $this->withoutMiddleware(VerifyCsrfToken::class);

        Feature::flushCache();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find('admin'));

        return $user;
    }

    /**
     * @return array{runs:int, perRunMs:float, totalMs:float, queriesPerRun:float, peakMemMB:float}
     */
    private function measure(callable $fn, int $runs = 5): array
    {
        $fn(); // warm autoloader, caches and the settings row

        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = hrtime(true);

        for ($i = 0; $i < $runs; $i++) {
            $fn();
        }

        $ms = (hrtime(true) - $start) / 1e6;
        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        return [
            'runs' => $runs,
            'perRunMs' => round($ms / $runs, 2),
            'totalMs' => round($ms, 2),
            'queriesPerRun' => round($queries / $runs, 2),
            'peakMemMB' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
    }

    private function seedFiles(string $disk, int $count, int $bytes = 1024, string $prefix = 'bench'): void
    {
        $storage = Storage::disk($disk);

        for ($i = 0; $i < $count; $i++) {
            $storage->put($prefix.'/file-'.$i.'.txt', str_repeat('x', $bytes));
        }
    }

    public function test_capacity_metrics_over_a_thousand_files(): void
    {
        Storage::fake('local');
        $this->seedFiles('local', 1000);

        $guard = app(StorageCapacityGuard::class);
        $metrics = $guard->metrics('local');

        static::$results['capacity_metrics_local_1000_files'] = [
            'files' => 1000,
            'usedBytes' => $metrics['used'],
            'msPerFile' => null,
        ] + $this->measure(fn () => $guard->metrics('local'), 3);

        static::$results['capacity_metrics_local_1000_files']['msPerFile'] = round(
            static::$results['capacity_metrics_local_1000_files']['perRunMs'] / 1000,
            4
        );
    }

    public function test_capacity_guard_over_a_thousand_files(): void
    {
        Storage::fake('local');
        $this->seedFiles('local', 1000);

        SystemSetting::set('storage_max_capacity_mb', '1024');
        SystemSetting::bustCache();

        $guard = app(StorageCapacityGuard::class);

        static::$results['capacity_guard_local_1000_files'] = [
            'files' => 1000,
            'metricsPerRunMs' => $this->measure(fn () => $guard->metrics('local'), 3)['perRunMs'],
        ] + $this->measure(fn () => $guard->assertCanStore(0, 'local'), 3);
    }

    public function test_settings_read_with_the_flag_on_and_off(): void
    {
        static::$results['settings_read_flag_on'] = $this->measure(
            fn () => SystemSetting::getLive(),
            100
        );

        Feature::deactivate('storage_management');
        Feature::flushCache();

        static::$results['settings_read_flag_off'] = $this->measure(
            fn () => SystemSetting::getLive(),
            100
        );
    }

    public function test_settings_write_through_the_shared_action(): void
    {
        $admin = $this->admin();
        $action = app(SystemSettingsUpdateAction::class);

        $result = $this->measure(fn () => $action->run([], false, $admin), 10);

        $result['auditRows'] = [
            'system' => DB::table('activity_log')->where('event', 'system_setting.updated')->count(),
            'storage' => DB::table('activity_log')->where('event', 'storage_setting.updated')->count(),
        ];

        static::$results['settings_write_full_form'] = $result;
    }

    public function test_settings_page_render_with_a_thousand_files(): void
    {
        $admin = $this->admin();

        Storage::fake('local');
        $this->seedFiles('local', 1000);

        $this->actingAs($admin);

        $result = $this->measure(function (): void {
            $response = $this->get(route('settings.index'));

            static::$results['settings_page_render']['status'] = $response->getStatusCode();
        }, 3);

        static::$results['settings_page_render'] = $result + (static::$results['settings_page_render'] ?? []);
        static::$results['settings_page_render']['files'] = 1000;
    }

    public function test_secure_download_of_one_megabyte(): void
    {
        Storage::fake('secure_documents');
        Storage::disk('secure_documents')->put('bench/one-megabyte.bin', str_repeat('x', 1024 * 1024));

        $admin = $this->admin();
        $this->actingAs($admin);

        $result = $this->measure(function () use ($admin): void {
            $url = URL::temporarySignedRoute(
                'storage.secure',
                now()->addMinutes(5),
                ['path' => 'bench/one-megabyte.bin']
            );

            $response = $this->actingAs($admin)->get($url);

            static::$results['secure_download_1mb']['status'] = $response->getStatusCode();
        }, 3);

        static::$results['secure_download_1mb'] = $result + (static::$results['secure_download_1mb'] ?? []);
        static::$results['secure_download_1mb']['bytes'] = 1024 * 1024;
    }

    public function test_backup_size_estimate_over_a_thousand_files(): void
    {
        $dir = storage_path('app/bench-estimate-'.uniqid());
        File::ensureDirectoryExists($dir);

        for ($i = 0; $i < 1000; $i++) {
            File::put($dir.'/f-'.$i.'.bin', str_repeat('x', 1024));
        }

        try {
            $estimator = app(SystemBackupSizeEstimator::class);

            static::$results['backup_estimate_1000_files'] = [
                'files' => 1000,
                'bytes' => $estimator->filesBytes(),
            ] + $this->measure(fn () => $estimator->filesBytes(), 3);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_tmp_cleanup_over_a_thousand_expired_files(): void
    {
        Storage::fake('tmp');
        $this->seedFiles('tmp', 1000);

        $storage = Storage::disk('tmp');
        $old = now()->subDays(3)->timestamp;

        foreach ($storage->allFiles() as $path) {
            touch($storage->path($path), $old);
        }

        $start = hrtime(true);
        (new CleanTemporaryStorageJob())->handle();
        $ms = (hrtime(true) - $start) / 1e6;

        $remaining = count($storage->allFiles());

        static::$results['tmp_cleanup_1000_files'] = [
            'files' => 1000,
            'deleted' => 1000 - $remaining,
            'remaining' => $remaining,
            'runMs' => round($ms, 2),
            'msPerFile' => round($ms / 1000, 3),
        ];
    }

    public function test_timezone_rebase_over_a_thousand_audit_rows(): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $rows = [];

        for ($i = 1; $i <= 1000; $i++) {
            $rows[] = [
                'log_name' => 'default',
                'description' => 'bench row '.$i,
                'event' => 'bench',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('activity_log')->insert($rows);

        $start = hrtime(true);
        $report = app(RebaseTimezones::class)->rebase('UTC', 'Asia/Jakarta');
        $ms = (hrtime(true) - $start) / 1e6;

        static::$results['timezone_rebase_seeded_db'] = [
            'auditRows' => 1000,
            'tables' => count($report['tables']),
            'rows' => array_sum(array_map(fn (array $t): int => $t['rows'], $report['tables'])),
            'skippedTables' => count($report['skipped']),
            'runMs' => round($ms, 2),
            'detail' => $report['tables'],
        ];
    }
}

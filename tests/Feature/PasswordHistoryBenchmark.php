<?php

namespace Tests\Feature;

use App\Actions\V1\Auth\ChangePasswordAction;
use App\Actions\V1\Auth\RecordPasswordHistoryAction;
use App\Actions\V1\User\CreateUserAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PasswordHistoryBenchmark extends TestCase
{
    use RefreshDatabase;

    protected static array $results = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);
    }

    protected function record(string $op, int $queries, float $latencyMs, float $memMB): void
    {
        static::$results[$op] = compact('queries', 'latencyMs', 'memMB');
    }

    public static function tearDownAfterClass(): void
    {
        file_put_contents('/tmp/password_history_benchmark.json', json_encode(static::$results, JSON_PRETTY_PRINT));
    }

    public function test_benchmark_password_change_with_history(): void
    {
        $user = User::factory()->create(['password' => bcrypt('CurrentP@ss1!')]);

        $action = app(ChangePasswordAction::class);

        DB::enableQueryLog();
        $start = microtime(true);
        $memBefore = memory_get_usage(true);

        $action->run($user, 'CurrentP@ss1!', 'NewSecureP@ss1!');

        $queries = count(DB::getQueryLog());
        $latencyMs = round((microtime(true) - $start) * 1000, 1);
        $memAfter = memory_get_usage(true);
        $memDeltaMB = round(($memAfter - $memBefore) / 1048576, 2);

        $this->record('password_change_with_history', $queries, $latencyMs, $memDeltaMB);

        $this->assertTrue(true); // Prevent risky test warning
    }

    public function test_benchmark_password_history_recording(): void
    {
        $user = User::factory()->create(['password' => bcrypt('OldP@ss1!')]);

        $action = app(RecordPasswordHistoryAction::class);

        DB::enableQueryLog();
        $start = microtime(true);
        $memBefore = memory_get_usage(true);

        $action->run($user, bcrypt('NewHashP@ss1!'));

        $queries = count(DB::getQueryLog());
        $latencyMs = round((microtime(true) - $start) * 1000, 1);
        $memAfter = memory_get_usage(true);
        $memDeltaMB = round(($memAfter - $memBefore) / 1048576, 2);

        $this->record('password_history_recording', $queries, $latencyMs, $memDeltaMB);

        $this->assertTrue(true);
    }

    public function test_benchmark_user_creation_with_history(): void
    {
        $action = app(CreateUserAction::class);

        DB::enableQueryLog();
        $start = microtime(true);
        $memBefore = memory_get_usage(true);

        $action->run([
            'name' => 'Benchmark User',
            'email' => 'bench' . uniqid() . '@example.com',
            'username' => 'benchuser' . uniqid(),
        ]);

        $queries = count(DB::getQueryLog());
        $latencyMs = round((microtime(true) - $start) * 1000, 1);
        $memAfter = memory_get_usage(true);
        $memDeltaMB = round(($memAfter - $memBefore) / 1048576, 2);

        $this->record('user_creation_with_history', $queries, $latencyMs, $memDeltaMB);

        $this->assertTrue(true);
    }

    public function test_benchmark_history_pruning(): void
    {
        $user = User::factory()->create(['password' => bcrypt('StartP@ss1!')]);

        // Insert 20 history entries (way beyond limit of 5)
        for ($i = 1; $i <= 20; $i++) {
            DB::table('password_histories')->insert([
                'user_id' => $user->id,
                'password' => bcrypt("OldP@ss{$i}!"),
                'created_at' => now()->subDays(25 - $i),
            ]);
        }

        $action = app(RecordPasswordHistoryAction::class);

        DB::enableQueryLog();
        $start = microtime(true);
        $memBefore = memory_get_usage(true);

        $action->run($user, bcrypt('NewHashP@ss1!'));

        $queries = count(DB::getQueryLog());
        $latencyMs = round((microtime(true) - $start) * 1000, 1);
        $memAfter = memory_get_usage(true);
        $memDeltaMB = round(($memAfter - $memBefore) / 1048576, 2);

        $this->record('history_pruning_20_entries', $queries, $latencyMs, $memDeltaMB);

        $this->assertTrue(true);
    }

    public function test_benchmark_password_reuse_check_with_many_history(): void
    {
        $user = User::factory()->create(['password' => bcrypt('CurrentP@ss1!')]);

        // Insert 24 history entries (max limit)
        for ($i = 1; $i <= 24; $i++) {
            DB::table('password_histories')->insert([
                'user_id' => $user->id,
                'password' => bcrypt("OldP@ss{$i}!"),
                'created_at' => now()->subDays(30 - $i),
            ]);
        }

        $action = app(ChangePasswordAction::class);

        DB::enableQueryLog();
        $start = microtime(true);
        $memBefore = memory_get_usage(true);

        $action->run($user, 'CurrentP@ss1!', 'BrandNewP@ss1!');

        $queries = count(DB::getQueryLog());
        $latencyMs = round((microtime(true) - $start) * 1000, 1);
        $memAfter = memory_get_usage(true);
        $memDeltaMB = round(($memAfter - $memBefore) / 1048576, 2);

        $this->record('reuse_check_with_24_history', $queries, $latencyMs, $memDeltaMB);

        $this->assertTrue(true);
    }

    public function test_benchmark_disabled_history_bypass(): void
    {
        $user = User::factory()->create(['password' => bcrypt('CurrentP@ss1!')]);

        // Insert history
        for ($i = 1; $i <= 10; $i++) {
            DB::table('password_histories')->insert([
                'user_id' => $user->id,
                'password' => bcrypt("OldP@ss{$i}!"),
                'created_at' => now()->subDays(15 - $i),
            ]);
        }

        // Disable history enforcement
        \App\Models\SystemSetting::set('password_history_enabled', 'false');
        \App\Models\SystemSetting::bustCache();

        $action = app(ChangePasswordAction::class);

        DB::enableQueryLog();
        $start = microtime(true);
        $memBefore = memory_get_usage(true);

        $action->run($user, 'CurrentP@ss1!', 'BrandNewP@ss1!');

        $queries = count(DB::getQueryLog());
        $latencyMs = round((microtime(true) - $start) * 1000, 1);
        $memAfter = memory_get_usage(true);
        $memDeltaMB = round(($memAfter - $memBefore) / 1048576, 2);

        $this->record('disabled_history_bypass', $queries, $latencyMs, $memDeltaMB);

        // Re-enable for other tests
        \App\Models\SystemSetting::set('password_history_enabled', 'true');

        $this->assertTrue(true);
    }

    public function test_benchmark_full_regression_suite(): void
    {
        // Run the full password-related test suite and measure time
        DB::enableQueryLog();
        $start = microtime(true);
        $memBefore = memory_get_usage(true);

        // 1. Create user with known password (not via CreateUserAction, since we don't know the temp)
        $user = User::factory()->create(['password' => bcrypt('TempP@ss1!')]);

        // 2. Change password 6 times (to test history rotation)
        $changeAction = app(ChangePasswordAction::class);
        $currentPassword = 'TempP@ss1!';
        for ($i = 1; $i <= 6; $i++) {
            $newPassword = "NewP@ss{$i}!" . str_repeat('x', 5);
            $user->refresh();
            $changeAction->run($user, $currentPassword, $newPassword);
            $currentPassword = $newPassword;
        }

        $queries = count(DB::getQueryLog());
        $latencyMs = round((microtime(true) - $start) * 1000, 1);
        $memAfter = memory_get_usage(true);
        $memDeltaMB = round(($memAfter - $memBefore) / 1048576, 2);

        $this->record('full_regression_6_changes', $queries, $latencyMs, $memDeltaMB);

        $this->assertTrue(true);
    }
}

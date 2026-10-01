<?php

namespace Tests\Benchmark;

use App\Actions\V1\User\UserActivateAction;
use App\Actions\V1\User\UserDeactivateAction;
use App\Actions\V1\User\UserDeleteAction;
use App\Actions\V1\User\UserLockAction;
use App\Actions\V1\User\UserRestoreAction;
use App\Actions\V1\User\UserUnlockAction;
use App\Actions\V1\User\UserIndexAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserManagementBenchmark extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    private static array $results = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'is_active' => true,
        ]);
    }

    private function measure(string $label, callable $fn): void
    {
        DB::enableQueryLog();
        $t0 = microtime(true);

        $fn();

        $latencyMs = round((microtime(true) - $t0) * 1000, 2);
        $queries = DB::getQueryLog();
        $queryCount = count($queries);
        $peakMb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        self::$results[$label] = [
            'queries' => $queryCount,
            'latency_ms' => $latencyMs,
            'peak_memory_mb' => $peakMb,
            'query_list' => array_column($queries, 'query'),
        ];
    }

    private function save(): void
    {
        file_put_contents(
            '/tmp/user_benchmark.json',
            json_encode(self::$results, JSON_PRETTY_PRINT)
        );
    }

    public function test_1_index_page(): void
    {
        User::factory()->count(25)->create();

        $indexAction = app(UserIndexAction::class);

        $this->measure('User Index Page Load', function () use ($indexAction) {
            $indexAction->run(search: null, status: 'active', sort: 'created_at', direction: 'desc', perPage: 10);
            $indexAction->counts();
        });
        $this->save();
    }

    public function test_2_deactivate_user(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $action = app(UserDeactivateAction::class);

        $this->measure('Deactivate User', function () use ($action, $user) {
            $action->run($user, $this->admin);
        });
        $this->save();
    }

    public function test_3_lock_user(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_locked' => false]);
        $action = app(UserLockAction::class);

        $this->measure('Lock User', function () use ($action, $user) {
            $action->run($user);
        });
        $this->save();
    }

    public function test_4_soft_delete_user(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $action = app(UserDeleteAction::class);

        $this->measure('Soft Delete User', function () use ($action, $user) {
            $action->run($user, $this->admin);
        });
        $this->save();
    }

    public function test_5_activate_user(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $action = app(UserActivateAction::class);

        $this->measure('Activate User', function () use ($action, $user) {
            $action->run($user);
        });
        $this->save();
    }

    public function test_6_unlock_user(): void
    {
        $user = User::factory()->create(['is_locked' => true]);
        $action = app(UserUnlockAction::class);

        $this->measure('Unlock User', function () use ($action, $user) {
            $action->run($user);
        });
        $this->save();
    }

    public function test_7_restore_user(): void
    {
        $user = User::factory()->create();
        $user->delete();
        $action = app(UserRestoreAction::class);

        $this->measure('Restore User', function () use ($action, $user) {
            $action->run($user);
        });
        $this->save();
    }
}
